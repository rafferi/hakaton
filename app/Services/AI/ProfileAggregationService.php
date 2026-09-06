<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiInsight;
use App\Models\Statement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Агрегированная сводка по ВСЕМ выпискам текущего скоупа
 * (Statement::forCurrentUser() — демо-бакет сейчас, per-user позже).
 * Только агрегирующие SQL-запросы, без загрузки транзакций в PHP.
 */
class ProfileAggregationService
{
    private const INCOME_TYPES = ['income', 'credit'];

    private const EXPENSE_TYPES = ['expense', 'debit'];

    /**
     * @return array{
     *     total_statements: int,
     *     date_range: array{from: ?string, to: ?string},
     *     total_income: float,
     *     total_expenses: float,
     *     balance: float,
     *     top_categories: list<array{category: string, amount: float, percentage: float}>,
     *     top_recipients: list<array{recipient: string, amount: float, transactions_count: int}>,
     *     average_monthly_expenses: float,
     *     recent_insights: list<array{id: int, type: string, title: string, description: ?string, potential_saving: ?float, created_at: ?string}>
     * }
     */
    public function buildAggregatedProfile(): array
    {
        $statementIds = Statement::forCurrentUser()->pluck('id');

        $empty = [
            'total_statements' => 0,
            'date_range' => ['from' => null, 'to' => null],
            'total_income' => 0.0,
            'total_expenses' => 0.0,
            'balance' => 0.0,
            'top_categories' => [],
            'top_recipients' => [],
            'average_monthly_expenses' => 0.0,
            'recent_insights' => [],
        ];

        if ($statementIds->isEmpty()) {
            return $empty;
        }

        $incomeList = $this->quotedList(self::INCOME_TYPES);
        $expenseList = $this->quotedList(self::EXPENSE_TYPES);

        $totals = Transaction::query()
            ->whereIn('statement_id', $statementIds)
            ->selectRaw("SUM(CASE WHEN type IN ({$incomeList}) THEN amount ELSE 0 END) AS income_sum")
            ->selectRaw("SUM(CASE WHEN type IN ({$expenseList}) THEN ABS(amount) ELSE 0 END) AS expense_sum")
            ->selectRaw('MIN(date) AS min_date')
            ->selectRaw('MAX(date) AS max_date')
            ->first();

        $totalIncome = (float) ($totals->income_sum ?? 0);
        $totalExpenses = (float) ($totals->expense_sum ?? 0);

        $transferCategories = Transaction::query()
            ->whereIn('statement_id', $statementIds)
            ->where('type', 'transfer')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->map(fn ($category): string => mb_strtolower(trim((string) $category)))
            ->filter()
            ->values()
            ->all();

        $isUsable = fn (string $name): bool => $this->isUsableCategory($name, $transferCategories);

        $categoryRows = Transaction::query()
            ->whereIn('statement_id', $statementIds)
            ->whereIn('type', self::EXPENSE_TYPES)
            ->whereNotNull('category')
            ->select('category')
            ->selectRaw('SUM(ABS(amount)) AS amount')
            ->groupBy('category')
            ->orderByDesc('amount')
            ->get();

        $topCategories = [];
        foreach ($categoryRows as $row) {
            $name = trim((string) $row->category);

            if (! $isUsable($name)) {
                continue;
            }

            $topCategories[] = [
                'category' => $name,
                'amount' => (float) $row->amount,
                'percentage' => $totalExpenses > 0
                    ? round((float) $row->amount / $totalExpenses * 100, 2)
                    : 0.0,
            ];

            if (count($topCategories) >= 7) {
                break;
            }
        }

        $recipientRows = Transaction::query()
            ->whereIn('statement_id', $statementIds)
            ->where('type', 'transfer')
            ->whereNotNull('recipient')
            ->select('recipient')
            ->selectRaw('SUM(ABS(amount)) AS amount')
            ->selectRaw('COUNT(*) AS transactions_count')
            ->groupBy('recipient')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $topRecipients = $recipientRows->map(fn ($row): array => [
            'recipient' => (string) $row->recipient,
            'amount' => (float) $row->amount,
            'transactions_count' => (int) $row->transactions_count,
        ])->all();

        // Только insights своих выписок (тот же scope через whereIn).
        $recentInsights = AiInsight::query()
            ->whereIn('statement_id', $statementIds)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (AiInsight $insight): array => [
                'id' => $insight->id,
                'type' => $insight->type,
                'title' => $insight->title,
                'description' => $insight->description,
                'potential_saving' => $insight->potential_saving !== null
                    ? (float) $insight->potential_saving
                    : null,
                'created_at' => $insight->created_at?->toIso8601String(),
            ])
            ->all();

        $months = $this->coveredMonths($totals->min_date ?? null, $totals->max_date ?? null);

        return [
            'total_statements' => $statementIds->count(),
            'date_range' => [
                'from' => $totals->min_date,
                'to' => $totals->max_date,
            ],
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'balance' => $totalIncome - $totalExpenses,
            'top_categories' => array_values($topCategories),
            'top_recipients' => array_values($topRecipients),
            'average_monthly_expenses' => round($totalExpenses / $months, 2),
            'recent_insights' => array_values($recentInsights),
        ];
    }

    private function isUsableCategory(string $name, array $transferCategories): bool
    {
        $normalized = mb_strtolower(trim($name));

        return $normalized !== ''
            && ! in_array($normalized, InsightPersistenceService::EXCLUDED_CATEGORIES, true)
            && ! in_array($normalized, $transferCategories, true);
    }

    /**
     * Число покрытых календарных месяцев ВКЛЮЧИТЕЛЬНО
     * (2026-05-01..2026-09-23 → май, июнь, июль, август, сентябрь = 5).
     */
    private function coveredMonths(mixed $from, mixed $to): int
    {
        try {
            $start = CarbonImmutable::parse((string) $from)->startOfMonth();
            $end = CarbonImmutable::parse((string) $to)->startOfMonth();
        } catch (\Throwable) {
            return 1;
        }

        // diffInMonths() в Carbon 3 возвращает float. Приводить к int нужно
        // ЯВНО и ВНЕ try: раньше float просачивался в return при : int,
        // strict_types бросал TypeError, а catch ниже его глотал и тихо
        // возвращал дефолт 1 — месяцы схлопывались для любых данных.
        return max(1, (int) ($start->diffInMonths($end) + 1));
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(
            fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values
        ));
    }
}
