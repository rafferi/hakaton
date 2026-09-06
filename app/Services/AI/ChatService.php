<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Тонкая оркестрация чата: профиль + история + вызов модели.
 * История обрезается до последних 10 сообщений здесь —
 * независимо от того, сколько прислал клиент.
 */
class ChatService
{
    private const HISTORY_LIMIT = 10;

    public function __construct(
        private ProfileAggregationService $profile,
        private GigaChatService $gigachat,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function reply(string $message, array $history = []): string
    {
        $profile = $this->profile->buildAggregatedProfile();

        $systemPrompt = 'Ты — персональный финансовый консультант в приложении FinBalance. '
            .'Вот агрегированные данные пользователя: '
            .json_encode($profile, JSON_UNESCAPED_UNICODE).'. '
            .'Отвечай ТОЛЬКО на основе этих данных, никогда не выдумывай цифры. '
            .'Если вопрос требует деталей, которых нет в сводке (единичная транзакция) — '
            .'честно скажи что у тебя только агрегированная сводка, предложи посмотреть таблицу транзакций. '
            .'Если вопрос не о финансах — вежливо верни разговор к финансам. '
            .'Отвечай кратко, по-русски, дружелюбно, как живой консультант.';

        $messages = [];

        foreach (array_slice($history, -self::HISTORY_LIMIT) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $role = $item['role'] ?? null;
            $content = $item['content'] ?? null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || trim($content) === '') {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        return $this->gigachat->chat($systemPrompt, $messages);
    }
}
