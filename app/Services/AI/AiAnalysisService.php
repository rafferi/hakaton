<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiInsight;
use App\Models\Statement;
use App\Services\AnalyticsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Оркестрация AI-анализа выписки: кеш → аналитика → GigaChat → персистентность.
 *
 * Повторный вызов без force НЕ обращается к GigaChat (платно/лимитировано),
 * а возвращает уже сохранённые строки. Сравнение с предыдущим периодом
 * строится ТОЛЬКО по реально предшествующей выписке: period_to строго
 * раньше текущего period_from, ближайшая по дате. Никаких "любых с меньшим
 * id" — такая подстановка давала ложные сравнения (вплоть до выписок
 * из будущего). Если предшественника нет — сравнение в промпт не идёт.
 *
 * Защита от нестабильности модели: если первая попытка дала 0 валидных
 * пунктов (модель вернула пустые массивы), делается ОДНА повторная попытка
 * с тем же промптом и Log::info. Пустой итог после retry — это валидный
 * результат "модель предпочла ничего не сообщить" (200 с пустым списком),
 * а не ошибка; 422 — только для структурно нечитаемых ответов.
 */
class AiAnalysisService
{
    public function __construct(
        private AnalyticsService $analytics,
        private GigaChatService $gigachat,
        private InsightPersistenceService $persistence,
    ) {}

    /**
     * @return array{insights: Collection<int, AiInsight>, from_cache: bool}
     */
    public function analyzeStatement(Statement $statement, bool $force = false): array
    {
        if (! $force) {
            $existing = $this->persistence->forStatement($statement);

            if ($existing->isNotEmpty()) {
                return ['insights' => $existing, 'from_cache' => true];
            }
        }

        $analyticsData = $this->analytics->analyze($statement);
        $previousPeriod = $this->previousPeriodTotals($statement);
        // Transfer-категории скрываем из промпта заранее, чтобы модель
        // вообще не видела их как кандидатов для рекомендаций.
        $hiddenCategories = $this->persistence->transferCategories($statement);

        $payload = $this->gigachat->analyze($analyticsData, $previousPeriod, $hiddenCategories);
        $saved = $this->persistence->persist($statement, $payload, $analyticsData);

        if ($saved->isEmpty()) {
            Log::info('GigaChat analysis came back empty, retrying once with the same prompt', [
                'statement_id' => $statement->id,
            ]);

            $payload = $this->gigachat->analyze($analyticsData, $previousPeriod, $hiddenCategories);
            $saved = $this->persistence->persist($statement, $payload, $analyticsData);
        }

        return ['insights' => $saved, 'from_cache' => false];
    }

    /**
     * Итоги реально предшествующей выписки: period_to строго раньше
     * текущего period_from, ближайшая по дате. Иначе null — и тогда
     * сравнение периодов в промпт не попадает вообще.
     *
     * Дополнительно требуется СОПОСТАВИМОСТЬ объёма данных — сразу по двум
     * критериям через AND (обоснование: каждый по отдельности врёт —
     * одинаковый count при разной длине периодов даёт разные дневные
     * средние, а одинаковая длина при count 7 vs 51 — разную плотность
     * данных; вместе они отсекают оба класса ложных сравнений):
     * - transactions_count отличаются не более чем в 2 раза (min/max >= 0.5);
     * - длина периодов в днях отличается не более чем в 2 раза.
     * Несопоставимый кандидат отклоняется так же, как отсутствующий
     * (null + Log::info для отладки).
     *
     * @return array{period_from: ?string, period_to: ?string, total_income: float, total_expenses: float, transactions_count: int}|null
     */
    private function previousPeriodTotals(Statement $statement): ?array
    {
        if (! $statement->period_from instanceof \DateTimeInterface) {
            return null;
        }

        // Предыдущий период ищем только в своём бакете (per-user при
        // авторизации, демо-бакет сейчас) — чужие выписки не подмешиваем.
        $previous = Statement::forCurrentUser()
            ->where('id', '!=', $statement->id)
            ->where('transactions_count', '>', 0)
            ->whereNotNull('period_to')
            ->whereDate('period_to', '<', $statement->period_from->format('Y-m-d'))
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return null;
        }

        if (! $this->isComparable($statement, $previous)) {
            Log::info('Previous statement found but rejected as incomparable', [
                'statement_id' => $statement->id,
                'previous_id' => $previous->id,
                'current_count' => $statement->transactions_count,
                'previous_count' => $previous->transactions_count,
                'current_period' => $statement->period_from->format('Y-m-d').'..'.$statement->period_to?->format('Y-m-d'),
                'previous_period' => $previous->period_from?->format('Y-m-d').'..'.$previous->period_to->format('Y-m-d'),
            ]);

            return null;
        }

        $incomeTypes = ['income', 'credit'];
        $expenseTypes = ['expense', 'debit'];

        $row = $previous->transactions()
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END) AS income_sum', $incomeTypes)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN ABS(amount) ELSE 0 END) AS expense_sum', $expenseTypes)
            ->selectRaw('COUNT(*) AS total_cnt')
            ->first();

        return [
            'period_from' => $previous->period_from instanceof \DateTimeInterface
                ? $previous->period_from->format('Y-m-d')
                : null,
            'period_to' => $previous->period_to instanceof \DateTimeInterface
                ? $previous->period_to->format('Y-m-d')
                : null,
            'total_income' => (float) ($row->income_sum ?? 0),
            'total_expenses' => (float) ($row->expense_sum ?? 0),
            'transactions_count' => (int) ($row->total_cnt ?? 0),
        ];
    }

    /**
     * Проверка сопоставимости двух выписок для сравнения в промпте.
     */
    private function isComparable(Statement $current, Statement $previous): bool
    {
        $currentCount = (int) $current->transactions_count;
        $previousCount = (int) $previous->transactions_count;

        if ($currentCount <= 0 || $previousCount <= 0) {
            return false;
        }

        if (min($currentCount, $previousCount) / max($currentCount, $previousCount) < 0.5) {
            return false;
        }

        if (! $current->period_from instanceof \DateTimeInterface
            || ! $current->period_to instanceof \DateTimeInterface
            || ! $previous->period_from instanceof \DateTimeInterface
            || ! $previous->period_to instanceof \DateTimeInterface
        ) {
            return false;
        }

        // Inclusive length in days; max(..., 1) avoids division by zero
        // for single-day statements.
        $currentDays = max($current->period_from->diffInDays($current->period_to) + 1, 1);
        $previousDays = max($previous->period_from->diffInDays($previous->period_to) + 1, 1);

        return min($currentDays, $previousDays) / max($currentDays, $previousDays) >= 0.5;
    }
}
