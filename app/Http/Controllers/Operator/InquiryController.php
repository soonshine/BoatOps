<?php

namespace App\Http\Controllers\Operator;

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
        $o = $r->attributes->get('organization');
        $record = DB::table('inquiries')->where('organization_id', $o->id)->where('id', $inquiry)->first();
        abort_if(! $record, 404);

        return view('operator.inquiries.show', ['organization' => $o, 'inquiry' => $record]);
    }
}
