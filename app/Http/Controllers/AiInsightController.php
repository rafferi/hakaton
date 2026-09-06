<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiServiceException;
use App\Http\Requests\SavingsPlanRequest;
use App\Http\Resources\AiInsightResource;
use App\Models\Statement;
use App\Services\AI\AiAnalysisService;
use App\Services\AI\InsightPersistenceService;
use App\Services\AI\SavingsPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiInsightController extends Controller
{
    /**
     * POST /api/statements/{statement}/ai/analyze
     *
     * Без force=true возвращает уже сохранённые insights без обращения
     * к GigaChat. Ошибки AI-слоя маппятся в осмысленные статусы,
     * а не голый 500.
     */
    public function analyze(
        Statement $statement,
        Request $request,
        AiAnalysisService $analysis,
    ): JsonResponse {
        try {
            $result = $analysis->analyzeStatement(
                $statement,
                $request->boolean('force')
            );
        } catch (AiServiceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatus());
        }

        return response()->json([
            'data' => AiInsightResource::collection($result['insights']),
            'from_cache' => $result['from_cache'],
        ]);
    }

    /**
     * GET /api/statements/{statement}/ai/insights
     *
     * Только чтение из БД, GigaChat не вызывается.
     */
    public function insights(
        Statement $statement,
        InsightPersistenceService $persistence,
    ): JsonResponse {
        return response()->json([
            'data' => AiInsightResource::collection(
                $persistence->forStatement($statement)
            ),
        ]);
    }

    /**
     * POST /api/statements/{statement}/savings-plan
     *
     * Интерактивный план "Хочу экономить X": нигде не сохраняется.
     */
    public function savingsPlan(
        Statement $statement,
        SavingsPlanRequest $request,
        SavingsPlanService $planner,
    ): JsonResponse {
        try {
            $plan = $planner->buildPlan(
                $statement,
                (float) $request->validated()['target_monthly_saving']
            );
        } catch (AiServiceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatus());
        }

        return response()->json($plan);
    }
}
