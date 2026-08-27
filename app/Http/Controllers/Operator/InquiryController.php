<?php

namespace App\Http\Controllers\Operator;

use App\Application\Holds\CreateInquiryHoldAction;
use App\Application\Holds\HoldActor;
use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Application\Holds\ReleaseHoldAction;
use App\Application\InquiryAi\InquiryAiExtractor;
use App\Application\InquiryAi\InquiryExtractionException;
use App\Application\InquiryAi\InquirySuggestionResolver;
use App\Http\Controllers\Controller;
use App\Support\MinorUnitAmount;
use App\Support\OperatorUi;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\View\View;
use InvalidArgumentException;

final class InquiryController extends Controller
{
    private const CREATE_OPERATION = 'operator.inquiries.create';

    private const DOSSIER_UPDATE_OPERATION_PREFIX = 'operator.inquiries.dossier.update:';

    private const EXECUTION_UPDATE_OPERATION_PREFIX = 'operator.inquiries.execution.update:';

    private const CONTACT_METHODS = ['PHONE', 'WHATSAPP', 'WECHAT', 'LINE', 'EMAIL', 'OTHER'];

    private const DOSSIER_FIELDS = [
        'contact_name',
        'contact_method',
        'contact_value',
        'party_size',
        'adult_count',
        'child_count',
        'child_ages',
        'route_summary',
        'hotel_name',
        'room_number',
        'pickup_required',
        'pickup_time',
        'meeting_point',
        'service_location',
        'sales_source',
        'agent_reference',
        'service_notes',
        'internal_notes',
        'selling_currency',
        'selling_amount_minor',
    ];

    private const EXECUTION_FIELDS = [
        'service_date',
        'boat_id',
        'trip_template_id',
        'slot_offering_id',
    ];

    public function __construct(
        private readonly CreateInquiryHoldAction $createInquiryHold,
        private readonly ReleaseHoldAction $releaseHoldAction,
        private readonly OrganizationHoldTtlPolicy $ttlPolicy,
        private readonly InquiryAiExtractor $inquiryAiExtractor,
        private readonly InquirySuggestionResolver $inquirySuggestionResolver,
    ) {}

    public function index(Request $r): View
    {
        $o = $r->attributes->get('organization');
        $inquiries = DB::table('inquiries')->where('organization_id', $o->id)->latest('id')->get();

        return view('operator.inquiries.index', compact('inquiries') + ['organization' => $o]);
    }

    public function create(Request $r): View
    {
        $o = $r->attributes->get('organization');

        return view('operator.inquiries.create', ['organization' => $o, 'boats' => DB::table('boats')->where('organization_id', $o->id)->where('status', 'ACTIVE')->get(), 'products' => DB::table('trip_templates')->where('organization_id', $o->id)->where('status', 'ACTIVE')->get(), 'slots' => DB::table('slot_offerings')->where('organization_id', $o->id)->where('status', 'ACTIVE')->get(), 'idempotencyKey' => (string) Str::uuid()]);
    }

    public function store(Request $r): RedirectResponse
    {
        $i = $this->validateDossierInput($r, [
            'idempotency_key' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/'],
            'boat_id' => ['nullable', 'integer', 'min:1'],
            'trip_template_id' => ['nullable', 'integer', 'min:1'],
            'slot_offering_id' => ['nullable', 'integer', 'min:1'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $o = $r->attributes->get('organization');
        foreach (['boats' => 'boat_id', 'trip_templates' => 'trip_template_id', 'slot_offerings' => 'slot_offering_id'] as $table => $field) {
            if (isset($i[$field]) && ! DB::table($table)->where('organization_id', $o->id)->where('id', $i[$field])->exists()) {
                abort(404);
            }
        }
        $payload = [
            'reference' => $i['reference'],
            'boat_id' => isset($i['boat_id']) ? (int) $i['boat_id'] : null,
            'trip_template_id' => isset($i['trip_template_id']) ? (int) $i['trip_template_id'] : null,
            'slot_offering_id' => isset($i['slot_offering_id']) ? (int) $i['slot_offering_id'] : null,
            'service_date' => $i['service_date'] ?? null,
            'notes' => $i['notes'] ?? null,
            ...$this->dossierPayload($i),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $key = $i['idempotency_key'];
        $result = DB::transaction(function () use ($o, $payload, $hash, $key) {
            DB::table('organizations')->where('id', $o->id)->lockForUpdate()->first();
            $e = DB::table('idempotency_keys')->where('organization_id', $o->id)->where('operation', self::CREATE_OPERATION)->where('idempotency_key', $key)->first();
            if ($e) {
                if (! hash_equals($e->request_hash, $hash)) {
                    abort(409, '页面操作标识已被用于其他内容，请刷新页面后重试。');
                }

                return [
                    'status' => (int) $e->response_status,
                    'body' => json_decode($e->response_body, true, 512, JSON_THROW_ON_ERROR),
                ];
            }
            if (DB::table('inquiries')->where('organization_id', $o->id)->where('reference', $payload['reference'])->exists()) {
                throw ValidationException::withMessages([
                    'reference' => ['该询价参考号已被使用。'],
                ]);
            }
            $now = now();
            $id = DB::table('inquiries')->insertGetId(['organization_id' => $o->id, ...$payload, 'status' => 'INQUIRY', 'created_by_user_id' => Auth::id(), 'created_at' => $now, 'updated_at' => $now]);
            $body = ['inquiry_id' => $id];
            $auditValues = [
                'id' => $id,
                'status' => 'INQUIRY',
                'reference' => $payload['reference'],
                'boat_id' => $payload['boat_id'],
                'trip_template_id' => $payload['trip_template_id'],
                'slot_offering_id' => $payload['slot_offering_id'],
                'service_date' => $payload['service_date'],
                'notes_present' => $this->isPresent($payload['notes']),
                ...$this->dossierAuditMetadata($payload),
            ];
            DB::table('audit_logs')->insert(['organization_id' => $o->id, 'actor_type' => 'operator_user', 'actor_id' => Auth::id(), 'action' => 'INQUIRY_CREATED', 'object_type' => 'inquiry', 'object_id' => $id, 'before_values' => null, 'after_values' => json_encode($auditValues, JSON_THROW_ON_ERROR), 'reason' => null, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('idempotency_keys')->insert(['organization_id' => $o->id, 'operation' => self::CREATE_OPERATION, 'idempotency_key' => $key, 'request_hash' => $hash, 'response_status' => 303, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);

            return ['status' => 303, 'body' => $body];
        }, 3);

        return redirect()->route('operator.inquiries.show', $result['body']['inquiry_id'], $result['status'])
            ->with('status', '询价已创建。');
    }

    /**
     * #51C: server-side AI suggestion-only parse for the Inquiry create form.
     *
     * Operator paste -> this endpoint -> safe suggestion payload -> the browser
     * fills only EMPTY form fields -> operator reviews -> existing Create
     * Inquiry action. Guarantees:
     * - browser never calls the provider directly; credentials stay server-side;
     * - read-only: no Inquiry/Booking/Trip/audit/idempotency write and no
     *   inventory/state mutation happens here;
     * - provider failure / timeout / 429 / malformed output / disabled AI all
     *   return a clear manual-entry fallback (ok=false) without touching the form;
     * - the raw pasted text is never logged and never reflected in the response.
     */
    public function aiSuggest(Request $r): JsonResponse
    {
        // This endpoint is consumed by the operator page via fetch(); it stays
        // JSON for every outcome. Web-route exception rendering redirects by
        // project contract (see bootstrap/app.php), so validation failures are
        // converted into the same JSON shape here instead of relying on the
        // global exception renderer.
        try {
            $input = $r->validate([
                'raw_text' => ['required', 'string', 'max:10000'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'code' => 'VALIDATION_FAILED',
                'message' => '请先在订单原文框粘贴 1–10000 字符的文字。未修改任何字段，请直接手工填写表单并点击「创建询价」。',
            ]);
        }

        $organization = $r->attributes->get('organization');

        try {
            $extracted = $this->inquiryAiExtractor->extract($input['raw_text']);
            $suggestion = $this->inquirySuggestionResolver->resolveForOrganization(
                $extracted,
                (int) $organization->id,
            );
        } catch (InquiryExtractionException $e) {
            return response()->json([
                'ok' => false,
                'code' => $e->kind,
                'message' => 'AI 智能识别暂不可用（'.$e->kind.'）。未修改任何字段，请直接手工填写表单并点击「创建询价」。',
            ]);
        }

        return response()->json([
            'ok' => true,
            'suggestion' => $suggestion->toArray(),
            'message' => 'AI 识别完成，结果仅为建议：仅空字段会被填充，不自动提交；请操作员核对后点击「创建询价」。',
        ]);
    }

    public function show(Request $r, int $inquiry): View
    {
        $organization = $r->attributes->get('organization');
        $record = $this->scopedInquiry((int) $organization->id, $inquiry);
        $hold = $record->hold_id === null ? null : DB::table('holds')
            ->where('organization_id', $organization->id)
            ->where('id', $record->hold_id)
            ->first();

        $booking = $hold === null ? null : DB::table('bookings')
            ->where('organization_id', $organization->id)
            ->where('hold_id', $hold->id)
            ->first();
        $trip = $booking === null ? null : DB::table('trips')
            ->where('organization_id', $organization->id)
            ->where('booking_id', $booking->id)
            ->first();
        $selectedBoat = $record->boat_id === null ? null : DB::table('boats')
            ->where('organization_id', $organization->id)
            ->where('id', $record->boat_id)
            ->first();
        $selectedProduct = $record->trip_template_id === null ? null : DB::table('trip_templates')
            ->where('organization_id', $organization->id)
            ->where('id', $record->trip_template_id)
            ->first();
        $selectedSlot = $record->slot_offering_id === null ? null : DB::table('slot_offerings')
            ->where('organization_id', $organization->id)
            ->where('id', $record->slot_offering_id)
            ->first();

        return view('operator.inquiries.show', [
            'organization' => $organization,
            'inquiry' => $record,
            'hold' => $hold,
            'booking' => $booking,
            'trip' => $trip,
            'selectedBoat' => $selectedBoat,
            'selectedProduct' => $selectedProduct,
            'selectedSlot' => $selectedSlot,
            'childAgesText' => $this->childAgesText($record->child_ages),
            'sellingAmountDecimal' => $record->selling_amount_minor === null
                ? null
                : MinorUnitAmount::toDecimal((int) $record->selling_amount_minor, (string) ($record->selling_currency ?: MinorUnitAmount::defaultCurrency())),
            'missingInformation' => $this->missingInformation($record),
            'boats' => DB::table('boats')->where('organization_id', $organization->id)->where('status', 'ACTIVE')->orderBy('name')->get(),
            'products' => DB::table('trip_templates')->where('organization_id', $organization->id)->where('status', 'ACTIVE')->orderBy('name')->get(),
            'slots' => DB::table('slot_offerings')->where('organization_id', $organization->id)->where('status', 'ACTIVE')->whereIn('kind', ['PRESET', 'CUSTOM_TEMPLATE'])->orderBy('name')->get(),
            'holdTtlConfigured' => $this->ttlPolicy->minutes((int) $organization->id) !== null,
            'holdIdempotencyKey' => (string) Str::uuid(),
            'releaseIdempotencyKey' => (string) Str::uuid(),
            'confirmIdempotencyKey' => (string) Str::uuid(),
            'amendIdempotencyKey' => (string) Str::uuid(),
            'cancelIdempotencyKey' => (string) Str::uuid(),
            'dossierIdempotencyKey' => (string) Str::uuid(),
            'executionIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function updateDossier(Request $r, int $inquiry): RedirectResponse
    {
        $organization = $r->attributes->get('organization');
        $this->scopedInquiry((int) $organization->id, $inquiry);
        $this->mergeHeaderIdempotencyKey($r);
        $input = $this->validateDossierInput($r, [
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $payload = $this->dossierPayload($input);
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $operation = self::DOSSIER_UPDATE_OPERATION_PREFIX.$inquiry;
        $idempotencyKey = $input['idempotency_key'];

        $result = DB::transaction(function () use ($organization, $inquiry, $payload, $requestHash, $operation, $idempotencyKey): array {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $existing = DB::table('idempotency_keys')
                ->where('organization_id', $organization->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    abort(409, '页面操作标识已被用于其他内容，请刷新页面后重试。');
                }

                return [
                    'status' => (int) $existing->response_status,
                    'body' => json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR),
                ];
            }

            $record = DB::table('inquiries')
                ->where('organization_id', $organization->id)
                ->where('id', $inquiry)
                ->lockForUpdate()
                ->first();
            abort_if(! $record, 404);

            $before = $this->storedDossierPayload((array) $record);
            $changedFields = array_values(array_filter(
                self::DOSSIER_FIELDS,
                static fn (string $field): bool => $before[$field] !== $payload[$field],
            ));
            $now = now();
            if ($changedFields !== []) {
                DB::table('inquiries')
                    ->where('organization_id', $organization->id)
                    ->where('id', $inquiry)
                    ->update([...$payload, 'updated_at' => $now]);
                DB::table('audit_logs')->insert([
                    'organization_id' => $organization->id,
                    'actor_type' => 'operator_user',
                    'actor_id' => Auth::id(),
                    'action' => 'INQUIRY_DOSSIER_UPDATED',
                    'object_type' => 'inquiry',
                    'object_id' => $inquiry,
                    'before_values' => json_encode($this->dossierAuditMetadata($before), JSON_THROW_ON_ERROR),
                    'after_values' => json_encode([
                        ...$this->dossierAuditMetadata($payload),
                        'changed_fields' => $changedFields,
                    ], JSON_THROW_ON_ERROR),
                    'reason' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $body = ['inquiry_id' => $inquiry, 'changed_fields' => $changedFields];
            DB::table('idempotency_keys')->insert([
                'organization_id' => $organization->id,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'response_status' => 303,
                'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['status' => 303, 'body' => $body];
        }, 3);

        return redirect()->route('operator.inquiries.show', $result['body']['inquiry_id'], $result['status'])
            ->with('status', '运营资料已更新。');
    }

    public function updateExecution(Request $r, int $inquiry): RedirectResponse
    {
        $organization = $r->attributes->get('organization');
        $this->scopedInquiry((int) $organization->id, $inquiry);
        $this->mergeHeaderIdempotencyKey($r);
        $input = $r->validate([
            'idempotency_key' => ['required', 'uuid'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'boat_id' => ['nullable', 'integer', 'min:1'],
            'trip_template_id' => ['nullable', 'integer', 'min:1'],
            'slot_offering_id' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach (['boats' => 'boat_id', 'trip_templates' => 'trip_template_id', 'slot_offerings' => 'slot_offering_id'] as $table => $field) {
            if (isset($input[$field]) && ! DB::table($table)->where('organization_id', $organization->id)->where('id', $input[$field])->exists()) {
                abort(404);
            }
        }

        $payload = [
            'service_date' => $input['service_date'] ?? null,
            'boat_id' => isset($input['boat_id']) ? (int) $input['boat_id'] : null,
            'trip_template_id' => isset($input['trip_template_id']) ? (int) $input['trip_template_id'] : null,
            'slot_offering_id' => isset($input['slot_offering_id']) ? (int) $input['slot_offering_id'] : null,
        ];
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $operation = self::EXECUTION_UPDATE_OPERATION_PREFIX.$inquiry;
        $idempotencyKey = $input['idempotency_key'];

        $result = DB::transaction(function () use ($organization, $inquiry, $payload, $requestHash, $operation, $idempotencyKey): array {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $existing = DB::table('idempotency_keys')
                ->where('organization_id', $organization->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    abort(409, 'Idempotency key was already used for different inquiry execution data.');
                }

                return [
                    'status' => (int) $existing->response_status,
                    'body' => json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR),
                ];
            }

            $record = DB::table('inquiries')
                ->where('organization_id', $organization->id)
                ->where('id', $inquiry)
                ->lockForUpdate()
                ->first();
            abort_if(! $record, 404);
            if ($record->hold_id !== null || $record->status !== 'INQUIRY') {
                abort(409, 'Execution fields are immutable after a HOLD is linked.');
            }

            $before = $this->storedExecutionPayload((array) $record);
            $changedFields = array_values(array_filter(
                self::EXECUTION_FIELDS,
                static fn (string $field): bool => $before[$field] !== $payload[$field],
            ));
            $now = now();
            if ($changedFields !== []) {
                DB::table('inquiries')
                    ->where('organization_id', $organization->id)
                    ->where('id', $inquiry)
                    ->update([...$payload, 'updated_at' => $now]);
                DB::table('audit_logs')->insert([
                    'organization_id' => $organization->id,
                    'actor_type' => 'operator_user',
                    'actor_id' => Auth::id(),
                    'action' => 'INQUIRY_EXECUTION_UPDATED',
                    'object_type' => 'inquiry',
                    'object_id' => $inquiry,
                    'before_values' => json_encode($this->executionAuditMetadata($before), JSON_THROW_ON_ERROR),
                    'after_values' => json_encode([
                        ...$this->executionAuditMetadata($payload),
                        'changed_fields' => $changedFields,
                    ], JSON_THROW_ON_ERROR),
                    'reason' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $body = ['inquiry_id' => $inquiry, 'changed_fields' => $changedFields];
            DB::table('idempotency_keys')->insert([
                'organization_id' => $organization->id,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'response_status' => 303,
                'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['status' => 303, 'body' => $body];
        }, 3);

        return redirect()->route('operator.inquiries.show', $result['body']['inquiry_id'], $result['status'])
            ->with('status', '出航资料已更新。');
    }

    public function createHold(Request $r, int $inquiry): RedirectResponse
    {
        $input = $r->validate(['idempotency_key' => ['required', 'uuid']]);
        $organization = $r->attributes->get('organization');
        $this->scopedInquiry((int) $organization->id, $inquiry);
        $result = $this->createInquiryHold->execute(
            (int) $organization->id,
            $inquiry,
            $input['idempotency_key'],
            HoldActor::operatorUser((int) Auth::id()),
        );

        if ($result->status === 201) {
            return redirect()->route('operator.inquiries.show', $inquiry, 303)
                ->with('status', '预留已创建。');
        }

        return redirect()->route('operator.inquiries.show', $inquiry, 303)
            ->withErrors(['hold' => OperatorUi::actionError($result->payload)]);
    }

    public function releaseHold(Request $r, int $inquiry): RedirectResponse
    {
        $input = $r->validate(['idempotency_key' => ['required', 'uuid']]);
        $organization = $r->attributes->get('organization');
        $record = $this->scopedInquiry((int) $organization->id, $inquiry);

        if ($record->hold_id === null) {
            return redirect()->route('operator.inquiries.show', $inquiry, 303)
                ->withErrors(['hold' => '该询价未关联预留。']);
        }

        $hold = DB::table('holds')
            ->where('organization_id', $organization->id)
            ->where('id', $record->hold_id)
            ->first();
        abort_if(! $hold, 404);
        $result = $this->releaseHoldAction->execute(
            (int) $organization->id,
            (int) $hold->id,
            ['external_reference' => (string) $hold->external_reference],
            $input['idempotency_key'],
            HoldActor::operatorUser((int) Auth::id()),
        );

        if ($result->status === 200) {
            return redirect()->route('operator.inquiries.show', $inquiry, 303)
                ->with('status', '预留已释放。');
        }

        return redirect()->route('operator.inquiries.show', $inquiry, 303)
            ->withErrors(['hold' => OperatorUi::actionError($result->payload)]);
    }

    private function scopedInquiry(int $organizationId, int $inquiry): object
    {
        $record = DB::table('inquiries')
            ->where('organization_id', $organizationId)
            ->where('id', $inquiry)
            ->first();
        abort_if(! $record, 404);

        return $record;
    }

    /** @return array<string, array<int, mixed>> */
    private function dossierRules(Request $request): array
    {
        return [
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_method' => ['nullable', 'string', 'max:32', Rule::in(self::CONTACT_METHODS), 'required_with:contact_value'],
            'contact_value' => ['nullable', 'string', 'max:255', 'required_with:contact_method'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:999'],
            'adult_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'child_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'child_ages' => ['nullable', 'array', 'max:999'],
            'child_ages.*' => ['integer', 'min:0'],
            'hotel_name' => ['nullable', 'string', 'max:255'],
            'room_number' => ['nullable', 'string', 'max:255'],
            'pickup_required' => ['nullable', 'boolean'],
            'pickup_time' => ['nullable', 'date_format:H:i'],
            'route_summary' => ['nullable', 'string', 'max:2000'],
            'meeting_point' => ['nullable', 'string', 'max:2000'],
            'service_location' => ['nullable', 'string', 'max:2000'],
            'sales_source' => ['nullable', 'string', 'max:255'],
            'agent_reference' => ['nullable', 'string', 'max:255'],
            'service_notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'selling_currency' => ['nullable', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/', Rule::in(MinorUnitAmount::supportedCurrencies()), 'required_with:selling_amount'],
            'selling_amount' => [
                'nullable',
                'required_with:selling_currency',
                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    if (! is_string($value) && ! is_int($value)) {
                        $fail('销售金额必须是最多两位小数的非负金额。');

                        return;
                    }

                    try {
                        MinorUnitAmount::fromDecimal((string) $value, (string) $request->input('selling_currency'));
                    } catch (InvalidArgumentException) {
                        $fail('销售金额必须是最多两位小数且不超过系统整数范围的非负金额。');
                    }
                },
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function dossierPayload(array $input): array
    {
        return [
            'contact_name' => $input['contact_name'] ?? null,
            'contact_method' => $input['contact_method'] ?? null,
            'contact_value' => $input['contact_value'] ?? null,
            'party_size' => isset($input['party_size']) ? (int) $input['party_size'] : null,
            'adult_count' => isset($input['adult_count']) ? (int) $input['adult_count'] : null,
            'child_count' => isset($input['child_count']) ? (int) $input['child_count'] : null,
            'child_ages' => isset($input['child_ages']) ? $this->childAgesJson($input['child_ages']) : null,
            'hotel_name' => $input['hotel_name'] ?? null,
            'room_number' => $input['room_number'] ?? null,
            'pickup_required' => isset($input['pickup_required']) ? (bool) $input['pickup_required'] : null,
            'pickup_time' => isset($input['pickup_time']) ? $input['pickup_time'].':00' : null,
            'route_summary' => $input['route_summary'] ?? null,
            'meeting_point' => $input['meeting_point'] ?? null,
            'service_location' => $input['service_location'] ?? null,
            'sales_source' => $input['sales_source'] ?? null,
            'agent_reference' => $input['agent_reference'] ?? null,
            'service_notes' => $input['service_notes'] ?? null,
            'internal_notes' => $input['internal_notes'] ?? null,
            'selling_currency' => $input['selling_currency'] ?? null,
            'selling_amount_minor' => isset($input['selling_amount'])
                ? MinorUnitAmount::fromDecimal((string) $input['selling_amount'], (string) $input['selling_currency'])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dossierAuditMetadata(array $payload): array
    {
        return [
            'contact_name_present' => $this->isPresent($payload['contact_name']),
            'contact_method' => $payload['contact_method'],
            'contact_value_present' => $this->isPresent($payload['contact_value']),
            'party_size' => $payload['party_size'],
            'adult_count' => $payload['adult_count'],
            'child_count' => $payload['child_count'],
            'child_ages_count' => $this->childAgesCount($payload['child_ages']),
            'hotel_name_present' => $this->isPresent($payload['hotel_name']),
            'room_number_present' => $this->isPresent($payload['room_number']),
            'pickup_required' => $payload['pickup_required'],
            'pickup_time_present' => $this->isPresent($payload['pickup_time']),
            'route_summary_present' => $this->isPresent($payload['route_summary']),
            'meeting_point_present' => $this->isPresent($payload['meeting_point']),
            'service_location_present' => $this->isPresent($payload['service_location']),
            'sales_source' => $payload['sales_source'],
            'agent_reference' => $payload['agent_reference'],
            'service_notes_present' => $this->isPresent($payload['service_notes']),
            'internal_notes_present' => $this->isPresent($payload['internal_notes']),
            'selling_currency' => $payload['selling_currency'],
            'selling_amount_minor' => $payload['selling_amount_minor'],
        ];
    }

    /**
     * @param  array<string, array<int, mixed>>  $additionalRules
     * @return array<string, mixed>
     */
    private function validateDossierInput(Request $request, array $additionalRules): array
    {
        $this->normalizeChildAges($request);
        $validator = ValidatorFacade::make($request->all(), [
            ...$additionalRules,
            ...$this->dossierRules($request),
        ]);
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();
            $errors = $validator->errors();

            if (! $errors->has('party_size') && ! $errors->has('adult_count') && ! $errors->has('child_count')
                && isset($data['party_size'], $data['adult_count'], $data['child_count'])
                && (int) $data['adult_count'] + (int) $data['child_count'] !== (int) $data['party_size']) {
                $errors->add('party_size', '总人数必须等于成人数和儿童数之和。');
            }

            if (! $errors->has('child_count') && ! $errors->has('child_ages') && ! $errors->has('child_ages.*')
                && isset($data['child_count'], $data['child_ages']) && is_array($data['child_ages'])
                && count($data['child_ages']) > (int) $data['child_count']) {
                $errors->add('child_ages', '已填写的儿童年龄数量不能超过儿童数。');
            }
        });

        return $validator->validate();
    }

    private function normalizeChildAges(Request $request): void
    {
        if (! $request->exists('child_ages')) {
            return;
        }

        $value = $request->input('child_ages');
        if ($value === null || $value === '') {
            $request->merge(['child_ages' => null]);

            return;
        }
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/u', $value) ?: [];
        }
        if (! is_array($value)) {
            return;
        }

        $ages = [];
        foreach ($value as $age) {
            $age = is_string($age) ? trim($age) : $age;
            if ($age !== '') {
                $ages[] = $age;
            }
        }

        $request->merge(['child_ages' => $ages === [] ? null : $ages]);
    }

    /** @param array<string, mixed> $record */
    private function storedExecutionPayload(array $record): array
    {
        return [
            'service_date' => $record['service_date'],
            'boat_id' => $record['boat_id'] === null ? null : (int) $record['boat_id'],
            'trip_template_id' => $record['trip_template_id'] === null ? null : (int) $record['trip_template_id'],
            'slot_offering_id' => $record['slot_offering_id'] === null ? null : (int) $record['slot_offering_id'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function executionAuditMetadata(array $payload): array
    {
        return [
            'service_date' => $payload['service_date'],
            'boat_id' => $payload['boat_id'],
            'trip_template_id' => $payload['trip_template_id'],
            'slot_offering_id' => $payload['slot_offering_id'],
        ];
    }

    /** @param array<string, mixed> $record */
    private function storedDossierPayload(array $record): array
    {
        return [
            'contact_name' => $record['contact_name'],
            'contact_method' => $record['contact_method'],
            'contact_value' => $record['contact_value'],
            'party_size' => $record['party_size'] === null ? null : (int) $record['party_size'],
            'adult_count' => $record['adult_count'] === null ? null : (int) $record['adult_count'],
            'child_count' => $record['child_count'] === null ? null : (int) $record['child_count'],
            'child_ages' => $record['child_ages'] === null ? null : $this->childAgesJson($record['child_ages']),
            'hotel_name' => $record['hotel_name'],
            'room_number' => $record['room_number'],
            'pickup_required' => $record['pickup_required'] === null ? null : (bool) $record['pickup_required'],
            'pickup_time' => $record['pickup_time'] === null ? null : $this->canonicalPickupTime((string) $record['pickup_time']),
            'route_summary' => $record['route_summary'],
            'meeting_point' => $record['meeting_point'],
            'service_location' => $record['service_location'],
            'sales_source' => $record['sales_source'],
            'agent_reference' => $record['agent_reference'],
            'service_notes' => $record['service_notes'],
            'internal_notes' => $record['internal_notes'],
            'selling_currency' => $record['selling_currency'],
            'selling_amount_minor' => $record['selling_amount_minor'] === null ? null : (int) $record['selling_amount_minor'],
        ];
    }

    /** @param array<int, mixed>|string $ages */
    private function childAgesJson(array|string $ages): string
    {
        $decoded = is_string($ages) ? json_decode($ages, true, 512, JSON_THROW_ON_ERROR) : $ages;

        return json_encode(array_map(static fn (mixed $age): int => (int) $age, $decoded), JSON_THROW_ON_ERROR);
    }

    private function childAgesText(?string $ages): string
    {
        if ($ages === null || $ages === '') {
            return '';
        }

        return implode("\n", json_decode($ages, true, 512, JSON_THROW_ON_ERROR));
    }

    private function childAgesCount(?string $ages): int
    {
        return $ages === null ? 0 : count(json_decode($ages, true, 512, JSON_THROW_ON_ERROR));
    }

    private function canonicalPickupTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    /** @return list<string> */
    private function missingInformation(object $inquiry): array
    {
        $missing = [];
        foreach ([
            'service_date' => '服务日期',
            'boat_id' => '船只',
            'trip_template_id' => '产品 / 出航模板',
            'slot_offering_id' => '服务时段',
            'route_summary' => '路线 / 目的地',
            'contact_name' => '客人 / 联系人姓名',
            'party_size' => '总人数',
        ] as $field => $label) {
            if (! $this->isPresent($inquiry->{$field})) {
                $missing[] = $label;
            }
        }
        if (! $this->isPresent($inquiry->contact_method) || ! $this->isPresent($inquiry->contact_value)) {
            $missing[] = '联系方式与联系信息';
        }
        if ($inquiry->adult_count === null || $inquiry->child_count === null) {
            $missing[] = '成人 / 儿童人数拆分';
        }
        if ($inquiry->pickup_required === null) {
            $missing[] = '是否需要接送';
        } elseif ((bool) $inquiry->pickup_required) {
            foreach ([
                'hotel_name' => '酒店 / 住宿名称',
                'meeting_point' => '接客 / 集合地点',
                'pickup_time' => '接客时间',
            ] as $field => $label) {
                if (! $this->isPresent($inquiry->{$field})) {
                    $missing[] = $label;
                }
            }
        }

        return $missing;
    }

    private function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function mergeHeaderIdempotencyKey(Request $request): void
    {
        $header = $request->header('Idempotency-Key');
        if (! $request->filled('idempotency_key') && is_string($header) && $header !== '') {
            $request->merge(['idempotency_key' => $header]);
        }
    }
}
