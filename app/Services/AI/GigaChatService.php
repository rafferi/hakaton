<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Транспортный слой GigaChat API (Сбер).
 *
 * Схема сверена с официальной документацией developers.sber.ru (2026):
 * - токен: POST https://ngw.devices.sberbank.ru:9443/api/v2/oauth,
 *   заголовок Authorization: Basic <готовый Ключ авторизации из личного
 *   кабинета> (подставляется как есть, БЕЗ склейки/кодирования на нашей
 *   стороне; Client ID в запросе не используется вообще),
 *   обязательный заголовок RqUID (uuid4, новый на каждый запрос токена),
 *   scope (GIGACHAT_API_PERS/B2B/CORP) — form-urlencoded полем в ТЕЛЕ
 *   POST-запроса, ответ {"access_token": "...", "expires_at": <unix>},
 *   время жизни 30 минут;
 * - completions: POST https://api.giga.chat/v1/chat/completions (целевой URL
 *   для всех пользователей с 17.07.2026), Bearer-авторизация,
 *   тело {"model", "messages": [{role, content}], ...},
 *   ответ {"choices": [{"message": {"content", "role"}}]}.
 *
 * Токен кешируется на 25 минут (консервативно меньше контрактных 30),
 * поэтому повторные analyze() не ходят в OAuth на каждый вызов.
 * Лимиты/таймауты/невалидный JSON — через AiServiceException со статусом,
 * сырой ответ модели при ошибке парсинга пишется в лог (обрезанный).
 */
class GigaChatService
{
    private const TOKEN_CACHE_KEY = 'gigachat_access_token';

    private const TOKEN_CACHE_TTL_SECONDS = 1500;

    private const COMPLETION_MAX_TOKENS = 2000;

    private const COMPLETION_TEMPERATURE = 0.3;

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = config('services.gigachat', []);

        return $config;
    }

    public function getAccessToken(): string
    {
        $config = $this->config();
        $authKey = (string) ($config['auth_key'] ?? '');

        if ($authKey === '') {
            throw new AiServiceException(
                'GigaChat credentials are not configured. Set GIGACHAT_AUTH_KEY.',
                503
            );
        }

        /** @var string $token */
        $token = Cache::remember(
            self::TOKEN_CACHE_KEY,
            self::TOKEN_CACHE_TTL_SECONDS,
            fn (): string => $this->requestAccessToken($authKey, $config)
        );

        return $token;
    }

    public function forgetAccessToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /**
     * Проверка SSL-сертификата для запросов к GigaChat.
     *
     * false предназначен ТОЛЬКО для локальной разработки при проблемах
     * с сертификатом НУЦ Минцифры (cURL error 60). В реальном проде
     * должен быть true с правильно установленным корневым сертификатом
     * НУЦ, либо явное осознанное исключение — комбинация
     * production + false при каждом вызове пишется в лог как warning.
     */
    private function verifySsl(array $config): bool
    {
        $verify = (bool) ($config['verify_ssl'] ?? true);

        if (! $verify && app()->isProduction()) {
            Log::warning(
                'GigaChat SSL verification is DISABLED in production. '.
                'This is insecure: set GIGACHAT_VERIFY_SSL=true and install the Russian CA bundle.'
            );
        }

        return $verify;
    }

    private function requestAccessToken(string $authKey, array $config): string
    {
        $authUrl = (string) ($config['auth_url'] ?? '');
        $scope = (string) ($config['scope'] ?? 'GIGACHAT_API_PERS');
        $timeout = (int) ($config['timeout'] ?? 30);

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $this->verifySsl($config)])
                ->withHeaders([
                    'RqUID' => (string) Str::uuid(),
                    'Accept' => 'application/json',
                    // Готовый Ключ авторизации из личного кабинета — как есть,
                    // без Base64-склейки (withBasicAuth здесь использовать НЕЛЬЗЯ).
                    'Authorization' => 'Basic '.$authKey,
                ])
                ->asForm()
                ->post($authUrl, ['scope' => $scope]);
        } catch (ConnectionException $e) {
            Log::error('GigaChat OAuth connection failed', [
                'auth_url' => $authUrl,
                'error' => $e->getMessage(),
            ]);

            throw new AiServiceException(
                'GigaChat authorization server is unreachable: '.$e->getMessage(),
                503
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            Log::error('GigaChat OAuth authorization failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new AiServiceException(
                'GigaChat authorization failed (invalid Client ID / Client Secret or scope).',
                502
            );
        }

        if ($response->status() === 429) {
            Log::warning('GigaChat OAuth rate limit hit', ['status' => 429]);

            throw new AiServiceException(
                'GigaChat authorization rate limit exceeded, try again later.',
                429
            );
        }

        if (! $response->successful()) {
            Log::error('GigaChat OAuth unexpected status', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new AiServiceException(
                'GigaChat authorization failed with status '.$response->status().'.',
                502
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            Log::error('GigaChat OAuth response has no access_token', [
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new AiServiceException('GigaChat authorization returned no access token.', 502);
        }

        return $token;
    }

    /**
     * Отправляет аналитику в GigaChat и возвращает РАСПАРСЕННЫЙ массив.
     * Строгая валидация структуры — в InsightPersistenceService.
     *
     * @param  array<string, mixed>  $analyticsData  Результат AnalyticsService::analyze().
     * @param  array<string, mixed>|null  $previousPeriod  Итоги предыдущей выписки или null.
     * @return array<string, mixed>
     */
    public function analyze(array $analyticsData, ?array $previousPeriod = null): array
    {
        $config = $this->config();
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.giga.chat'), '/');
        $model = (string) ($config['model'] ?? 'GigaChat-2');
        $timeout = (int) ($config['timeout'] ?? 30);

        $token = $this->getAccessToken();

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $this->verifySsl($config)])
                ->acceptJson()
                ->withToken($token)
                ->post($baseUrl.'/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->userPrompt($analyticsData, $previousPeriod)],
                    ],
                    'temperature' => self::COMPLETION_TEMPERATURE,
                    'max_tokens' => self::COMPLETION_MAX_TOKENS,
                ]);
        } catch (ConnectionException $e) {
            Log::error('GigaChat completions connection failed', ['error' => $e->getMessage()]);

            throw new AiServiceException(
                'GigaChat API is unreachable: '.$e->getMessage(),
                503
            );
        }

        if ($response->status() === 401) {
            $this->forgetAccessToken();
            Log::error('GigaChat completions unauthorized, token cache cleared');

            throw new AiServiceException(
                'GigaChat access token was rejected, try again.',
                502
            );
        }

        if ($response->status() === 429) {
            Log::warning('GigaChat completions rate limit hit', ['status' => 429]);

            throw new AiServiceException(
                'GigaChat request rate limit exceeded, try again later.',
                429
            );
        }

        if (! $response->successful()) {
            Log::error('GigaChat completions unexpected status', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new AiServiceException(
                'GigaChat request failed with status '.$response->status().'.',
                502
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            Log::error('GigaChat completions empty content', [
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new AiServiceException('GigaChat returned an empty response.', 502);
        }

        $decoded = json_decode($this->stripCodeFences($content), true);

        if (! is_array($decoded)) {
            Log::error('GigaChat returned invalid JSON', [
                'raw' => mb_substr($content, 0, 2000),
            ]);

            throw new AiServiceException(
                'GigaChat returned a response that is not valid JSON.',
                422
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function stripCodeFences(string $content): string
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $content);
            $content = (string) preg_replace('/\s*```$/', '', $content);
        }

        return trim($content);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            Ты — финансовый аналитик. Анализируешь выписку пользователя и возвращаешь insights и рекомендации.

            Отвечай ТОЛЬКО валидным JSON без markdown-разметки, без ``` fences и без пояснений вне JSON.
            Строгая схема ответа:
            {
              "insights": [
                {"type": "рост_расходов|главная_категория|аномалия|подписка|временной_паттерн", "title": "краткий заголовок", "description": "объяснение простым языком с цифрами"}
              ],
              "recommendations": [
                {"problem": "краткая проблема", "explanation": "объяснение", "recommendation": "конкретный совет", "monthly_saving": 0, "annual_saving": 0, "priority": "high|medium|low"}
              ]
            }

            Правила:
            - "type" — ТОЛЬКО одно из пяти перечисленных значений, "priority" — ТОЛЬКО high|medium|low;
            - monthly_saving и annual_saving — числа (рубли в месяц/год), annual_saving = monthly_saving * 12;
            - опирайся только на переданные цифры, не выдумывай категории и суммы;
            - если данных для какого-то вывода нет — не выдумывай его, верни меньше пунктов.
            PROMPT;
    }

    /**
     * @param  array<string, mixed>  $analyticsData
     * @param  array<string, mixed>|null  $previousPeriod
     */
    private function userPrompt(array $analyticsData, ?array $previousPeriod): string
    {
        $totals = $analyticsData['totals'] ?? [];

        $lines = [
            'Данные выписки (суммы в рублях):',
            sprintf(
                '- доходы: %s, расходы: %s, баланс: %s, средний расход: %s, операций: %s',
                $this->num($totals['total_income'] ?? 0),
                $this->num($totals['total_expenses'] ?? 0),
                $this->num($totals['balance'] ?? 0),
                $this->num($totals['average_expense'] ?? 0),
                (int) ($totals['transactions_count'] ?? 0),
            ),
        ];

        $categories = $analyticsData['by_category'] ?? [];
        if (is_array($categories) && $categories !== []) {
            $lines[] = 'Категории:';
            foreach ($categories as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- %s: %s (%s%%, операций: %s)',
                    (string) ($row['category'] ?? '?'),
                    $this->num($row['amount'] ?? 0),
                    $this->num($row['percentage'] ?? 0),
                    (int) ($row['transaction_count'] ?? 0)
                );
            }
        }

        $recipients = $analyticsData['top_recipients'] ?? [];
        if (is_array($recipients) && $recipients !== []) {
            $lines[] = 'Топ получателей переводов:';
            foreach ($recipients as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- %s: %s (операций: %s)',
                    (string) ($row['recipient'] ?? '?'),
                    $this->num($row['amount'] ?? 0),
                    (int) ($row['transactions_count'] ?? 0)
                );
            }
        }

        $extras = $analyticsData['extras'] ?? [];
        if (is_array($extras)) {
            $lines[] = sprintf(
                'Траты в выходные: %s, в будни: %s, средние траты в день: %s.',
                $this->num($extras['weekend_spending'] ?? 0),
                $this->num($extras['weekday_spending'] ?? 0),
                $this->num($extras['average_daily_spending'] ?? 0)
            );
        }

        if ($previousPeriod !== null) {
            $lines[] = sprintf(
                'Предыдущий период (%s — %s): доходы %s, расходы %s, операций %s. Сравни текущий период с предыдущим в insights, где уместно.',
                (string) ($previousPeriod['period_from'] ?? '?'),
                (string) ($previousPeriod['period_to'] ?? '?'),
                $this->num($previousPeriod['total_income'] ?? 0),
                $this->num($previousPeriod['total_expenses'] ?? 0),
                (int) ($previousPeriod['transactions_count'] ?? 0)
            );
        } else {
            $lines[] = 'Данных за предыдущий период нет — сравнение периодов не делай.';
        }

        return implode("\n", $lines);
    }

    private function num(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
