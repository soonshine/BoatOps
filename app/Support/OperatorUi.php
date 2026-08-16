<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class OperatorUi
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'ACTIVE' => '生效中',
        'AVAILABLE' => '可用',
        'BLOCKED' => '已停用',
        'CANCELLED' => '已取消',
        'COMPLETED' => '已完成',
        'CONFIRMED' => '已确认',
        'DEPARTED' => '已出航',
        'EXPIRED' => '已过期',
        'HELD' => '已预留',
        'INQUIRY' => '询价中',
        'PLANNED' => '待出航',
        'RELEASED' => '已释放',
        'RETURNED' => '已返航',
        'UNAVAILABLE' => '不可用',
    ];

    /** @var array<string, string> */
    private const CONTACT_METHOD_LABELS = [
        'PHONE' => '电话',
        'WHATSAPP' => 'WhatsApp',
        'WECHAT' => '微信',
        'LINE' => 'LINE',
        'EMAIL' => '电子邮箱',
        'OTHER' => '其他',
    ];

    /** @var array<string, string> */
    private const BLOCK_REASON_LABELS = [
        'MAINTENANCE' => '维护保养',
        'WEATHER' => '天气原因',
        'OWNER_USE' => '船东自用',
        'MANUAL' => '人工停用',
    ];

    /** @var array<string, string> */
    private const SLOT_CODE_LABELS = [
        'FULL_DAY_8H' => '全天 8 小时',
        'FULL_DAY_6H' => '全天 6 小时',
        'AM_4H' => '上午 4 小时',
        'PM_4H' => '下午 4 小时',
        'PM_2_5H' => '下午 2.5 小时',
        'DEMO_REUSABLE_DRAFT' => '虚构演示：可复用草稿时段',
        'DEMO_REUSABLE_RETIRED' => '虚构演示：已停用时段',
    ];

    /** @var array<string, string> */
    private const AUDIT_ACTION_LABELS = [
        'INQUIRY_CREATED' => '询价已创建',
        'INQUIRY_DOSSIER_UPDATED' => '运营资料已更新',
        'INQUIRY_HOLD_LINKED' => '询价已关联预留',
        'hold.created' => '预留已创建',
        'hold.released' => '预留已释放',
        'hold.expired' => '预留已过期',
        'booking.confirmed' => '订单已确认',
        'booking.amended' => '订单已改期',
        'booking.cancelled' => '订单已取消',
        'trip.prepared' => '出航准备已保存',
        'trip.departed' => '已登记出航',
        'trip.returned' => '已登记返航',
        'trip.completed' => '出航已完成',
        'resource.blocked' => '船只已停用',
        'resource.unblocked' => '船只停用已解除',
        'slot.offering.created' => '服务时段已创建',
        'slot.offering.status.transitioned' => '服务时段状态已变更',
        'slot.compatibility.rule.set' => '时段兼容规则已设置',
    ];

    /** @var array<string, string> */
    private const AUDIT_ACTOR_LABELS = [
        'operator_user' => '操作员',
        'api_client' => 'API 客户端',
        'system' => '系统',
    ];

    /** @var array<string, string> */
    private const AUDIT_OBJECT_LABELS = [
        'inquiry' => '询价',
        'hold' => '预留',
        'booking' => '订单',
        'trip' => '出航',
        'block' => '停用记录',
        'slot_offering' => '服务时段',
        'slot_compatibility_rule' => '时段兼容规则',
    ];

    /** @var array<string, string> */
    private const ERROR_MESSAGE_LABELS = [
        'The idempotency key was used with another payload.' => '页面操作标识已被用于其他内容，请刷新页面后重试。',
        'The requested slot is unavailable.' => '所选时段当前不可用，请选择其他时段。',
        'The selected slots cannot be combined on the same boat and service date.' => '同一船只和服务日期下，所选时段不能组合。',
        'Inventory linkage is inconsistent and requires manual action.' => '库存关联不一致，需要人工处理后才能继续。',
        'Only an active block can be released.' => '只有生效中的停用记录可以解除。',
        'Only a departed trip can return.' => '只有已出航的航次才能登记返航。',
        'Return time cannot be before departure time.' => '返航时间不能早于出航时间。',
        'Return time cannot be in the future.' => '返航时间不能晚于当前时间。',
        'Only a planned trip can depart.' => '只有待出航的航次可以登记出航。',
        'Only a planned trip can be prepared.' => '只有待出航的航次可以保存出航准备。',
        'Crew and all required checklist items must be complete before departure.' => '出航前必须至少安排一名船员，并完成全部必检项目。',
        'Departure time cannot be in the future.' => '出航时间不能晚于当前时间。',
        'Only a returned trip can be completed.' => '只有已返航的航次可以完成。',
        'A valid return time is required before completion.' => '完成航次前必须先记录有效返航时间。',
        'A trip with a future return time cannot be completed.' => '返航时间晚于当前时间，无法完成航次。',
        'The trip booking must still own active inventory.' => '该航次对应订单必须仍持有生效库存。',
        'The trip cannot be completed before the occupied inventory interval ends.' => '实际占用区间结束前不能完成航次。',
        'Only an active HOLD can be confirmed.' => '只有生效中的预留可以确认订单。',
        'Only an active HOLD can be released.' => '只有生效中的预留可以释放。',
        'The HOLD has expired.' => '该预留已过期。',
        'Only a confirmed booking can be cancelled.' => '只有已确认订单可以取消。',
        'Only a planned trip can be cancelled.' => '只有尚未出航的订单可以取消。',
        'Only a confirmed booking with active inventory can be amended.' => '只有仍持有生效库存的已确认订单可以改期。',
        'Only a booking with a planned trip can be amended.' => '只有尚未出航的订单可以改期。',
        'The request payload is invalid.' => '提交内容无效，请检查后重试。',
        'The external reference already exists.' => '该外部参考号已存在。',
        'This inquiry already has a linked HOLD.' => '该询价已关联预留。',
        'Boat, product, slot, and service date are required before creating a HOLD.' => '创建预留前必须选择船只、产品、服务时段和服务日期。',
        'HOLD creation is unavailable because the organization HOLD TTL policy is not configured.' => '组织尚未配置预留有效期，当前无法创建预留。',
    ];

    /** @var array<string, string> */
    private const ERROR_CODE_LABELS = [
        'AUTHORIZATION_FAILED' => '无权访问所请求的记录。',
        'VALIDATION_FAILED' => '提交内容无效，请检查后重试。',
        'IDEMPOTENCY_CONFLICT' => '页面操作标识冲突，请刷新页面后重试。',
        'DUPLICATE_EXTERNAL_REFERENCE' => '该外部参考号已存在。',
        'SLOT_UNAVAILABLE' => '所选时段当前不可用，请选择其他时段。',
        'SLOT_COMPATIBILITY_CONFLICT' => '同一船只和服务日期下，所选时段不能组合。',
        'INVENTORY_INTEGRITY_FAILED' => '库存关联不一致，需要人工处理后才能继续。',
        'INVALID_TRANSITION' => '当前状态不允许执行此操作。',
        'HOLD_ALREADY_LINKED' => '该询价已关联预留。',
        'INQUIRY_INCOMPLETE' => '创建预留前必须补全船只、产品、服务时段和服务日期。',
        'HOLD_TTL_POLICY_UNCONFIGURED' => '组织尚未配置预留有效期，当前无法创建预留。',
        'TRIP_NOT_READY' => '出航准备尚未完成。',
        'RATE_CHANGED' => '报价已失效，请重新确认价格。',
    ];

    public static function status(?string $status): string
    {
        if ($status === null || $status === '') {
            return '未记录';
        }

        return self::STATUS_LABELS[$status] ?? '未知状态（'.$status.'）';
    }

    public static function contactMethod(?string $method): string
    {
        if ($method === null || $method === '') {
            return '未提供';
        }

        return self::CONTACT_METHOD_LABELS[$method] ?? '其他（'.$method.'）';
    }

    public static function blockReason(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return '未提供';
        }

        return self::BLOCK_REASON_LABELS[$reason] ?? '其他原因（'.$reason.'）';
    }

    public static function slotName(string $name, ?string $code = null): string
    {
        if ($code !== null && isset(self::SLOT_CODE_LABELS[$code])) {
            return self::SLOT_CODE_LABELS[$code];
        }
        if ($code !== null && str_starts_with($code, 'DEMO_FULL_DAY_6H_')) {
            return '虚构演示：全天 6 小时验证时段';
        }

        $patterns = [
            '/^Morning\s+(\d+(?:\.\d+)?)\s+Hours?$/i' => '上午 $1 小时',
            '/^Afternoon\s+(\d+(?:\.\d+)?)\s+Hours?$/i' => '下午 $1 小时',
            '/^Full\s+Day\s+(\d+(?:\.\d+)?)\s+Hours?$/i' => '全天 $1 小时',
        ];
        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, trim($name)) === 1) {
                return preg_replace($pattern, $replacement, trim($name)) ?? trim($name);
            }
        }

        return $name;
    }

    public static function wallClockRange(?string $start, ?string $end): string
    {
        if ($start === null || $start === '' || $end === null || $end === '') {
            return '未记录';
        }

        return substr($start, 0, 5).'–'.substr($end, 0, 5);
    }

    public static function durationMinutes(?int $minutes): string
    {
        if ($minutes === null || $minutes <= 0) {
            return '未记录';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return $remainingMinutes.' 分钟';
        }
        if ($remainingMinutes === 0) {
            return $hours.' 小时';
        }

        return $hours.' 小时 '.$remainingMinutes.' 分钟';
    }

    public static function auditAction(?string $action): string
    {
        if ($action === null || $action === '') {
            return '未记录';
        }

        return self::AUDIT_ACTION_LABELS[$action] ?? '未识别操作（'.$action.'）';
    }

    public static function auditActor(?string $actor): string
    {
        if ($actor === null || $actor === '') {
            return '未记录';
        }

        return self::AUDIT_ACTOR_LABELS[$actor] ?? '其他主体（'.$actor.'）';
    }

    public static function auditObject(?string $object): string
    {
        if ($object === null || $object === '') {
            return '未记录';
        }

        return self::AUDIT_OBJECT_LABELS[$object] ?? '其他对象（'.$object.'）';
    }

    /** @param array<string, mixed> $payload */
    public static function actionError(array $payload): string
    {
        $message = $payload['message'] ?? null;
        if (is_string($message) && isset(self::ERROR_MESSAGE_LABELS[$message])) {
            return self::ERROR_MESSAGE_LABELS[$message];
        }

        $code = $payload['code'] ?? null;
        if (is_string($code) && isset(self::ERROR_CODE_LABELS[$code])) {
            return self::ERROR_CODE_LABELS[$code];
        }

        return '操作未完成，请刷新页面后重试；如仍失败，请联系管理员。';
    }

    public static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '未选择';
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')->format('Y年n月j日');
    }

    public static function dateTime(?string $value, string $timezone): string
    {
        if ($value === null || $value === '') {
            return '未记录';
        }

        return self::localDateTime($value, $timezone)->format('Y年n月j日 H:i');
    }

    public static function dateTimeRange(string $start, string $end, string $timezone): string
    {
        $localStart = self::localDateTime($start, $timezone);
        $localEnd = self::localDateTime($end, $timezone);

        if ($localStart->isSameDay($localEnd)) {
            return $localStart->format('Y年n月j日 H:i').'–'.$localEnd->format('H:i');
        }

        return $localStart->format('Y年n月j日 H:i').' – '.$localEnd->format('Y年n月j日 H:i');
    }

    private static function localDateTime(string $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone);
    }
}
