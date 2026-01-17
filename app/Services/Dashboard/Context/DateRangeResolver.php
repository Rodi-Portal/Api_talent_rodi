<?php

namespace App\Services\Dashboard\Context;

use Carbon\Carbon;
use Illuminate\Http\Request;

class DateRangeResolver
{
    public static function resolve(Request $request): array
    {
        $today = Carbon::today();

        $startDate = $request->query('start_date');
        $endDate   = $request->query('end_date');

        if ($startDate && $endDate) {
            try {
                return [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay(),
                ];
            } catch (\Throwable $e) {
                // fallback
            }
        }

        $periodMonth = (string) $request->query('period_month', $today->format('Y-m'));

        if (! preg_match('/^\d{4}-\d{2}$/', $periodMonth)) {
            $periodMonth = $today->format('Y-m');
        }

        try {
            $base = Carbon::createFromFormat('Y-m', $periodMonth)->startOfMonth();
        } catch (\Throwable $e) {
            $base = $today->copy()->startOfMonth();
        }

        return [
            $base->copy()->startOfMonth(),
            $base->copy()->endOfMonth(),
        ];
    }
}
