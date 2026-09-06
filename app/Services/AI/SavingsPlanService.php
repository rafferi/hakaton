<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AiServiceException;
use App\Models\Statement;
use App\Services\AnalyticsService;
use Illuminate\Support\Facades\Log;

/**
 * План "Хочу экономить X": распределение целевой месячной экономии
 * по usable-категориям расходов.
 *
 * Источники (по приоритету): живой GigaChat (suggestSavingsDistribution)
 * → детерминированный пропорциональный fallback. Fallback работает
 * вообще без сети: это защита от недоступности GigaChat.
 * План нигде не сохраняется (интерактивный запрос, не анализ).
 */
class SavingsPlanService
{
    private const FALLBACK_MAX_PERCENTAGE = 70.0;

    private const FALLBACK_DEFAULT_PERCENTAGE = 20.0;

    public function __construct(
        private AnalyticsService $analytics,
        private GigaChatService $gigachat,
        private InsightPersistenceService $persistence,
    ) {}

    /**
     * @return array{
     *     target_monthly_saving: float,
     *     achievable_monthly_saving: float,
     *     achievable_annual_saving: float,
     *     reachable: bool,
     *     source: string,
     *     distribution: list<array{category: string, current_amount: float, reduction_percentage: float, monthly_saving: float}>
     * }
     */
    public function buildPlan(Statement $statement, float $target): array
    {
        $analyticsData = $this->analytics->analyze($statement);
        $totalExpenses = (float) ($analyticsData['totals']['total_expenses'] ?? 0);

        if ($target > $totalExpenses) {
            throw new AiServiceException(
                'Целевая сумма экономии превышает ваши общие расходы за период',
                422
            );
        }

        $ranking = $this->persistence->usableExpenseCategories($statement, $analyticsData);

        if ($ranking === []) {
            return $this->emptyPlan($target);
        }

        $items = $this->aiDistribution($statement, $ranking, $target);

        if ($items !== []) {
            return $this->assemblePlan($target, $ranking, $items, 'ai');
        }

        Log::info('Savings plan uses deterministic fallback', [
            'statement_id' => $statement->id,
            'target' => $target,
        ]);

        return $this->assemblePlan($target, $ranking, $this->fallbackItems($ranking, $target), 'fallback');
    }

    /**
     * Живой план от модели: неизвестные категории пропускаются,
     * невалидный процент → дефолт (как в recommendations).
     * Пусто/ошибка транспорта → [] (оркестратор уйдёт в fallback).
     *
     * @param  list<array{name: string, amount: float, percentage: float|null}>  $ranking
     * @return list<array{category: string, reduction_percentage: float}>
     */
    private function aiDistribution(Statement $statement, array $ranking, float $target): array
    {
        $categories = array_map(
            fn (array $entry): array => ['category' => $entry['name'], 'amount' => $entry['amount']],
            $ranking
        );

        try {
            $suggested = $this->gigachat->suggestSavingsDistribution($categories, $target);
        } catch (AiServiceException $e) {
            Log::info('Savings plan AI call failed, falling back', [
                'statement_id' => $statement->id,
                'status' => $e->getStatus(),
            ]);

            return [];
        }

        $byName = [];
        foreach ($ranking as $entry) {
            $byName[mb_strtolower(trim($entry['name']))] = $entry['name'];
        }

        $items = [];

        foreach ($suggested as $item) {
            $key = mb_strtolower(trim($item['category']));

            if (! isset($byName[$key])) {
                Log::warning('Savings plan skips unknown AI category', [
                    'statement_id' => $statement->id,
                    'category' => $item['category'],
                ]);

                continue;
            }

            $percentage = $item['reduction_percentage'];

            if ($percentage < 0 || $percentage > 100) {
                Log::warning('Savings plan uses default percentage=20', [
                    'statement_id' => $statement->id,
                    'category' => $item['category'],
                    'received' => $percentage,
                ]);
                $percentage = self::FALLBACK_DEFAULT_PERCENTAGE;
            }

            // Одна категория — одно предложение (первое выигрывает).
            $items[$key] ??= [
                'category' => $byName[$key],
                'reduction_percentage' => (float) $percentage,
            ];
        }

        return array_values($items);
    }

    /**
     * Пропорциональный fallback: target делится по долям категорий пула,
     * процент среза ограничен сверху, чтобы не предлагать absurd вроде
     * "сократи всё на 95%".
     *
     * @param  list<array{name: string, amount: float, percentage: float|null}>  $ranking
     * @return list<array{category: string, reduction_percentage: float}>
     */
    private function fallbackItems(array $ranking, float $target): array
    {
        $pool = 0.0;
        foreach ($ranking as $entry) {
            $pool += $entry['amount'];
        }

        if ($pool <= 0) {
            return [];
        }

        // Точный процент — для математики; округление только на выходе,
        // иначе округлённый процент даёт дрейф суммы по категориям.
        $percentage = min($target / $pool * 100, self::FALLBACK_MAX_PERCENTAGE);

        return array_map(
            fn (array $entry): array => [
                'category' => $entry['name'],
                'reduction_percentage' => $percentage,
            ],
            $ranking
        );
    }

    /**
     * @param  list<array{name: string, amount: float, percentage: float|null}>  $ranking
     * @param  list<array{category: string, reduction_percentage: float}>  $items
     */
    private function assemblePlan(float $target, array $ranking, array $items, string $source): array
    {
        $amounts = [];
        foreach ($ranking as $entry) {
            $amounts[mb_strtolower(trim($entry['name']))] = $entry['amount'];
        }

        $distribution = [];
        $achievable = 0.0;

        foreach ($items as $item) {
            $amount = $amounts[mb_strtolower(trim($item['category']))] ?? 0.0;
            $monthly = round($amount * ($item['reduction_percentage'] / 100), 2);
            $achievable += $monthly;

            $distribution[] = [
                'category' => $item['category'],
                'current_amount' => $amount,
                'reduction_percentage' => round((float) $item['reduction_percentage'], 2),
                'monthly_saving' => $monthly,
            ];
        }

        $achievable = round($achievable, 2);

        return [
            'target_monthly_saving' => $target,
            'achievable_monthly_saving' => $achievable,
            'achievable_annual_saving' => round($achievable * 12, 2),
            'reachable' => $achievable >= $target * 0.9,
            'source' => $source,
            'distribution' => $distribution,
        ];
    }

    /**
     * @return array{
     *     target_monthly_saving: float,
     *     achievable_monthly_saving: float,
     *     achievable_annual_saving: float,
     *     reachable: bool,
     *     source: string,
     *     distribution: list<array{category: string, current_amount: float, reduction_percentage: float, monthly_saving: float}>
     * }
     */
    private function emptyPlan(float $target): array
    {
        return [
            'target_monthly_saving' => $target,
            'achievable_monthly_saving' => 0.0,
            'achievable_annual_saving' => 0.0,
            'reachable' => false,
            'source' => 'fallback',
            'distribution' => [],
        ];
    }
}
