<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveDemoSiteContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSiteSeeder;
use Database\Seeders\SlotCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoScheduleTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'fictional-demo-schedule-token-for-tests';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        putenv('BOATOPS_DEMO_TOKEN');
        parent::tearDown();
    }

    public function test_calendar_and_slot_pages_render_seeded_fictional_status_matrix_and_warnings(): void
    {
        $this->enableAndSeed();
        $calendar = $this->get('/demo/calendar?from=2026-08-04')->assertOk()
            ->assertSee('运营库存日历')
            ->assertSee('Plan A（虚构演示船）')
            ->assertSee('Plan B（虚构演示船）')
            ->assertSee('AVAILABLE')->assertSee('HELD')->assertSee('CONFIRMED')->assertSee('BLOCKED')->assertSee('UNAVAILABLE')
            ->assertSee('service local:')->assertSee('occupied local:')->assertSee('actual occupied:')
            ->assertSee('BUFFER CONFLICT')->assertSee('SLOT_UNAVAILABLE')->assertSee('SLOT_COMPATIBILITY_CONFLICT')
            ->assertSee('演示默认档期；真实起止时间和周转缓冲尚未冻结。')
            ->assertSee('DEMO_FULL_DAY_6H_20260807')
            ->assertDontSee('organization_id', false);
        $this->assertStringNotContainsString($this->token, $calendar->getContent());
        $this->assertSame(14, substr_count($calendar->getContent(), 'data-business-date="2026-08-'));

        $slots = $this->get('/demo/slots')->assertOk()
            ->assertSee('运营端档期目录')
            ->assertSee('创建 reusable custom slot')
            ->assertSee('创建 date-specific custom slot instance')
            ->assertSee('配置 ALLOW / DENY compatibility')
            ->assertSee('DEMO_REUSABLE_DRAFT')->assertSee('DEMO_REUSABLE_RETIRED')
            ->assertSee('ACTIVE')->assertSee('DRAFT')->assertSee('RETIRED')
            ->assertSee('12:00–18:00')
            ->assertSee('DEMO DEFAULT / UNVERIFIED OPERATING TIME')
            ->assertSee('name="_token"', false)
            ->assertDontSee('name="organization_id"', false);
        $this->assertStringNotContainsString($this->token, $slots->getContent());
    }

    public function test_demo_schedule_routes_use_web_csrf_and_reject_missing_token_locally(): void
    {
        $this->enableAndSeed();
        foreach ([
            'demo.slots.reusable',
            'demo.slots.instances',
            'demo.slots.compatibility',
            'demo.slots.activate',
            'demo.slots.retire',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains(ResolveDemoSiteContext::class, $route->gatherMiddleware());
        }

        $this->app->detectEnvironment(fn (): string => 'local');
        try {
            $this->post('/demo/slots/reusable', [])->assertStatus(419);
            $this->post('/demo/slots/compatibility', [])->assertStatus(419);
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_demo_forms_create_transition_and_upsert_only_fictional_schedule_data(): void
    {
        $this->enableAndSeed();
        $planA = DB::table('boats')->where('name', config('demo_site.boat_names.0'))->first();
        $actorId = (int) DB::table('api_clients')->where('name', config('demo_site.actor_name'))->value('id');

        $this->postDemo('/demo/slots/reusable', [
            'code' => 'DEMO_FORM_REUSABLE',
            'name' => 'Fictional Form Reusable Slot',
            'service_start_time' => '09:30',
            'service_end_time' => '11:30',
            'duration_minutes' => 120,
            'additional_buffer_before_minutes' => 5,
            'additional_buffer_after_minutes' => 10,
            'valid_from' => '2026-08-10',
            'valid_until' => '2026-08-31',
            'scope' => 'SELECTED',
            'boat_ids' => [$planA->id],
        ])->assertRedirect('/demo/slots')->assertSessionHas('status');
        $reusableId = (int) DB::table('slot_offerings')->where('code', 'DEMO_FORM_REUSABLE')->value('id');
        $this->assertDatabaseHas('slot_offerings', [
            'id' => $reusableId,
            'status' => 'DRAFT',
            'operating_time_status' => 'DEMO_DEFAULT_UNVERIFIED',
        ]);
        $this->assertDatabaseHas('slot_offering_boats', [
            'slot_offering_id' => $reusableId,
            'boat_id' => $planA->id,
        ]);
        $this->postDemo("/demo/slots/{$reusableId}:activate", [])
            ->assertRedirect('/demo/slots');
        $this->assertDatabaseHas('slot_offerings', ['id' => $reusableId, 'status' => 'ACTIVE']);

        $templateId = (int) DB::table('slot_offerings')->where('code', 'FULL_DAY_6H')->value('id');
        $this->postDemo('/demo/slots/instances', [
            'template_slot_offering_id' => $templateId,
            'code' => 'DEMO_FORM_INSTANCE_1200',
            'name' => 'Fictional Form Date Instance',
            'status' => 'ACTIVE',
            'service_date' => '2026-08-12',
            'service_start_time' => '12:00',
            'service_end_time' => '18:00',
            'duration_minutes' => 360,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'scope' => 'SELECTED',
            'boat_ids' => [$planA->id],
        ])->assertRedirect('/demo/slots')->assertSessionHas('status');
        $instanceId = (int) DB::table('slot_offerings')->where('code', 'DEMO_FORM_INSTANCE_1200')->value('id');
        $this->assertDatabaseHas('slot_offerings', [
            'id' => $instanceId,
            'kind' => 'CUSTOM_INSTANCE',
            'service_date' => '2026-08-12',
            'service_start_time' => '12:00:00',
            'service_end_time' => '18:00:00',
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
        ]);

        $this->postDemo('/demo/slots/compatibility', [
            'first_slot_offering_id' => $reusableId,
            'second_slot_offering_id' => $instanceId,
            'policy' => 'ALLOW',
            'reason' => 'Fictional form allow',
        ])->assertRedirect('/demo/slots')->assertSessionHas('status');
        $pairKey = min($reusableId, $instanceId).':'.max($reusableId, $instanceId);
        $this->assertDatabaseHas('slot_compatibility_rules', ['pair_key' => $pairKey, 'policy' => 'ALLOW']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $actorId,
            'action' => 'slot.compatibility.created',
        ]);
        $this->assertSame(1, DB::table('slot_compatibility_rules')->where('pair_key', $pairKey)->count());
    }

    public function test_demo_forms_revalidate_boat_and_slot_ids_without_partial_cross_organization_writes(): void
    {
        $this->enableAndSeed();
        $otherOrganizationId = DB::table('organizations')->insertGetId([
            'name' => 'Other Fictional Demo Isolation',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherBoatId = DB::table('boats')->insertGetId([
            'organization_id' => $otherOrganizationId,
            'name' => 'Other Forbidden Boat',
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seed(SlotCatalogSeeder::class);
        $otherSlotId = (int) DB::table('slot_offerings')
            ->where('organization_id', $otherOrganizationId)
            ->where('code', 'AM_4H')
            ->value('id');
        $demoSlotId = (int) DB::table('slot_offerings')
            ->where('organization_id', '!=', $otherOrganizationId)
            ->where('code', 'PM_4H')
            ->value('id');
        $offeringCount = DB::table('slot_offerings')->count();
        $ruleCount = DB::table('slot_compatibility_rules')->count();

        $this->postDemo('/demo/slots/reusable', [
            'code' => 'DEMO_FORGED_BOAT',
            'name' => 'Fictional Forged Boat Slot',
            'service_start_time' => '09:00',
            'service_end_time' => '10:00',
            'duration_minutes' => 60,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'scope' => 'SELECTED',
            'boat_ids' => [$otherBoatId],
        ])->assertRedirect('/demo/slots')->assertSessionHasErrors('boat_ids');
        $this->postDemo('/demo/slots/compatibility', [
            'first_slot_offering_id' => $demoSlotId,
            'second_slot_offering_id' => $otherSlotId,
            'policy' => 'ALLOW',
            'reason' => 'Fictional forbidden cross organization pair',
        ])->assertRedirect('/demo/slots')->assertSessionHasErrors('schedule');

        $this->assertSame($offeringCount, DB::table('slot_offerings')->count());
        $this->assertSame($ruleCount, DB::table('slot_compatibility_rules')->count());
        $this->assertDatabaseMissing('slot_offerings', ['code' => 'DEMO_FORGED_BOAT']);
    }

    public function test_390px_layout_contract_has_no_fixed_width_calendar_or_catalog_overflow(): void
    {
        $this->enableAndSeed();

        foreach (['/demo/calendar?from=2026-08-04', '/demo/slots'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertStringContainsString('data-verified-mobile-width="390"', $html);
            $this->assertStringContainsString('html,body{margin:0;max-width:100%;overflow-x:hidden}', $html);
            $this->assertStringContainsString('minmax(min(100%', $html);
            $this->assertStringNotContainsString('<table', strtolower($html));
            $this->assertDoesNotMatchRegularExpression('/min-width:\s*[4-9]\d{2}px/i', $html);
            $this->assertDoesNotMatchRegularExpression('/width:\s*[4-9]\d{2}px/i', $html);
        }
    }

    private function enableAndSeed(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 01:00:00 UTC');
        config(['demo_site.enabled' => true]);
        putenv('BOATOPS_DEMO_TOKEN='.$this->token);
        $this->seed(DemoSiteSeeder::class);
    }

    private function postDemo(string $uri, array $payload): mixed
    {
        $token = 'fictional-schedule-csrf-token';

        return $this->from('/demo/slots')->withSession(['_token' => $token])
            ->post($uri, ['_token' => $token, ...$payload]);
    }
}
