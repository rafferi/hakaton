<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Statement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Построение запроса списка транзакций выписки с фильтрацией и пагинацией.
 *
 * Принятые решения (зафиксированы здесь и в финальном отчёте):
 *
 * 1. Фильтр type — ТОЧНОЕ совпадение со значениями, реально хранящимися в БД
 *    (credit / debit / transfer). Алиасы НЕ маппятся: income НЕ превращается
 *    в credit, expense НЕ превращается в debit. Неизвестное значение даёт
 *    пустой результат (200), а не ошибку — как и любой другой фильтр без совпадений.
 * 2. Фильтр по сумме — через ABS(amount): границы amount_min / amount_max
 *    трактуются как величины (модули), т.к. расходы хранятся со знаком минус.
 *    ABS() поддерживается и PostgreSQL, и SQLite. Граница приводится через
 *    CAST(? AS NUMERIC): PDO биндит float как строку, а в SQLite сравнение
 *    числа с текстом всегда ложно (числа < текст), поэтому без CAST фильтр
 *    по сумме на SQLite не нашёл бы ничего.
 * 3. Поиск — регистронезависимый LIKE по normalized_description, merchant,
 *    recipient через LOWER() с обеих сторон: в PostgreSQL обычный LIKE
 *    чувствителен к регистру, поэтому LOWER() нужен для кросса-СУБД поведения.
 *    Символы %, _ и \ во вводе экранируются. Поиск по raw_description
 *    намеренно не включён — сырое описание дублирует нормализованное.
 * 4. Сортировка — только направление (asc/desc) по date, по умолчанию desc
 *    (новые сверху); вторичный ключ id для стабильности пагинации.
 * 5. Пустой результат (нет транзакций или ничего не прошло фильтр) —
 *    обычный пустой paginate-ответ (200), не ошибка.
 */
class TransactionQueryService
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $filters  Валидированные данные StatementTransactionsRequest.
     */
    public function paginate(Statement $statement, array $filters): LengthAwarePaginator
    {
        $query = $statement->transactions();

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (isset($filters['amount_min']) && $filters['amount_min'] !== null && $filters['amount_min'] !== '') {
            $query->whereRaw('ABS(amount) >= CAST(? AS NUMERIC)', [(float) $filters['amount_min']]);
        }

        if (isset($filters['amount_max']) && $filters['amount_max'] !== null && $filters['amount_max'] !== '') {
            $query->whereRaw('ABS(amount) <= CAST(? AS NUMERIC)', [(float) $filters['amount_max']]);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($nested) use ($filters): void {
                $term = '%'.$this->escapeLike(mb_strtolower((string) $filters['search'])).'%';

                $nested->whereRaw('LOWER(normalized_description) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(merchant) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(recipient) LIKE ?', [$term]);
            });
        }

        $direction = (($filters['sort'] ?? null) === 'asc') ? 'asc' : 'desc';

        return $query->orderBy('date', $direction)
            ->orderBy('id', $direction)
            ->paginate($this->perPage($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
