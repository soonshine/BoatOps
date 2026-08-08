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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class InquiryController extends Controller
{
    private const OP = 'operator.inquiries.create';

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
        $i = $r->validate(['idempotency_key' => ['required', 'uuid'], 'reference' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/'], 'boat_id' => ['nullable', 'integer', 'min:1'], 'trip_template_id' => ['nullable', 'integer', 'min:1'], 'slot_offering_id' => ['nullable', 'integer', 'min:1'], 'service_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $o = $r->attributes->get('organization');
        foreach (['boats' => 'boat_id', 'trip_templates' => 'trip_template_id', 'slot_offerings' => 'slot_offering_id'] as $table => $field) {
            if (isset($i[$field]) && ! DB::table($table)->where('organization_id', $o->id)->where('id', $i[$field])->exists()) {
                abort(404);
            }
        }$payload = ['reference' => $i['reference'], 'boat_id' => isset($i['boat_id']) ? (int) $i['boat_id'] : null, 'trip_template_id' => isset($i['trip_template_id']) ? (int) $i['trip_template_id'] : null, 'slot_offering_id' => isset($i['slot_offering_id']) ? (int) $i['slot_offering_id'] : null, 'service_date' => $i['service_date'] ?? null, 'notes' => $i['notes'] ?? null];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $key = $i['idempotency_key'];
        $result = DB::transaction(function () use ($o, $payload, $hash, $key) {
            DB::table('organizations')->where('id', $o->id)->lockForUpdate()->first();
            $e = DB::table('idempotency_keys')->where('organization_id', $o->id)->where('operation', self::OP)->where('idempotency_key', $key)->first();
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
            DB::table('audit_logs')->insert(['organization_id' => $o->id, 'actor_type' => 'operator_user', 'actor_id' => Auth::id(), 'action' => 'INQUIRY_CREATED', 'object_type' => 'inquiry', 'object_id' => $id, 'before_values' => null, 'after_values' => json_encode(['id' => $id, 'status' => 'INQUIRY', ...$payload], JSON_THROW_ON_ERROR), 'reason' => null, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('idempotency_keys')->insert(['organization_id' => $o->id, 'operation' => self::OP, 'idempotency_key' => $key, 'request_hash' => $hash, 'response_status' => 303, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);

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
        ]);
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
}
