<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Statement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Эвристический детектор обязательных ежемесячных расходов.
 *
 * Чисто rule-based, без GigaChat: подписки, ЖКХ, аренда, кредиты
 * и связь определяются по повторяемости (merchant/recipient/category),
 * совпадению сумм и месячному интервалу 25–35 дней.
 *
 * Два SQL-запроса, без загрузки моделей в память и без N+1:
 * 1) GROUP BY (merchant, recipient, category) HAVING COUNT(*) >= 2 —
 *    кандидаты (агрегация в БД, работает и в pgsql, и в sqlite:
 *    никаких date_trunc/strftime здесь нет, только COUNT/AVG/MAX);
 * 2) один узкий SELECT (merchant, recipient, category, amount, date)
 *    только для троек-кандидатов — даты нужны для проверки
 *    месячных интервалов, что в GROUP BY портируемо не выражается.
 * Фильтрация — через Statement::forCurrentUser(), как в
 * ProfileAggregationService::buildMonthlyTrend().
 */
class MandatoryExpensesDetectorService
{
    private const MIN_OCCURRENCES = 2;

    private const MIN_GAP_DAYS = 25;

    private const MAX_GAP_DAYS = 35;

    private const SUBSCRIPTION_AMOUNT_TOLERANCE = 10.0;

    private const RENT_MIN_AMOUNT = 20000.0;

    private const RENT_AMOUNT_TOLERANCE = 100.0;

    private const UTILITY_CATEGORY_KEYWORDS = ['жкх', 'коммунальн'];

    private const UTILITY_MERCHANT_KEYWORDS = ['жкх', 'коммунальн', 'еирц', 'моэк', 'водоканал', 'энергосбыт'];

    private const LOAN_CATEGORY_KEYWORDS = ['кредит'];

    private const LOAN_MERCHANT_KEYWORDS = ['кредит', 'рассрочка', 'платёж по', 'банк'];

    private const COMMUNICATION_MERCHANT_KEYWORDS = ['мтс', 'билайн', 'мегафон', 'ростелеком', 'теле2'];

    /**
     * @return array{
     *     mandatory_expenses: list<array{type: string, category: string, merchant: string, average_amount: float, frequency: string, occurrences: int, last_date: string, monthly_total: float}>,
     *     total_monthly_mandatory: float,
     *     summary: array{subscriptions_count: int, subscriptions_total: float, utilities_total: float, rent_total: float, loans_total: float, communication_total: float}
     * }
     */
    public function detect(): array
    {
        $candidates = $this->candidateGroups();

        if ($candidates === []) {
            return $this->emptyResult();
        }

        $rows = $this->rowsForCandidates($candidates);

        // Раскладываем строки второго запроса по тем же тройкам-кандидатам.
        $buckets = [];
        foreach ($rows as $row) {
            $buckets[$this->tripleKey($row->merchant, $row->recipient, $row->category)][] = $row;
        }

        $items = [];
        foreach ($candidates as $candidate) {
            $key = $this->tripleKey($candidate->merchant, $candidate->recipient, $candidate->category);
            $item = $this->buildItem(
                $candidate->merchant,
                $candidate->recipient,
                $candidate->category,
                $buckets[$key] ?? []
            );

            if ($item !== null) {
                $items[] = $item;
            }
        }

        usort($items, fn (array $a, array $b): int => $b['monthly_total'] <=> $a['monthly_total']);

        $total = round(array_sum(array_column($items, 'monthly_total')), 2);

        return [
            'mandatory_expenses' => $items,
            'total_monthly_mandatory' => $total,
            'summary' => $this->summarize($items),
        ];
    }

    /**
     * Кандидаты: тройки (merchant, recipient, category) с минимум
     * двумя расходами. COUNT/AVG/MAX считаются в БД одним запросом.
     *
     * @return list<object{merchant: ?string, recipient: ?string, category: ?string, occurrences: int, average_amount: float, last_date: string}>
     */
    private function candidateGroups(): array
    {
        $rows = $this->scopedDebits()
            ->select('merchant', 'recipient', 'category')
            ->selectRaw('COUNT(*) AS occurrences')
            ->selectRaw('AVG(ABS(amount)) AS average_amount')
            ->selectRaw('MAX(date) AS last_date')
            ->groupBy('merchant', 'recipient', 'category')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_OCCURRENCES])
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = (object) [
                'merchant' => $row->merchant !== null ? (string) $row->merchant : null,
                'recipient' => $row->recipient !== null ? (string) $row->recipient : null,
                'category' => $row->category !== null ? (string) $row->category : null,
                'occurrences' => (int) $row->occurrences,
                'average_amount' => (float) $row->average_amount,
                'last_date' => (string) $row->last_date,
            ];
        }

        return $candidates;
    }

    /**
     * Узкие строки для проверки сумм и интервалов — одним запросом,
     * ограниченным тройками-кандидатами (OR-цепочка с null-safe
     * сравнением, портируема между pgsql и sqlite).
     *
     * @param  list<object{merchant: ?string, recipient: ?string, category: ?string}>  $candidates
     * @return list<object{merchant: ?string, recipient: ?string, category: ?string, amount: float, date: string}>
     */
    private function rowsForCandidates(array $candidates): array
    {
        $rows = $this->scopedDebits()
            ->where(function ($group) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    $group->orWhere(function ($nested) use ($candidate): void {
                        $this->applyTripleCondition($nested, 'merchant', $candidate->merchant);
                        $this->applyTripleCondition($nested, 'recipient', $candidate->recipient);
                        $this->applyTripleCondition($nested, 'category', $candidate->category);
                    });
                }
            })
            ->select('merchant', 'recipient', 'category', 'amount', 'date')
            ->orderBy('date')
            ->get();

        $narrow = [];

        foreach ($rows as $row) {
            $date = $this->normalizeDate((string) $row->date);

            if ($date === null) {
                continue;
            }

            $narrow[] = (object) [
                'merchant' => $row->merchant !== null ? (string) $row->merchant : null,
                'recipient' => $row->recipient !== null ? (string) $row->recipient : null,
                'category' => $row->category !== null ? (string) $row->category : null,
                'amount' => abs((float) $row->amount),
                'date' => $date,
            ];
        }

        return $narrow;
    }

    /**
     * Расходы текущего скоупа: type=debit через forCurrentUser()-подзапрос
     * (тот же приём, что в buildMonthlyTrend()).
     */
    private function scopedDebits(): QueryBuilder
    {
        return DB::table('transactions')
            ->whereIn('statement_id', Statement::forCurrentUser()->select('id'))
            ->where('type', 'debit');
    }

    /**
     * @param  mixed  $query  Query builder (Eloquent или вложенный where).
     */
    private function applyTripleCondition(mixed $query, string $column, ?string $value): void
    {
        if ($value === null) {
            $query->whereNull($column);
        } else {
            $query->where($column, $value);
        }
    }

    /**
     * Приводит сырое значение date-колонки к Y-m-d.
     *
     * Через Query Builder касты модели не применяются, а формат зависит
     * от драйвера: pgsql отдаёт 'Y-m-d', sqlite — 'Y-m-d H:i:s' (Eloquent
     * при записи сериализует date-каст с временем). Null при мусоре —
     * такая строка пропускается, интервалы по ней не считаются.
     */
    private function normalizeDate(string $value): ?string
    {
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function tripleKey(?string $merchant, ?string $recipient, ?string $category): string
    {
        return implode("\0", [
            mb_strtolower(trim((string) $merchant)),
            mb_strtolower(trim((string) $recipient)),
            mb_strtolower(trim((string) $category)),
        ]);
    }

    /**
     * Классификация тройки по приоритету: аренда → кредиты → ЖКХ →
     * связь → подписки. Порядок важен: специфичные ключевые слова
     * побеждают общий fallback "повторяющийся merchant".
     *
     * @return array{type: string, category: string}|null
     */
    private function classify(?string $merchant, ?string $recipient, ?string $category, float $averageAmount): ?array
    {
        $payee = $recipient ?? $merchant;

        if ($payee !== null && trim($payee) !== '' && $averageAmount > self::RENT_MIN_AMOUNT) {
            return ['type' => 'rent', 'category' => 'Жильё'];
        }

        if ($this->containsAny($category, self::LOAN_CATEGORY_KEYWORDS)
            || $this->containsAny($merchant, self::LOAN_MERCHANT_KEYWORDS)
        ) {
            return ['type' => 'loan', 'category' => 'Кредиты'];
        }

        if ($this->containsAny($category, self::UTILITY_CATEGORY_KEYWORDS)
            || $this->containsAny($merchant, self::UTILITY_MERCHANT_KEYWORDS)
        ) {
            return ['type' => 'utilities', 'category' => 'ЖКХ'];
        }

        if ($this->containsAny($merchant, self::COMMUNICATION_MERCHANT_KEYWORDS)) {
            return ['type' => 'communication', 'category' => 'Связь'];
        }

        if (mb_strtolower(trim((string) $category)) === 'подписки'
            || ($merchant !== null && trim($merchant) !== '')
        ) {
            return ['type' => 'subscription', 'category' => 'Подписки'];
        }

        return null;
    }

    /**
     * @param  list<string>  $keywords
     */
    private function containsAny(?string $haystack, array $keywords): bool
    {
        if ($haystack === null || trim($haystack) === '') {
            return false;
        }

        $normalized = mb_strtolower($haystack);

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверяет тройку-кандидата и строит пункт ответа.
     * Null — тройка не прошла эвристики (суммы/интервалы/тип).
     *
     * @param  list<object{amount: float, date: string}>  $rows  Уже отсортированы по date.
     * @return array{type: string, category: string, merchant: string, average_amount: float, frequency: string, occurrences: int, last_date: string, monthly_total: float}|null
     */
    private function buildItem(?string $merchant, ?string $recipient, ?string $category, array $rows): ?array
    {
        if (count($rows) < self::MIN_OCCURRENCES) {
            return null;
        }

        $amounts = array_map(fn ($row): float => $row->amount, $rows);
        $average = array_sum($amounts) / count($amounts);

        $classified = $this->classify($merchant, $recipient, $category, (float) $average);

        if ($classified === null) {
            return null;
        }

        // Допуск сумм — только там, где сумма по смыслу фиксирована.
        // ЖКХ и кредиты варьируются от месяца к месяцу — их не ограничиваем.
        $tolerance = match ($classified['type']) {
            'rent' => self::RENT_AMOUNT_TOLERANCE,
            'subscription', 'communication' => self::SUBSCRIPTION_AMOUNT_TOLERANCE,
            default => null,
        };

        if ($tolerance !== null && (max($amounts) - min($amounts)) > $tolerance) {
            return null;
        }

        $dates = array_map(fn ($row): string => $row->date, $rows);

        if (! $this->isMonthly($dates)) {
            return null;
        }

        $averageRounded = round((float) $average, 2);

        return [
            'type' => $classified['type'],
            'category' => $classified['category'],
            'merchant' => $merchant ?? $recipient ?? $category ?? '',
            'average_amount' => $averageRounded,
            'frequency' => 'monthly',
            'occurrences' => count($rows),
            'last_date' => max($dates),
            'monthly_total' => $averageRounded,
        ];
    }

    /**
     * Месячная регулярность: ВСЕ соседние интервалы — 25–35 дней.
     * Даты уже отсортированы (orderBy date во втором запросе).
     *
     * @param  list<string>  $dates  Y-m-d по возрастанию.
     */
    private function isMonthly(array $dates): bool
    {
        if (count($dates) < self::MIN_OCCURRENCES) {
            return false;
        }

        $previous = null;

        foreach ($dates as $date) {
            try {
                $current = CarbonImmutable::parse($date)->startOfDay();
            } catch (\Throwable) {
                return false;
            }

            if ($previous !== null) {
                // diffInDays() в Carbon 3 возвращает float — каст обязателен
                // (тот же урок, что в coveredMonths() профиля).
                $gap = (int) $previous->diffInDays($current);

                if ($gap < self::MIN_GAP_DAYS || $gap > self::MAX_GAP_DAYS) {
                    return false;
                }
            }

            $previous = $current;
        }

        return true;
    }

    /**
     * @param  list<array{type: string, monthly_total: float}>  $items
     * @return array{subscriptions_count: int, subscriptions_total: float, utilities_total: float, rent_total: float, loans_total: float, communication_total: float}
     */
    private function summarize(array $items): array
    {
        $summary = [
            'subscriptions_count' => 0,
            'subscriptions_total' => 0.0,
            'utilities_total' => 0.0,
            'rent_total' => 0.0,
            'loans_total' => 0.0,
            'communication_total' => 0.0,
        ];

        foreach ($items as $item) {
            $amount = round((float) $item['monthly_total'], 2);

            if ($item['type'] === 'subscription') {
                $summary['subscriptions_count']++;
                $summary['subscriptions_total'] = round($summary['subscriptions_total'] + $amount, 2);
            } elseif ($item['type'] === 'utilities') {
                $summary['utilities_total'] = round($summary['utilities_total'] + $amount, 2);
            } elseif ($item['type'] === 'rent') {
                $summary['rent_total'] = round($summary['rent_total'] + $amount, 2);
            } elseif ($item['type'] === 'loan') {
                $summary['loans_total'] = round($summary['loans_total'] + $amount, 2);
            } elseif ($item['type'] === 'communication') {
                $summary['communication_total'] = round($summary['communication_total'] + $amount, 2);
            }
        }

        return $summary;
    }

    /**
     * @return array{mandatory_expenses: list<never>, total_monthly_mandatory: float, summary: array{subscriptions_count: int, subscriptions_total: float, utilities_total: float, rent_total: float, loans_total: float, communication_total: float}}
     */
    private function emptyResult(): array
    {
        return [
            'mandatory_expenses' => [],
            'total_monthly_mandatory' => 0.0,
            'summary' => [
                'subscriptions_count' => 0,
                'subscriptions_total' => 0.0,
                'utilities_total' => 0.0,
                'rent_total' => 0.0,
                'loans_total' => 0.0,
                'communication_total' => 0.0,
            ],
        ];
    }
}
