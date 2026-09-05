<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Statement;
use Illuminate\Support\Facades\DB;

/**
 * Расчёт аналитики по выписке без AI — только SQL-агрегация.
 *
 * Принятые решения по неоднозначностям ТЗ (зафиксированы здесь и в финальном отчёте):
 *
 * 1. Типы транзакций. CsvStatementParser реально пишет 'credit' / 'debit' / 'transfer',
 *    а не 'income' / 'expense' из описания. Сервис поддерживает оба варианта:
 *    доход = income|credit, расход = expense|debit. Переводы (transfer) в
 *    total_income / total_expenses НЕ входят, чтобы не задваивать обороты.
 * 2. Денежная конвенция. Все объёмные показатели — положительные величины
 *    (расходы через ABS(amount), т.к. в БД расходы хранятся со знаком минус).
 *    Знаковый только balance (income - expenses) и balance в timeline.
 * 3. by_category строится по ВСЕМ транзакциям (включая доходы и переводы),
 *    amount категории = SUM(ABS(amount)), percentage считается от суммарного
 *    оборота (суммы amount всех категорий), поэтому проценты в сумме дают ~100%.
 * 4. timeline.balance — НАКОПИТЕЛЬНЫЙ (running total: сумма дневных
 *    income - expenses нарастающим итогом от первой даты). Дневные агрегаты
 *    берутся одним SQL-запросом с GROUP BY date, накопление идёт по нескольким
 *    строкам-датам (не по транзакциям), сами транзакции в память не грузятся.
 * 5. top_recipients: amount = SUM(ABS(amount)) — объём по получателю.
 * 6. weekend = суббота + воскресенье (ISO 6, 7). DOW-выражение выбирается
 *    по драйверу БД: EXTRACT(ISODOW ...) для pgsql, strftime('%w', ...) для sqlite,
 *    чтобы работало и в проде (PostgreSQL), и в тестах (sqlite :memory:).
 * 7. average_daily_spending = total_expenses / число distinct дат с транзакциями.
 * 8. average_expense = total_expenses / число расходных транзакций.
 * 9. largest_transaction — максимум по ABS(amount) среди ВСЕХ транзакций
 *    (может быть доходом, например зарплатой); description =
 *    normalized_description ?? raw_description.
 * 10. Пустая выписка: нули, пустые массивы, largest_transaction = null — без ошибки.
 */
class AnalyticsService
{
    private const INCOME_TYPES = ['income', 'credit'];

    private const EXPENSE_TYPES = ['expense', 'debit'];

    /**
     * @return array{
     *     totals: array{total_income: float, total_expenses: float, balance: float, average_expense: float, transactions_count: int},
     *     by_category: list<array{category: string, amount: float, percentage: float, transaction_count: int}>,
     *     timeline: list<array{date: string, income: float, expenses: float, balance: float}>,
     *     top_recipients: list<array{recipient: string, amount: float, transactions_count: int}>,
     *     extras: array{weekend_spending: float, weekday_spending: float, average_daily_spending: float, largest_transaction: ?array{amount: float, description: ?string, date: ?string, category: ?string}},
     * }
     */
    public function analyze(Statement $statement): array
    {
        $totals = $this->totals($statement);

        return [
            'totals' => $totals,
            'by_category' => $this->byCategory($statement),
            'timeline' => $this->timeline($statement),
            'top_recipients' => $this->topRecipients($statement),
            'extras' => $this->extras($statement),
        ];
    }

    /**
     * @return array{total_income: float, total_expenses: float, balance: float, average_expense: float, transactions_count: int}
     */
    private function totals(Statement $statement): array
    {
        $row = $statement->transactions()
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END) AS income_sum', self::INCOME_TYPES)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN ABS(amount) ELSE 0 END) AS expense_sum', self::EXPENSE_TYPES)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END) AS expense_cnt', self::EXPENSE_TYPES)
            ->selectRaw('COUNT(*) AS total_cnt')
            ->first();

        $totalIncome = (float) ($row->income_sum ?? 0);
        $totalExpenses = (float) ($row->expense_sum ?? 0);
        $expenseCount = (int) ($row->expense_cnt ?? 0);

        return [
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'balance' => $totalIncome - $totalExpenses,
            'average_expense' => $expenseCount > 0 ? $totalExpenses / $expenseCount : 0.0,
            'transactions_count' => (int) ($row->total_cnt ?? 0),
        ];
    }

    /**
     * @return list<array{category: string, amount: float, percentage: float, transaction_count: int}>
     */
    private function byCategory(Statement $statement): array
    {
        $rows = $statement->transactions()
            ->select('category')
            ->selectRaw('SUM(ABS(amount)) AS amount')
            ->selectRaw('COUNT(*) AS transaction_count')
            ->groupBy('category')
            ->orderByDesc('amount')
            ->get();

        $turnover = (float) $rows->sum('amount');

        return array_values($rows->map(fn ($row): array => [
            'category' => $row->category ?? 'Прочее',
            'amount' => (float) $row->amount,
            'percentage' => $turnover > 0 ? round(((float) $row->amount / $turnover) * 100, 2) : 0.0,
            'transaction_count' => (int) $row->transaction_count,
        ])->all());
    }

    /**
     * Дневные income/expenses — одним SQL-запросом, balance — накопительный итог.
     *
     * @return list<array{date: string, income: float, expenses: float, balance: float}>
     */
    private function timeline(Statement $statement): array
    {
        $days = $statement->transactions()
            ->select('date')
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END) AS income', self::INCOME_TYPES)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN ABS(amount) ELSE 0 END) AS expenses', self::EXPENSE_TYPES)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $runningBalance = 0.0;
        $timeline = [];

        foreach ($days as $day) {
            $income = (float) $day->income;
            $expenses = (float) $day->expenses;
            $runningBalance += $income - $expenses;

            $timeline[] = [
                'date' => $day->date instanceof \DateTimeInterface
                    ? $day->date->format('Y-m-d')
                    : (string) $day->date,
                'income' => $income,
                'expenses' => $expenses,
                'balance' => $runningBalance,
            ];
        }

        return $timeline;
    }

    /**
     * @return list<array{recipient: string, amount: float, transactions_count: int}>
     */
    private function topRecipients(Statement $statement): array
    {
        $rows = $statement->transactions()
            ->whereNotNull('recipient')
            ->select('recipient')
            ->selectRaw('SUM(ABS(amount)) AS amount')
            ->selectRaw('COUNT(*) AS transactions_count')
            ->groupBy('recipient')
            ->orderByDesc('amount')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'recipient' => (string) $row->recipient,
                'amount' => (float) $row->amount,
                'transactions_count' => (int) $row->transactions_count,
            ])
            ->all();

        return array_values($rows);
    }

    /**
     * @return array{weekend_spending: float, weekday_spending: float, average_daily_spending: float, largest_transaction: ?array{amount: float, description: ?string, date: ?string, category: ?string}}
     */
    private function extras(Statement $statement): array
    {
        $weekendCondition = $this->weekendCondition();

        $row = $statement->transactions()
            ->selectRaw("SUM(CASE WHEN ({$weekendCondition}) AND type IN (?, ?) THEN ABS(amount) ELSE 0 END) AS weekend_sum", self::EXPENSE_TYPES)
            ->selectRaw("SUM(CASE WHEN NOT ({$weekendCondition}) AND type IN (?, ?) THEN ABS(amount) ELSE 0 END) AS weekday_sum", self::EXPENSE_TYPES)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN ABS(amount) ELSE 0 END) AS expense_sum', self::EXPENSE_TYPES)
            ->selectRaw('COUNT(DISTINCT date) AS active_days')
            ->first();

        $weekendSpending = (float) ($row->weekend_sum ?? 0);
        $weekdaySpending = (float) ($row->weekday_sum ?? 0);
        $expenseSum = (float) ($row->expense_sum ?? 0);
        $activeDays = (int) ($row->active_days ?? 0);

        $largest = $statement->transactions()
            ->orderByRaw('ABS(amount) DESC')
            ->first(['amount', 'normalized_description', 'raw_description', 'date', 'category']);

        return [
            'weekend_spending' => $weekendSpending,
            'weekday_spending' => $weekdaySpending,
            'average_daily_spending' => $activeDays > 0 ? $expenseSum / $activeDays : 0.0,
            'largest_transaction' => $largest === null ? null : [
                'amount' => abs((float) $largest->amount),
                'description' => $largest->normalized_description ?? $largest->raw_description,
                'date' => $largest->date instanceof \DateTimeInterface
                    ? $largest->date->format('Y-m-d')
                    : ($largest->date !== null ? (string) $largest->date : null),
                'category' => $largest->category,
            ],
        ];
    }

    /**
     * Условие "дата выпадает на выходной (сб/вс)" с учётом драйвера БД.
     */
    private function weekendCondition(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return 'EXTRACT(ISODOW FROM date) IN (6, 7)';
        }

        return "CAST(strftime('%w', date) AS INTEGER) IN (0, 6)";
    }
}
