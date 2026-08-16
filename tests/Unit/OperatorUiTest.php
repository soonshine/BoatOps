<?php

namespace Tests\Unit;

use App\Support\OperatorUi;
use PHPUnit\Framework\TestCase;

class OperatorUiTest extends TestCase
{
    public function test_system_enums_have_chinese_display_labels_without_changing_contract_values(): void
    {
        $this->assertSame('已确认', OperatorUi::status('CONFIRMED'));
        $this->assertSame('待出航', OperatorUi::status('PLANNED'));
        $this->assertSame('已释放', OperatorUi::status('RELEASED'));
        $this->assertSame('未知状态（FUTURE_STATE）', OperatorUi::status('FUTURE_STATE'));
    }

    public function test_dates_use_chinese_labels_and_twenty_four_hour_time(): void
    {
        $this->assertSame('2026年8月14日', OperatorUi::date('2026-08-14'));
        $this->assertSame(
            '2026年8月14日 20:05',
            OperatorUi::dateTime('2026-08-14T13:05:00Z', 'Asia/Bangkok'),
        );
        $this->assertSame(
            '2026年8月14日 20:05–21:35',
            OperatorUi::dateTimeRange('2026-08-14T13:05:00Z', '2026-08-14T14:35:00Z', 'Asia/Bangkok'),
        );
        $this->assertSame(
            '2026年8月14日 23:30 – 2026年8月15日 01:00',
            OperatorUi::dateTimeRange('2026-08-14T16:30:00Z', '2026-08-14T18:00:00Z', 'Asia/Bangkok'),
        );
        $this->assertSame('未记录', OperatorUi::dateTime(null, 'Asia/Bangkok'));
    }

    public function test_operator_vocabulary_translates_display_values_only(): void
    {
        $this->assertSame('电话', OperatorUi::contactMethod('PHONE'));
        $this->assertSame('微信', OperatorUi::contactMethod('WECHAT'));
        $this->assertSame('船东自用', OperatorUi::blockReason('OWNER_USE'));
        $this->assertSame('订单已确认', OperatorUi::auditAction('booking.confirmed'));
        $this->assertSame('服务时段状态已变更', OperatorUi::auditAction('slot.offering.status.transitioned'));
        $this->assertSame('时段兼容规则已设置', OperatorUi::auditAction('slot.compatibility.rule.set'));
        $this->assertSame('操作员', OperatorUi::auditActor('operator_user'));
        $this->assertSame('订单', OperatorUi::auditObject('booking'));
    }

    public function test_default_slot_catalog_names_have_chinese_display_labels(): void
    {
        $this->assertSame('上午 4 小时', OperatorUi::slotName('Morning 4 Hours', 'AM_4H'));
        $this->assertSame('下午 2.5 小时', OperatorUi::slotName('Afternoon 2.5 Hours', 'PM_2_5H'));
        $this->assertSame('全天 8 小时', OperatorUi::slotName('Full Day 8 Hours', 'FULL_DAY_8H'));
        $this->assertSame('虚构演示：已停用时段', OperatorUi::slotName('Fictional Retired Slot', 'DEMO_REUSABLE_RETIRED'));
        $this->assertSame('虚构演示：全天 6 小时验证时段', OperatorUi::slotName('Fictional Validation Instance', 'DEMO_FULL_DAY_6H_20260817'));
        $this->assertSame('客制夕阳航程', OperatorUi::slotName('客制夕阳航程', 'CUSTOM_SUNSET'));
    }

    public function test_slot_wall_clock_and_duration_labels_use_existing_slot_truth(): void
    {
        $this->assertSame('08:00–12:00', OperatorUi::wallClockRange('08:00:00', '12:00:00'));
        $this->assertSame('未记录', OperatorUi::wallClockRange(null, '12:00:00'));
        $this->assertSame('4 小时', OperatorUi::durationMinutes(240));
        $this->assertSame('2 小时 30 分钟', OperatorUi::durationMinutes(150));
        $this->assertSame('45 分钟', OperatorUi::durationMinutes(45));
    }

    public function test_action_errors_are_chinese_even_when_shared_contract_messages_are_english(): void
    {
        $this->assertSame(
            '所选时段当前不可用，请选择其他时段。',
            OperatorUi::actionError([
                'code' => 'SLOT_UNAVAILABLE',
                'message' => 'The requested slot is unavailable.',
            ]),
        );
        $this->assertSame(
            '只有已出航的航次才能登记返航。',
            OperatorUi::actionError([
                'code' => 'INVALID_TRANSITION',
                'message' => 'Only a departed trip can return.',
            ]),
        );
        $this->assertSame(
            '操作未完成，请刷新页面后重试；如仍失败，请联系管理员。',
            OperatorUi::actionError([
                'code' => 'FUTURE_ERROR',
                'message' => 'A future English error.',
            ]),
        );
    }
}
