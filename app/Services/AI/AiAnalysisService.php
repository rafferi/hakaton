<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiInsight;
use App\Models\Statement;
use App\Services\AnalyticsService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Оркестрация AI-анализа выписки: кеш → аналитика → GigaChat → персистентность.
 *
 * Повторный вызов без force НЕ обращается к GigaChat (платно/лимитировано),
 * а возвращает уже сохранённые строки. Сравнение с предыдущим периодом
 * строится по ближайшей более ранней выписке с транзакциями; если её нет —
 * в промпт уходит явное "сравнения нет" (модель его не выдумывает).
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

        $payload = $this->gigachat->analyze($analyticsData, $previousPeriod);
        $saved = $this->persistence->persist($statement, $payload);

        return ['insights' => $saved, 'from_cache' => false];
    }

    /**
     * Итоги ближайшей более ранней выписки с транзакциями.
     *
     * @return array{period_from: ?string, period_to: ?string, total_income: float, total_expenses: float, transactions_count: int}|null
     */
    private function previousPeriodTotals(Statement $statement): ?array
    {
        $previous = Statement::query()
            ->where('id', '<', $statement->id)
            ->where('transactions_count', '>', 0)
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
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
}
