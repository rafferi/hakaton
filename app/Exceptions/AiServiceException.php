<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Доменная ошибка AI-слоя (GigaChat / персистентность инсайтов).
 *
 * Несёт HTTP-статус для ответа API, чтобы контроллер не возвращал
 * голый 500 без объяснения: 422 — мусор от модели, 502/503/429 —
 * проблемы транспорта, авторизации и лимитов.
 */
class AiServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 502,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
