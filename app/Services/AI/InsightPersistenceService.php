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
 *   data = {recommendation, priority, monthly_saving, annual_saving},
 *   potential_saving = monthly_saving.
 *
 * Мусор в БД не пишется: при любом несоответствии схеме бросается
 * AiServiceException(422) с деталями, сырой payload уходит в лог.
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
     * @return Collection<int, AiInsight>
     */
    public function persist(Statement $statement, array $payload): Collection
    {
        $rows = $this->validate($statement, $payload);

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
     * @return list<array<string, mixed>>
     */
    private function validate(Statement $statement, array $payload): array
    {
        $errors = [];

        if (! isset($payload['insights']) || ! is_array($payload['insights'])) {
            $errors[] = 'missing or invalid "insights" array';
        }

        if (! isset($payload['recommendations']) || ! is_array($payload['recommendations'])) {
            $errors[] = 'missing or invalid "recommendations" array';
        }

        if ($errors !== []) {
            $this->reject($statement, $payload, $errors);
        }

        /** @var list<array<string, mixed>> $insights */
        $insights = array_values($payload['insights']);
        /** @var list<array<string, mixed>> $recommendations */
        $recommendations = array_values($payload['recommendations']);

        $rows = [];

        foreach ($insights as $i => $insight) {
            $prefix = "insights[{$i}]";

            if (! is_array($insight)) {
                $errors[] = "{$prefix} must be an object";

                continue;
            }

            $type = $insight['type'] ?? null;
            $title = $insight['title'] ?? null;
            $description = $insight['description'] ?? null;

            if (! is_string($type) || ! in_array($type, self::INSIGHT_TYPES, true)) {
                $errors[] = "{$prefix}.type must be one of: ".implode(', ', self::INSIGHT_TYPES);
            }

            if (! is_string($title) || trim($title) === '') {
                $errors[] = "{$prefix}.title must be a non-empty string";
            } elseif (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
                $errors[] = "{$prefix}.title exceeds ".self::TITLE_MAX_LENGTH.' characters';
            }

            if (! is_string($description) || trim($description) === '') {
                $errors[] = "{$prefix}.description must be a non-empty string";
            }

            if ($errors !== []) {
                continue;
            }

            $rows[] = [
                'type' => $type,
                'title' => $title,
                'description' => $description,
                'data' => [],
                'potential_saving' => null,
            ];
        }

        foreach ($recommendations as $i => $rec) {
            $prefix = "recommendations[{$i}]";

            if (! is_array($rec)) {
                $errors[] = "{$prefix} must be an object";

                continue;
            }

            foreach (['problem', 'explanation', 'recommendation'] as $field) {
                $value = $rec[$field] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "{$prefix}.{$field} must be a non-empty string";
                }
            }

            $monthly = $rec['monthly_saving'] ?? null;
            $annual = $rec['annual_saving'] ?? null;

            if (! is_numeric($monthly) || (float) $monthly < 0) {
                $errors[] = "{$prefix}.monthly_saving must be a non-negative number";
            }

            if (! is_numeric($annual) || (float) $annual < 0) {
                $errors[] = "{$prefix}.annual_saving must be a non-negative number";
            }

            $priority = $rec['priority'] ?? null;
            if (! is_string($priority) || ! in_array($priority, self::PRIORITIES, true)) {
                $errors[] = "{$prefix}.priority must be one of: ".implode(', ', self::PRIORITIES);
            }

            $problem = $rec['problem'] ?? null;
            if (is_string($problem) && mb_strlen($problem) > self::TITLE_MAX_LENGTH) {
                $errors[] = "{$prefix}.problem exceeds ".self::TITLE_MAX_LENGTH.' characters';
            }

            if ($errors !== []) {
                continue;
            }

            $rows[] = [
                'type' => 'recommendation',
                'title' => $rec['problem'],
                'description' => $rec['explanation'],
                'data' => [
                    'recommendation' => $rec['recommendation'],
                    'priority' => $rec['priority'],
                    'monthly_saving' => (float) $monthly,
                    'annual_saving' => (float) $annual,
                ],
                'potential_saving' => (float) $monthly,
            ];
        }

        if ($errors !== []) {
            $this->reject($statement, $payload, $errors);
        }

        return $rows;
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
