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

    private const DISTRIBUTION_MAX_TOKENS = 1000;

    private const CHAT_MAX_TOKENS = 800;

    private const CHAT_TEMPERATURE = 0.55;

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
     * @param  list<string>  $hiddenCategories  Категории, скрытые из промпта
     *                                          (transfer-категории выписки — модель вообще не видит их как кандидатов).
     * @return array<string, mixed>
     */
    public function analyze(array $analyticsData, ?array $previousPeriod = null, array $hiddenCategories = []): array
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
                        ['role' => 'user', 'content' => $this->userPrompt($analyticsData, $previousPeriod, $hiddenCategories)],
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

        // Сырой ответ модели — ДО парсинга: при следующей проблеме с форматом
        // сразу видно, что именно прислала модель, без нового живого вызова.
        Log::info('GigaChat raw completions content', [
            'raw' => mb_substr($content, 0, 4000),
        ]);

        $decoded = $this->extractJson($content);

        if ($decoded === null) {
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

    /**
     * Просит модель распределить target экономии по категориям.
     * Короткий промпт (урок 20/20 пустых ответов при перегруженном):
     * схема + 3 правила, без избыточных запретов.
     * Пустой/невалидный ответ — [] (оркестратор уйдёт в fallback);
     * не-JSON — AiServiceException 422. Один retry при пустоте.
     *
     * @param  list<array{category: string, amount: float}>  $categories
     * @return list<array{category: string, reduction_percentage: float}>
     */
    public function suggestSavingsDistribution(array $categories, float $target): array
    {
        $config = $this->config();
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.giga.chat'), '/');
        $model = (string) ($config['model'] ?? 'GigaChat-2');
        $timeout = (int) ($config['timeout'] ?? 30);
        $token = $this->getAccessToken();

        $lines = ['Категории трат (рубли):'];
        foreach ($categories as $row) {
            $lines[] = sprintf('- %s: %s', (string) ($row['category'] ?? '?'), $this->num($row['amount'] ?? 0));
        }
        $lines[] = sprintf('Целевая месячная экономия: %s.', $this->num($target));

        $messages = [
            ['role' => 'system', 'content' => $this->distributionSystemPrompt()],
            ['role' => 'user', 'content' => implode("\n", $lines)],
        ];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->withOptions(['verify' => $this->verifySsl($config)])
                    ->acceptJson()
                    ->withToken($token)
                    ->post($baseUrl.'/v1/chat/completions', [
                        'model' => $model,
                        'messages' => $messages,
                        'temperature' => self::COMPLETION_TEMPERATURE,
                        'max_tokens' => self::DISTRIBUTION_MAX_TOKENS,
                    ]);
            } catch (ConnectionException $e) {
                Log::error('GigaChat distribution connection failed', ['error' => $e->getMessage()]);

                throw new AiServiceException(
                    'GigaChat API is unreachable: '.$e->getMessage(),
                    503
                );
            }

            if ($response->status() === 401) {
                $this->forgetAccessToken();
                Log::error('GigaChat distribution unauthorized, token cache cleared');

                throw new AiServiceException('GigaChat access token was rejected, try again.', 502);
            }

            if ($response->status() === 429) {
                Log::warning('GigaChat distribution rate limit hit', ['status' => 429]);

                throw new AiServiceException('GigaChat request rate limit exceeded, try again later.', 429);
            }

            if (! $response->successful()) {
                Log::error('GigaChat distribution unexpected status', [
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
                Log::error('GigaChat distribution empty content', [
                    'body' => mb_substr($response->body(), 0, 1000),
                ]);

                throw new AiServiceException('GigaChat returned an empty response.', 502);
            }

            Log::info('GigaChat raw distribution content', [
                'raw' => mb_substr($content, 0, 2000),
            ]);

            $items = $this->extractDistribution($content);

            if ($items !== []) {
                return $items;
            }

            Log::info('GigaChat distribution came back empty, retrying once', ['attempt' => $attempt]);
        }

        return [];
    }

    /**
     * @return list<array{category: string, reduction_percentage: float}>
     */
    private function extractDistribution(string $content): array
    {
        $decoded = $this->extractJson($content);

        if ($decoded === null) {
            Log::error('GigaChat distribution invalid JSON', [
                'raw' => mb_substr($content, 0, 2000),
            ]);

            throw new AiServiceException(
                'GigaChat returned a response that is not valid JSON.',
                422
            );
        }

        $items = $decoded['distribution'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $result = [];

        foreach (array_values($items) as $item) {
            if (! is_array($item)
                || ! isset($item['category'], $item['reduction_percentage'])
                || ! is_string($item['category'])
                || trim($item['category']) === ''
                || ! is_numeric($item['reduction_percentage'])
            ) {
                continue;
            }

            $result[] = [
                'category' => $item['category'],
                'reduction_percentage' => (float) $item['reduction_percentage'],
            ];
        }

        return $result;
    }

    private function distributionSystemPrompt(): string
    {
        return <<<'PROMPT'
            Ты — финансовый советник. Распредели целевую экономию по категориям трат.

            Отвечай ТОЛЬКО валидным JSON без markdown и пояснений:
            {"distribution": [{"category": "точное название из data", "reduction_percentage": 30}]}

            Правила (коротко):
            - category — точное название категории из data;
            - reduction_percentage — число от 0 до 100, доля сокращения трат категории;
            - распредели так, чтобы сумма экономии приблизилась к целевой.
            PROMPT;
    }

    /**
     * Свободный диалог: без JSON-схемы и парсинга — возвращается текст
     * модели как есть. Любая транспортная проблема маппится в 503
     * с пользовательским текстом (детали — в лог).
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function chat(string $systemPrompt, array $messages): string
    {
        $config = $this->config();
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.giga.chat'), '/');
        $model = (string) ($config['model'] ?? 'GigaChat-2');
        $timeout = (int) ($config['timeout'] ?? 30);
        $token = $this->getAccessToken();

        $payload = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $payload[] = [
                'role' => $message['role'] ?? 'user',
                'content' => (string) ($message['content'] ?? ''),
            ];
        }

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $this->verifySsl($config)])
                ->acceptJson()
                ->withToken($token)
                ->post($baseUrl.'/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $payload,
                    'temperature' => self::CHAT_TEMPERATURE,
                    'max_tokens' => self::CHAT_MAX_TOKENS,
                ]);
        } catch (ConnectionException $e) {
            Log::error('GigaChat chat connection failed', ['error' => $e->getMessage()]);

            throw new AiServiceException(
                'Ассистент временно недоступен. Попробуйте позже.',
                503
            );
        }

        if (! $response->successful()) {
            Log::error('GigaChat chat request failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new AiServiceException(
                'Ассистент временно недоступен. Попробуйте позже.',
                503
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            Log::error('GigaChat chat empty content', [
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new AiServiceException(
                'Ассистент временно недоступен. Попробуйте позже.',
                503
            );
        }

        return trim($content);
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

    /**
     * Извлекает JSON из ответа модели. Модель иногда присылает мусор
     * вокруг JSON (реальный случай из логов: "{}\n```\n{...}\n```"),
     * поэтому после прямой попытки ищем все сбалансированные {...}-блоки
     * и берём первый декодируемый, предпочитая блок с ключами
     * insights/recommendations. Сканирование побайтовое — безопасно
     * для UTF-8, т.к. структурные символы ASCII не пересекаются
     * с многобайтовыми последовательностями.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $decoded = json_decode($this->stripCodeFences($content), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $fallback = null;

        foreach ($this->jsonCandidates($content) as $candidate) {
            $decoded = json_decode($candidate, true);

            if (! is_array($decoded)) {
                continue;
            }

            if (array_key_exists('insights', $decoded) || array_key_exists('recommendations', $decoded)) {
                return $decoded;
            }

            $fallback ??= $decoded;
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function jsonCandidates(string $content): array
    {
        $candidates = [];
        $length = strlen($content);
        $i = 0;

        while ($i < $length) {
            if ($content[$i] !== '{') {
                $i++;

                continue;
            }

            $depth = 0;
            $inString = false;
            $escape = false;
            $start = $i;
            $closedAt = null;

            for ($j = $i; $j < $length; $j++) {
                $ch = $content[$j];

                if ($inString) {
                    if ($escape) {
                        $escape = false;
                    } elseif ($ch === '\\') {
                        $escape = true;
                    } elseif ($ch === '"') {
                        $inString = false;
                    }
                } elseif ($ch === '"') {
                    $inString = true;
                } elseif ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $closedAt = $j;

                        break;
                    }
                }
            }

            if ($closedAt !== null) {
                $candidates[] = substr($content, $start, $closedAt - $start + 1);
                $i = $closedAt + 1;
            } else {
                $i = $start + 1;
            }
        }

        return $candidates;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            Ты — финансовый аналитик. Анализируешь выписку и возвращаешь insights и рекомендации.

            Отвечай ТОЛЬКО валидным JSON без markdown и пояснений:
            {
              "insights": [
                {"type": "рост_расходов|главная_категория|аномалия|подписка|временной_паттерн", "title": "...", "description": "..."}
              ],
              "recommendations": [
                {"problem": "...", "explanation": "...", "recommendation": "...", "category": "...", "reduction_percentage": 30, "priority": "high|medium|low"}
              ]
            }

            Пример хорошего ответа:
            {"insights": [{"type": "подписка", "title": "Много подписок", "description": "Подписки — 3693.00 руб., проверьте неиспользуемые."}], "recommendations": [{"problem": "Дорогая доставка", "explanation": "Доставка — 16780.00 руб.", "recommendation": "Готовьте дома дважды в неделю.", "category": "Доставка", "reduction_percentage": 30, "priority": "high"}]}

            Правила (коротко):
            - type и priority — только из списков выше; category — точное название из data; "Прочее"/"Other" и "Переводы" — не кандидаты (переводы = движение денег, не расходы);
            - reduction_percentage — число от 5 до 80 (твоя оценка среза трат; суммы экономии backend посчитает сам);
            - ВСЕ цифры копируй из data как есть: суммы, средние и проценты уже посчитаны, самому считать запрещено;
            - insights — от 0 до 5: есть заметный паттерн (доля категории / крупная транзакция / повторы-подписки / будни-выходные) — верни хотя бы 1; мало данных (<5 транзакций) — можно пусто; тип только из списка, не выдумывай;
            - без блока про предыдущий период в data — никаких сравнений с прошлым;
            - если в data есть категории трат — верни хотя бы 1 recommendation;
            - верни объект РОВНО с двумя ключами верхнего уровня: insights и recommendations, никаких других ключей верхнего уровня.
            PROMPT;
    }

    /**
     * @param  array<string, mixed>  $analyticsData
     * @param  array<string, mixed>|null  $previousPeriod
     * @param  list<string>  $hiddenCategories
     */
    private function userPrompt(array $analyticsData, ?array $previousPeriod, array $hiddenCategories = []): string
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

        $hidden = array_map(
            fn ($category): string => mb_strtolower(trim((string) $category)),
            $hiddenCategories
        );

        $categories = $analyticsData['by_category'] ?? [];
        if (is_array($categories) && $categories !== []) {
            $lines[] = 'Категории:';
            foreach ($categories as $row) {
                if (! is_array($row)) {
                    continue;
                }
                // Transfer-категории скрыты из промпта заранее: модель
                // не должна видеть их как кандидатов для рекомендаций.
                if (in_array(mb_strtolower(trim((string) ($row['category'] ?? ''))), $hidden, true)) {
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
