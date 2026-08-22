<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorInquiryQuickPasteTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_exposes_quick_paste_without_changing_submission_contract(): void
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Quick Paste Operator',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'name' => 'Fictional Operator',
            'email' => Str::random(8).'@example.test',
            'password' => Hash::make('fictional-password'),
        ]);
        DB::table('operator_memberships')->insert([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'can_calendar_read' => false,
            'can_booking_workflow' => true,
            'can_block' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);
        $this->get('/operator/inquiries/create')->assertOk()
            ->assertSee('快速粘贴')
            ->assertSee('一键粘贴并识别')
            ->assertSee('识别并填充')
            ->assertSee('id="quick-paste-input"', false)
            ->assertSee('id="quick-paste-clipboard"', false)
            ->assertSee('navigator.clipboard?.readText', false)
            ->assertSee("setField('service_date'", false)
            ->assertSee("setField('selling_amount'", false)
            ->assertSee("setField('notes'", false)
            ->assertSee('不会自动提交，也不会覆盖你已经填写的字段。');

        $this->post('/operator/inquiries', [
            'idempotency_key' => (string) Str::uuid(),
            'reference' => 'FICTIONAL-QUICK-PASTE-001',
            'service_date' => '2026-08-22',
            'service_notes' => '4小时',
            'notes' => "原始粘贴：\n2026-08-22 4小时收入 14450 THB",
            'selling_currency' => 'THB',
            'selling_amount' => '14450',
        ])->assertStatus(303);

        $this->assertDatabaseHas('inquiries', [
            'organization_id' => $organizationId,
            'reference' => 'FICTIONAL-QUICK-PASTE-001',
            'service_date' => '2026-08-22',
            'service_notes' => '4小时',
            'selling_currency' => 'THB',
            'selling_amount_minor' => 1445000,
        ]);
    }
}
