<?php

namespace App\Http\Controllers\Operator;

use App\Application\Holds\CreateInquiryHoldAction;
use App\Application\Holds\HoldActor;
use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Application\Holds\ReleaseHoldAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class InquiryController extends Controller
{
    private const CREATE_OPERATION = 'operator.inquiries.create';

    private const DOSSIER_UPDATE_OPERATION_PREFIX = 'operator.inquiries.dossier.update:';

    private const CONTACT_METHODS = ['PHONE', 'WHATSAPP', 'WECHAT', 'LINE', 'EMAIL', 'OTHER'];

    private const DOSSIER_FIELDS = [
        'contact_name',
        'contact_method',
        'contact_value',
        'party_size',
        'meeting_point',
        'service_location',
        'sales_source',
        'agent_reference',
        'service_notes',
        'internal_notes',
        'selling_currency',
        'selling_amount_minor',
    ];

    public function __construct(
        private readonly CreateInquiryHoldAction $createInquiryHold,
        private readonly ReleaseHoldAction $releaseHoldAction,
        private readonly OrganizationHoldTtlPolicy $ttlPolicy,
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
        $i = $r->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/'],
            'boat_id' => ['nullable', 'integer', 'min:1'],
            'trip_template_id' => ['nullable', 'integer', 'min:1'],
            'slot_offering_id' => ['nullable', 'integer', 'min:1'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->dossierRules(),
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
                    abort(409, 'The idempotency key was used with another payload.');
                }

                return [
                    'status' => (int) $e->response_status,
                    'body' => json_decode($e->response_body, true, 512, JSON_THROW_ON_ERROR),
                ];
            }
            if (DB::table('inquiries')->where('organization_id', $o->id)->where('reference', $payload['reference'])->exists()) {
                throw ValidationException::withMessages([
                    'reference' => ['This neutral reference is already in use.'],
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

        return redirect()->route('operator.inquiries.show', $result['body']['inquiry_id'], $result['status']);
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

        return view('operator.inquiries.show', [
            'organization' => $organization,
            'inquiry' => $record,
            'hold' => $hold,
            'booking' => $booking,
            'trip' => $trip,
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
        ]);
    }

    public function updateDossier(Request $r, int $inquiry): RedirectResponse
    {
        $organization = $r->attributes->get('organization');
        $this->scopedInquiry((int) $organization->id, $inquiry);
        $this->mergeHeaderIdempotencyKey($r);
        $input = $r->validate([
            'idempotency_key' => ['required', 'uuid'],
            ...$this->dossierRules(),
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
                    abort(409, 'The idempotency key was used with another payload.');
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

            $before = $this->dossierPayload((array) $record);
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
            ->with('status', 'Operational dossier updated.');
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
            return redirect()->route('operator.inquiries.show', $inquiry, 303);
        }

        return redirect()->route('operator.inquiries.show', $inquiry, 303)
            ->withErrors(['hold' => $result->payload['message']]);
    }

    public function releaseHold(Request $r, int $inquiry): RedirectResponse
    {
        $input = $r->validate(['idempotency_key' => ['required', 'uuid']]);
        $organization = $r->attributes->get('organization');
        $record = $this->scopedInquiry((int) $organization->id, $inquiry);

        if ($record->hold_id === null) {
            return redirect()->route('operator.inquiries.show', $inquiry, 303)
                ->withErrors(['hold' => 'This inquiry has no linked HOLD.']);
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
            return redirect()->route('operator.inquiries.show', $inquiry, 303);
        }

        return redirect()->route('operator.inquiries.show', $inquiry, 303)
            ->withErrors(['hold' => $result->payload['message']]);
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
    private function dossierRules(): array
    {
        return [
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_method' => ['nullable', 'string', 'max:32', Rule::in(self::CONTACT_METHODS), 'required_with:contact_value'],
            'contact_value' => ['nullable', 'string', 'max:255', 'required_with:contact_method'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:999'],
            'meeting_point' => ['nullable', 'string', 'max:2000'],
            'service_location' => ['nullable', 'string', 'max:2000'],
            'sales_source' => ['nullable', 'string', 'max:255'],
            'agent_reference' => ['nullable', 'string', 'max:255'],
            'service_notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'selling_currency' => ['nullable', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/', 'required_with:selling_amount_minor'],
            'selling_amount_minor' => ['nullable', 'integer', 'min:0', 'max:'.PHP_INT_MAX, 'required_with:selling_currency'],
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
            'meeting_point' => $input['meeting_point'] ?? null,
            'service_location' => $input['service_location'] ?? null,
            'sales_source' => $input['sales_source'] ?? null,
            'agent_reference' => $input['agent_reference'] ?? null,
            'service_notes' => $input['service_notes'] ?? null,
            'internal_notes' => $input['internal_notes'] ?? null,
            'selling_currency' => $input['selling_currency'] ?? null,
            'selling_amount_minor' => isset($input['selling_amount_minor']) ? (int) $input['selling_amount_minor'] : null,
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
