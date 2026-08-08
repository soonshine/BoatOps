<?php

namespace App\Http\Controllers\Operator;

use App\Exceptions\SlotCatalogException;
use App\Http\Controllers\Controller;
use App\Services\SlotCatalog\SlotCalendarReadModel;
use App\Services\SlotCatalog\SlotCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class OperatorCalendarController extends Controller
{
    public function __construct(private readonly SlotCalendarReadModel $calendar, private readonly SlotCatalogService $catalog) {}

    public function index(Request $r): View
    {
        $i = $r->validate(['range' => ['sometimes', 'integer', 'in:7,30'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'boat_id' => ['sometimes', 'integer', 'min:1']]);
        $o = $r->attributes->get('organization');
        $range = (int) ($i['range'] ?? 7);
        $from = (string) ($i['from'] ?? CarbonImmutable::now((string) $o->timezone)->format('Y-m-d'));
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $from, (string) $o->timezone);
        try {
            $calendar = $this->calendar->read($o, $from, $d->addDays($range - 1)->format('Y-m-d'), isset($i['boat_id']) ? (int) $i['boat_id'] : null);
            $slots = $this->catalog->listOfferings((int) $o->id);
        } catch (SlotCatalogException $e) {
            abort($e->errorCode === 'AUTHORIZATION_FAILED' ? 404 : $e->httpStatus, $e->getMessage());
        }$products = DB::table('trip_templates')->where('organization_id', $o->id)->where('status', 'ACTIVE')->orderBy('name')->get();

        return view('operator.calendar', compact('calendar', 'products', 'slots', 'range', 'from') + ['organization' => $o]);
    }
}
