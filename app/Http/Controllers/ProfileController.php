<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\ProfileAggregationService;
use App\Services\MandatoryExpensesDetectorService;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    /**
     * GET /api/profile/trend — тренд доходов/расходов по месяцам
     * по всем выпискам текущего скоупа. Чистая SQL-агрегация, без GigaChat.
     */
    public function trend(ProfileAggregationService $profile): JsonResponse
    {
        return response()->json([
            'months' => $profile->buildMonthlyTrend(),
        ]);
    }

    /**
     * GET /api/profile/mandatory-expenses — обязательные ежемесячные
     * платежи по всем выпискам текущего скоупа. Чистая эвристика, без GigaChat.
     */
    public function mandatoryExpenses(MandatoryExpensesDetectorService $detector): JsonResponse
    {
        return response()->json($detector->detect());
    }
}
