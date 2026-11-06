<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Elder;
use App\Services\CalendarService;
use App\Services\QuickCall;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DailyViewController extends Controller
{
    public function __invoke(Request $request, Elder $elder, CalendarService $calendar, QuickCall $quickCall)
    {
        $data = $request->validate(['date' => 'sometimes|date_format:Y-m-d']);
        $date = $data['date'] ?? CarbonImmutable::now($elder->timezone)->format('Y-m-d');
        $day = $calendar->forDay($request->user(), $elder, $date);

        return ['data' => ['elder' => ['id' => $elder->id, 'name' => $elder->name], 'date' => $date, 'timezone' => $elder->timezone, 'items' => $day['data'], 'quick_call' => $quickCall->resolve($elder)]];
    }
}
