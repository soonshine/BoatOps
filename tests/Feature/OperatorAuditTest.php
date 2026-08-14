<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_is_organization_scoped_and_calendar_read_permission_fails_closed(): void
    {
        $allowed = $this->context();
        $foreign = $this->context();
        $denied = $this->context(false);
        $this->audit($allowed, 'VISIBLE_FICTIONAL_ACTION');
        $this->audit($foreign, 'FOREIGN_SECRET_ACTION');
        $this->audit($denied, 'DENIED_ORGANIZATION_ACTION');
        $this->actingAs($allowed['user'])->get('/operator/audit')->assertOk()->assertSee('操作记录')->assertSee('只读')->assertSee('2026年9月1日 07:00')->assertSee('VISIBLE_FICTIONAL_ACTION')->assertDontSee('FOREIGN_SECRET_ACTION')->assertDontSee('DENIED_ORGANIZATION_ACTION');
        $this->get('/operator/calendar?from=2026-09-01')->assertOk()->assertSee('/operator/audit', false);
        $this->actingAs($denied['user'])->get('/operator/audit')->assertForbidden();
    }

    public function test_audit_is_newest_first_and_bounded_to_fifty_rows_per_page(): void
    {
        $context = $this->context();
        for ($number = 1; $number <= 55; $number++) {
            $this->audit($context, sprintf('FICTIONAL_PAGE_ACTION_%02d', $number), sprintf('2026-09-01 00:%02d:00', $number));
        }

        $first = $this->actingAs($context['user'])->get('/operator/audit')->assertOk();
        $logs = $first->viewData('auditLogs');
        $this->assertCount(50, $logs);
        $this->assertSame('FICTIONAL_PAGE_ACTION_55', $logs->first()->action);
        $this->assertSame('FICTIONAL_PAGE_ACTION_06', $logs->last()->action);
        $first->assertSee('下一页')->assertSee('FICTIONAL_PAGE_ACTION_55')->assertSee('FICTIONAL_PAGE_ACTION_06')->assertDontSee('FICTIONAL_PAGE_ACTION_05')->assertDontSee('FICTIONAL_PAGE_ACTION_01');
        $this->assertLessThan(strpos($first->getContent(), 'FICTIONAL_PAGE_ACTION_06'), strpos($first->getContent(), 'FICTIONAL_PAGE_ACTION_55'));
        $second = $this->get('/operator/audit?page=2')->assertOk();
        $logs = $second->viewData('auditLogs');
        $this->assertCount(5, $logs);
        $this->assertSame('FICTIONAL_PAGE_ACTION_05', $logs->first()->action);
        $this->assertSame('FICTIONAL_PAGE_ACTION_01', $logs->last()->action);
        $second->assertSee('上一页')->assertDontSee('FICTIONAL_PAGE_ACTION_06');
    }

    public function test_audit_safely_escapes_content_and_shows_lifecycle_actions_with_actor_attribution(): void
    {
        $context = $this->context();
        $actions = ['INQUIRY_CREATED', 'hold.created', 'booking.confirmed', 'booking.amended', 'booking.cancelled', 'resource.blocked', 'resource.unblocked'];
        foreach ($actions as $offset => $action) {
            $this->audit($context, $action, sprintf('2026-09-02 00:0%d:00', $offset));
        }

        DB::table('audit_logs')->where('organization_id', $context['organization_id'])->where('action', 'INQUIRY_CREATED')->update(['reason' => '<script>fictionalReason()</script>',            'before_values' => json_encode(['html' => '<b>fictional before</b>'], JSON_THROW_ON_ERROR),            'after_values' => json_encode(['html' => '<img src=x onerror=fictionalAfter()>'], JSON_THROW_ON_ERROR)]);
        $response = $this->actingAs($context['user'])->get('/operator/audit')->assertOk()->assertSee('&lt;script&gt;fictionalReason()&lt;/script&gt;', false)->assertSee('&lt;b&gt;fictional before&lt;\/b&gt;', false)->assertSee('&lt;img src=x onerror=fictionalAfter()&gt;', false)->assertDontSee('<script>fictionalReason()</script>', false)->assertDontSee('<b>fictional before</b>', false)->assertDontSee('<img src=x onerror=fictionalAfter()>', false)->assertSee('操作员 / '.$context['user']->id);
        foreach (['询价已创建', '预留已创建', '订单已确认', '订单已改期', '订单已取消', '船只已停用', '船只停用已解除'] as $action) {
            $response->assertSee($action);
        }
    }

    public function test_audit_read_path_has_no_mutation_or_secret_table_access(): void
    {
        $context = $this->context();
        $this->audit($context, 'FICTIONAL_READ_ONLY_EVIDENCE');
        $before = json_encode(DB::table('audit_logs')->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        $this->actingAs($context['user'])->get('/operator/audit')->assertOk();
        $this->assertSame($before, json_encode(DB::table('audit_logs')->orderBy('id')->get(), JSON_THROW_ON_ERROR));
        $controller = file_get_contents(app_path('Http/Controllers/Operator/AuditController.php'));
        $this->assertSame(1, substr_count($controller, "DB::table('audit_logs')"));
        foreach (['DB::transaction', 'insert(', 'update(', 'delete(', 'idempotency_keys', 'outbox_events'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $controller);
        }

        $view = file_get_contents(resource_path('views/operator/audit.blade.php'));
        foreach (['<form', '@csrf', 'idempotency', 'outbox', 'Export'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }

        $route = app('router')->getRoutes()->getByName('operator.audit');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/operator/audit')->assertMethodNotAllowed()->assertHeader('Allow', 'GET, HEAD');
    }

    public function test_public_production_demo_hides_audit_get_and_rejects_non_get(): void
    {
        $environment = $this->app->environment();
        try {
            $this->app->detectEnvironment(fn (): string => 'production');
            config(['demo_site.mode' => 'public_read_only', 'demo_site.isolated_dataset' => true,                'database.default' => 'sqlite', 'database.connections.sqlite.url' => null,                'cache.default' => 'file', 'cache.limiter' => 'file', 'session.driver' => 'file', 'queue.default' => 'sync']);
            $this->get('/operator/audit')->assertNotFound();
            $this->post('/operator/audit')->assertStatus(405)->assertHeader('Allow', 'GET');
        } finally {
            $this->app->detectEnvironment(fn (): string => $environment);
        }
    }

    private function context(bool $canRead = true): array
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Fictional Audit Organization '.Str::random(5), 'timezone' => 'Asia/Bangkok',            'inventory_revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::create(['name' => 'Fictional Audit Operator', 'email' => Str::random(8).'@example.test',            'password' => Hash::make('fictional-password')]);
        DB::table('operator_memberships')->insert(['organization_id' => $organizationId, 'user_id' => $user->id, 'status' => 'ACTIVE',            'can_calendar_read' => $canRead, 'can_booking_workflow' => false, 'can_block' => false,            'created_at' => now(), 'updated_at' => now()]);

        return ['organization_id' => $organizationId, 'user' => $user];
    }

    private function audit(array $context, string $action, string $createdAt = '2026-09-01 00:00:00'): void
    {
        DB::table('audit_logs')->insert(['organization_id' => $context['organization_id'], 'actor_type' => 'operator_user', 'actor_id' => $context['user']->id,            'action' => $action, 'object_type' => 'fictional_record', 'object_id' => DB::table('audit_logs')->count() + 1000,            'before_values' => json_encode(['status' => 'FICTIONAL_BEFORE'], JSON_THROW_ON_ERROR),            'after_values' => json_encode(['status' => 'FICTIONAL_AFTER'], JSON_THROW_ON_ERROR),            'reason' => 'Fictional audit fixture', 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }
}
