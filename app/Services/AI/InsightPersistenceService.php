<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AiServiceException;
use App\Models\AiInsight;
use App\Models\Statement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Строгая валидация ответа модели и сохранение в ai_insights.
 *
 * Маппинг строк (зафиксирован здесь):
 * - insight: type/title/description — как есть; data = []; potential_saving = null;
 * - recommendation: type = 'recommendation', title = problem, description = explanation,
 *   data = {recommendation, priority, category, reduction_percentage,
 *     monthly_saving, annual_saving}, potential_saving = monthly_saving.
 *
 * Суммы экономии НЕ берутся из ответа модели, а считаются ДЕТЕРМИНИРОВАННО
 * backend'ом: monthly_saving = category_amount (реальная сумма категории
 * из analytics.by_category) * reduction_percentage / 100;
 * annual_saving = monthly_saving * 12. Так числа в БД всегда сходятся
 * с реальной аналитикой, а модель отвечает только за выбор категории
 * и оценку процента сокращения.
 * - reduction_percentage вне (0, 100] или отсутствует → дефолт 20 + Log::warning
 *   (ноль модель ставит как "не знаю" — такие экономии бесполезны);
 * - category не найдена в analytics → рекомендация ПРОПУСКАЕТСЯ (не сохраняем
 *   числа, которым не на что опереться) + Log::warning. Остальные пункты
 *   при этом сохраняются — один битый пункт не роняет весь анализ.
 *
 * Валидация ОТКАЗОУСТОЙЧИВАЯ на уровне пунктов: каждый insight и каждая
 * recommendation проверяются индивидуально; невалидный пункт пропускается
 * с Log::warning (полное содержимое — в лог), остальные сохраняются.
 * Различаются два исхода пустого результата: "модель предпочла ничего
 * не сообщить" (ключи есть, валидных пунктов 0 — возвращается пустой
 * список, API отдаёт 200) и "ответ нечитаем" (ключей insights/
 * recommendations нет вообще — AiServiceException(422), сырой payload
 * уходит в лог).
 * Сохранение — атомарно: старые строки выписки удаляются и заменяются
 * новыми в одной транзакции (повторный анализ с force не плодит дубли).
 */
class InsightPersistenceService
{
    private const INSIGHT_TYPES = [
        'рост_расходов',
        'главная_категория',
        'аномалия',
        'подписка',
        'временной_паттерн',
    ];

    private const PRIORITIES = ['high', 'medium', 'low'];

    /**
     * Категории-заглушки для неопознанных трат: рекомендации по ним
     * неинформативны ("сократи Прочее на 30%"), такие пункты пропускаются.
     * Сравнение — без учёта регистра. Публичная: переиспользуется
     * ProfileAggregationService для топ-категорий профиля.
     */
    public const EXCLUDED_CATEGORIES = ['прочее', 'other', 'переводы', 'перевод', 'transfer', 'transfers'];

    /**
     * Дефолт маркеров доходности на случай отсутствия config-секции.
     * Основной источник — config('categories.income'), см. isIncomeLike().
     */
    private const INCOME_KEYWORDS_FALLBACK = ['зарплата'];

    /**
     * Возможные альтернативные имена ключей верхнего уровня от модели.
     * Применяются ТОЛЬКО если канонического ключа нет; значение обязано
     * быть списком, иначе игнорируется.
     */
    private const KEY_ALIASES = [
        'insights' => ['observations', 'findings', 'notes'],
        'recommendations' => ['recommendation', 'advice', 'advices', 'tips', 'suggestions'],
    ];

    private const TITLE_MAX_LENGTH = 255;

    /**
     * @return Collection<int, AiInsight>
     */
    public function forStatement(Statement $statement): Collection
    {
        return AiInsight::query()
            ->where('statement_id', $statement->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload  Распарсенный ответ GigaChatService::analyze().
     * @param  array<string, mixed>  $analyticsData  Результат AnalyticsService::analyze() — источник сумм категорий.
     * @return Collection<int, AiInsight>
     */
    public function persist(Statement $statement, array $payload, array $analyticsData): Collection
    {
        $rows = $this->validate($statement, $payload, $analyticsData);

        return DB::transaction(function () use ($statement, $rows) {
            AiInsight::query()->where('statement_id', $statement->id)->delete();

            $saved = [];
            foreach ($rows as $row) {
                $saved[] = AiInsight::create(array_merge(
                    ['statement_id' => $statement->id],
                    $row
                ));
            }

            /** @var Collection<int, AiInsight> $collection */
            $collection = new Collection($saved);

            return $collection;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $analyticsData
     * @return list<array<string, mixed>>
     */
    private function validate(Statement $statement, array $payload, array $analyticsData): array
    {
        // Различаем "модель предпочла ничего не сообщить" (200 с пустым
        // списком) и "ответ нечитаем" (422): пустой объект {} — тоже
        // "нечего сообщить" (реальный кейс из логов), и он ОБЯЗАН пройти
        // через fallback-логику ниже, а не возвращаться досрочно —
        // иначе детерминированные фолбэки на таком ответе не срабатывают.
        // 422 — только если ключи insights/recommendations отсутствуют
        // при НЕПУСТОМ объекте (структура чужая — ошибка).
        if ($payload === []) {
            Log::info('GigaChat response is an empty object, treated as empty analysis', [
                'statement_id' => $statement->id,
            ]);
        } else {
            $payload = $this->applyKeyAliases($statement, $payload);

            if (! array_key_exists('insights', $payload) && ! array_key_exists('recommendations', $payload)) {
                $this->reject($statement, $payload, ['response has neither "insights" nor "recommendations"']);
            }
        }

        $insights = $this->asList($statement, $payload, 'insights');
        $recommendations = $this->asList($statement, $payload, 'recommendations');

        $rows = [];

        foreach ($insights as $i => $insight) {
            $row = $this->validateInsight($statement, $i, $insight);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($recommendations as $i => $rec) {
            $row = $this->validateRecommendation($statement, $i, $rec, $analyticsData);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // Детерминированные fallback'и: модель систематически возвращает пустые
        // insights (ей проще пропустить их, чем подобрать тип из enum), поэтому
        // при нуле валидных пунктов синтезируем backend'ом — строго
        // из реальных цифр analytics, без единой выдуманной цифры.
        $hasInsight = false;
        $hasRecommendation = false;
        foreach ($rows as $row) {
            if (($row['type'] ?? null) === 'recommendation') {
                $hasRecommendation = true;
            } else {
                $hasInsight = true;
            }
        }

        // Один запрос ранжирования на оба fallback'а: insight берёт топ-1,
        // recommendation — топ-2 (другую категорию), чтобы не дублировать
        // один и тот же факт в двух карточках. Если годная категория одна —
        // оба пункта строятся на ней (разные тексты: наблюдение vs совет).
        $ranking = (! $hasInsight || ! $hasRecommendation)
            ? $this->usableExpenseCategories($statement, $analyticsData)
            : [];

        $insightCategory = null;

        if (! $hasInsight) {
            $fallback = $this->fallbackInsight($statement, $ranking);

            if ($fallback !== null) {
                $rows[] = $fallback['row'];
                $insightCategory = $fallback['category'];
            } else {
                Log::warning('GigaChat fallback found no usable expense category for insight', [
                    'statement_id' => $statement->id,
                ]);
            }
        }

        if (! $hasRecommendation) {
            $fallback = $this->fallbackRecommendation($statement, $ranking, $insightCategory);

            if ($fallback !== null) {
                $rows[] = $fallback;
            } else {
                Log::warning('GigaChat fallback found no usable expense category for recommendation', [
                    'statement_id' => $statement->id,
                ]);
            }
        }

        if ($rows === []) {
            Log::info('GigaChat analysis is empty: model returned no valid items', [
                'statement_id' => $statement->id,
            ]);
        }

        return $rows;
    }

    /**
     * Упорядоченный рейтинг usable расходных категорий (убывание суммы).
     *
     * Tier-1: РЕАЛЬНЫЕ debit/expense-транзакции из БД (тип надёжнее имён);
     * процент — доля от totals.total_expenses. Отдельного expense-only
     * метода в AnalyticsService нет (by_category смешивает типы), поэтому
     * запрос здесь — один GROUP BY по индексированным колонкам.
     * Tier-2 (если debit-строк нет вообще — данные вида id=26, где траты
     * лежат с type=credit): by_category минус static/dynamic-исключения
     * минус income-ключевики; процент тогда оборотный, из самой строки.
     *
     * Публичный: переиспользуется SavingsPlanService для плана "Хочу экономить X".
     *
     * @param  array<string, mixed>  $analyticsData
     * @return list<array{name: string, amount: float, percentage: float|null}>
     */
    public function usableExpenseCategories(Statement $statement, array $analyticsData): array
    {
        $transferCategories = $this->transferCategories($statement);

        $isUsable = fn (string $name): bool => $this->isUsableCategory($name, $transferCategories);

        $totalExpenses = (float) ($analyticsData['totals']['total_expenses'] ?? 0);

        $groups = $statement->transactions()
            ->whereIn('type', ['expense', 'debit'])
            ->whereNotNull('category')
            ->select('category')
            ->selectRaw('SUM(ABS(amount)) AS amount')
            ->groupBy('category')
            ->orderByDesc('amount')
            ->get();

        $ranking = [];

        foreach ($groups as $group) {
            $name = trim((string) $group->category);
            $amount = (float) $group->amount;

            if ($amount <= 0 || ! $isUsable($name)) {
                continue;
            }

            $ranking[] = [
                'name' => $name,
                'amount' => $amount,
                'percentage' => $totalExpenses > 0 ? round($amount / $totalExpenses * 100, 2) : null,
            ];
        }

        if ($ranking !== []) {
            return $ranking;
        }

        $rows = $analyticsData['by_category'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['category'], $row['amount'])) {
                continue;
            }

            $name = trim((string) $row['category']);

            if (! is_numeric($row['amount']) || (float) $row['amount'] <= 0
                || ! $isUsable($name)
                || $this->isIncomeLike($name)
            ) {
                continue;
            }

            $ranking[] = [
                'name' => $name,
                'amount' => (float) $row['amount'],
                'percentage' => isset($row['percentage']) && is_numeric($row['percentage'])
                    ? (float) $row['percentage']
                    : null,
            ];
        }

        usort($ranking, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $ranking;
    }

    private function isUsableCategory(string $name, array $transferCategories): bool
    {
        $normalized = mb_strtolower(trim($name));

        return $normalized !== ''
            && ! in_array($normalized, self::EXCLUDED_CATEGORIES, true)
            && ! in_array($normalized, $transferCategories, true);
    }

    /**
     * Доходна ли категория: точное имя из config income.categories
     * ИЛИ подстрока-маркер из config income.keywords (как в категоризаторе).
     * Единый источник — config/categories.php, секция 'income'.
     */
    private function isIncomeLike(string $name): bool
    {
        $normalized = mb_strtolower(trim($name));

        /** @var list<string> $incomeNames */
        $incomeNames = config('categories.income.categories', []);
        foreach ($incomeNames as $incomeName) {
            if ($normalized === mb_strtolower(trim((string) $incomeName))) {
                return true;
            }
        }

        /** @var list<string> $keywords */
        $keywords = config('categories.income.keywords', self::INCOME_KEYWORDS_FALLBACK);
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{name: string, amount: float, percentage: float|null}>  $ranking
     * @return array{row: array<string, mixed>, category: string}|null
     */
    private function fallbackInsight(Statement $statement, array $ranking): ?array
    {
        if ($ranking === []) {
            return null;
        }

        $top = $ranking[0];
        $amountFormatted = $this->formatMoney($top['amount']);

        Log::info('GigaChat fallback insight synthesized', [
            'statement_id' => $statement->id,
            'category' => $top['name'],
        ]);

        return [
            'row' => [
                'type' => 'главная_категория',
                'title' => "Главная категория трат: {$top['name']}",
                'description' => $top['percentage'] !== null
                    ? "{$top['name']} — {$amountFormatted} ({$this->formatPercent($top['percentage'])}% расходов)."
                    : "{$top['name']} — {$amountFormatted}",
                'data' => [],
                'potential_saving' => null,
            ],
            'category' => $top['name'],
        ];
    }

    /**
     * Русское написание суммы для человекочитаемых fallback-строк:
     * пробел как разделитель тысяч, знак ₽, дробная часть — только
     * значимая (2994.00 → «2 994 ₽», 898.20 → «898,2 ₽»).
     * Только форматирование: формулы и валидация не тронуты.
     */
    private function formatMoney(float $value): string
    {
        $formatted = number_format($value, 2, ',', ' ');
        $formatted = (string) preg_replace('/,00$/', '', $formatted);
        $formatted = (string) preg_replace('/(,\d)0$/', '$1', $formatted);

        return $formatted.' ₽';
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Симметричный fallback для recommendations: топ-2 usable-категория
     * (другая, нежели у insight), иначе топ-1; дефолтный процент 20,
     * суммы — по существующей формуле. Null, если usable-категорий нет.
     *
     * @param  list<array{name: string, amount: float, percentage: float|null}>  $ranking
     * @return array<string, mixed>|null
     */
    private function fallbackRecommendation(Statement $statement, array $ranking, ?string $exceptCategory): ?array
    {
        $best = null;

        foreach ($ranking as $entry) {
            if ($exceptCategory === null
                || mb_strtolower(trim($entry['name'])) !== mb_strtolower(trim($exceptCategory))
            ) {
                $best = $entry;

                break;
            }
        }

        $best ??= $ranking[0] ?? null;

        if ($best === null) {
            return null;
        }

        $percentage = 20.0;
        $monthly = round($best['amount'] * ($percentage / 100), 2);
        $annual = round($monthly * 12, 2);
        $amountFormatted = $this->formatMoney($best['amount']);

        Log::info('GigaChat fallback recommendation synthesized', [
            'statement_id' => $statement->id,
            'category' => $best['name'],
        ]);

        return [
            'type' => 'recommendation',
            'title' => "Высокие траты на {$best['name']}",
            'description' => "{$best['name']} — {$amountFormatted}",
            'data' => [
                'recommendation' => "Проанализируйте траты в категории «{$best['name']}» и сократите необязательные покупки.",
                'priority' => 'medium',
                'category' => $best['name'],
                'reduction_percentage' => $percentage,
                'monthly_saving' => $monthly,
                'annual_saving' => $annual,
            ],
            'potential_saving' => $monthly,
        ];
    }

    /**
     * Подменяет отсутствующие канонические ключи найденными алиасами.
     * Логирует каждую подмену — видно, какой нейминг прислала модель.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyKeyAliases(Statement $statement, array $payload): array
    {
        foreach (self::KEY_ALIASES as $canonical => $aliases) {
            if (array_key_exists($canonical, $payload)) {
                continue;
            }

            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $payload) && is_array($payload[$alias])) {
                    Log::info("GigaChat payload uses alias key \"{$alias}\" instead of \"{$canonical}\"", [
                        'statement_id' => $statement->id,
                    ]);
                    $payload[$canonical] = $payload[$alias];

                    break;
                }
            }
        }

        return $payload;
    }

    /**
     * Верхнеуровневый ключ приводим к списку: отсутствует — пустой список,
     * присутствует, но не массив — warning и пустой список.
     *
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function asList(Statement $statement, array $payload, string $key): array
    {
        if (! isset($payload[$key])) {
            return [];
        }

        if (! is_array($payload[$key])) {
            Log::warning("GigaChat payload key \"{$key}\" is not an array, ignored", [
                'statement_id' => $statement->id,
            ]);

            return [];
        }

        return array_values($payload[$key]);
    }

    /**
     * Валидация одного insight. Невалидный пункт — warning с полным
     * содержимым и пропуск, остальные пункты не страдают.
     *
     * @return array<string, mixed>|null
     */
    private function validateInsight(Statement $statement, int $i, mixed $insight): ?array
    {
        $prefix = "insights[{$i}]";
        $reasons = [];

        if (! is_array($insight)) {
            $reasons[] = "{$prefix} must be an object";
        } else {
            $type = $insight['type'] ?? null;
            $title = $insight['title'] ?? null;
            $description = $insight['description'] ?? null;

            if (! is_string($type) || ! in_array($type, self::INSIGHT_TYPES, true)) {
                $reasons[] = "{$prefix}.type must be one of: ".implode(', ', self::INSIGHT_TYPES);
            }

            if (! is_string($title) || trim($title) === '') {
                $reasons[] = "{$prefix}.title must be a non-empty string";
            } elseif (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
                $reasons[] = "{$prefix}.title exceeds ".self::TITLE_MAX_LENGTH.' characters';
            }

            if (! is_string($description) || trim($description) === '') {
                $reasons[] = "{$prefix}.description must be a non-empty string";
            }
        }

        if ($reasons !== []) {
            Log::warning('GigaChat insight skipped', [
                'statement_id' => $statement->id,
                'reasons' => $reasons,
                'item' => $insight,
            ]);

            return null;
        }

        /** @var array<string, mixed> $insight */
        return [
            'type' => $insight['type'],
            'title' => $insight['title'],
            'description' => $insight['description'],
            'data' => [],
            'potential_saving' => null,
        ];
    }

    /**
     * Валидация одной recommendation. Невалидный пункт — warning с полным
     * содержимым и пропуск. Суммы считаются детерминированно backend'ом.
     *
     * @param  array<string, mixed>  $analyticsData
     * @return array<string, mixed>|null
     */
    private function validateRecommendation(Statement $statement, int $i, mixed $rec, array $analyticsData): ?array
    {
        $prefix = "recommendations[{$i}]";
        $reasons = [];

        if (! is_array($rec)) {
            $reasons[] = "{$prefix} must be an object";
        } else {
            foreach (['problem', 'explanation', 'recommendation'] as $field) {
                $value = $rec[$field] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    $reasons[] = "{$prefix}.{$field} must be a non-empty string";
                }
            }

            $categoryName = $rec['category'] ?? null;
            if (! is_string($categoryName) || trim($categoryName) === '') {
                $reasons[] = "{$prefix}.category must be a non-empty string";
            }

            $priority = $rec['priority'] ?? null;
            if (! is_string($priority) || ! in_array($priority, self::PRIORITIES, true)) {
                $reasons[] = "{$prefix}.priority must be one of: ".implode(', ', self::PRIORITIES);
            }

            $problem = $rec['problem'] ?? null;
            if (is_string($problem) && mb_strlen($problem) > self::TITLE_MAX_LENGTH) {
                $reasons[] = "{$prefix}.problem exceeds ".self::TITLE_MAX_LENGTH.' characters';
            }
        }

        if ($reasons !== []) {
            Log::warning('GigaChat recommendation skipped', [
                'statement_id' => $statement->id,
                'reasons' => $reasons,
                'item' => $rec,
            ]);

            return null;
        }

        /** @var array<string, mixed> $rec */
        /** @var string $categoryName */
        $categoryName = $rec['category'];

        // Статика (мусорные/нетранзакционные категории) + динамика по ТИПУ:
        // категории, в которых у этой выписки есть transfer-транзакции,
        // отлавливают и вариативные названия ("Перевод другу", "P2P").
        $normalizedCategory = mb_strtolower(trim($categoryName));

        if (in_array($normalizedCategory, self::EXCLUDED_CATEGORIES, true)
            || in_array($normalizedCategory, $this->transferCategories($statement), true)
        ) {
            Log::info("Recommendation for category '{$categoryName}' rejected as uninformative", [
                'statement_id' => $statement->id,
                'category' => $categoryName,
            ]);

            return null;
        }

        $categoryAmount = $this->categoryAmount($analyticsData, $categoryName);

        if ($categoryAmount === null) {
            Log::warning('GigaChat recommendation skipped: category not found in analytics', [
                'statement_id' => $statement->id,
                'category' => $categoryName,
            ]);

            return null;
        }

        $percentage = $rec['reduction_percentage'] ?? null;

        // Ноль тоже невалиден: рекомендация "сократить" на 0% противоречива,
        // модель так кодирует "не знаю" — подменяем дефолтом, иначе в БД
        // оседают бесполезные нулевые экономии.
        if (! is_numeric($percentage) || (float) $percentage <= 0 || (float) $percentage > 100) {
            Log::warning('GigaChat recommendation uses default reduction_percentage=20', [
                'statement_id' => $statement->id,
                'category' => $categoryName,
                'received' => $percentage,
            ]);
            $percentage = 20;
        }

        $monthly = round($categoryAmount * ((float) $percentage / 100), 2);
        $annual = round($monthly * 12, 2);

        return [
            'type' => 'recommendation',
            'title' => $rec['problem'],
            'description' => $rec['explanation'],
            'data' => [
                'recommendation' => $rec['recommendation'],
                'priority' => $rec['priority'],
                'category' => $categoryName,
                'reduction_percentage' => (float) $percentage,
                'monthly_saving' => $monthly,
                'annual_saving' => $annual,
            ],
            'potential_saving' => $monthly,
        ];
    }

    /**
     * Категории transfer-транзакций выписки (нижний регистр, без дублей).
     * Единый источник правды: используется и guard'ом выше, и скрытием
     * категорий из промпта в AiAnalysisService — названия вида
     * "Перевод другу" ловятся здесь, а не только статическим списком.
     *
     * @return list<string>
     */
    public function transferCategories(Statement $statement): array
    {
        return $statement->transactions()
            ->where('type', 'transfer')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->map(fn ($category): string => mb_strtolower(trim((string) $category)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Реальная сумма категории из analytics.by_category.
     * Сравнение нечувствительно к регистру и крайним пробелам —
     * модель просили копировать дословно, но не роняем пункт из-за регистра.
     *
     * @param  array<string, mixed>  $analyticsData
     */
    private function categoryAmount(array $analyticsData, string $category): ?float
    {
        $wanted = mb_strtolower(trim($category));

        $rows = $analyticsData['by_category'] ?? [];

        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['category'], $row['amount'])) {
                continue;
            }

            if (mb_strtolower(trim((string) $row['category'])) === $wanted) {
                return (float) $row['amount'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $errors
     * @return never
     */
    private function reject(Statement $statement, array $payload, array $errors): void
    {
        Log::error('GigaChat payload failed strict validation, nothing saved', [
            'statement_id' => $statement->id,
            'errors' => $errors,
            'raw' => mb_substr((string) json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 2000),
        ]);

        throw new AiServiceException(
            'GigaChat response did not match the required schema: '.implode('; ', $errors),
            422
        );
    }
}
