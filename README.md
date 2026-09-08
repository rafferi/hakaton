FinBalance API — backend

REST API анализатора банковских выписок FinBalance (хакатон-кейс для
экосистемы Сбера). Полный цикл: импорт выписки → нормализация →
категоризация → аналитика → AI-анализ → рекомендации → план экономии.

 Стек

- PHP 8.3+, Laravel 12
- PostgreSQL (dev), SQLite (тесты)
- Laravel Sanctum — авторизация (Bearer-токены)
- GigaChat API — текстовые модели и Vision (распознавание чеков)
- Pest / PHPUnit (тесты), Pint, PHPStan

 Ключевые возможности

- Импорт CSV: BOM, Windows-1251, CRLF, автоопределение разделителя
  (`,`, `;`, tab, `|`); честное разделение ошибок «файл пуст» и
  «не удалось распознать ни одной транзакции»
- Категоризация: правила по ключевым словам (config/categories.php)
  с confidence; транзакции с низкой уверенностью уходят в GigaChat
- Аналитика по выписке: totals, категории с процентами, динамика по
  дням, топ получателей, выходные/будни, крупнейшая транзакция
- Список транзакций: фильтры по категории, типу, датам, сумме,
  поиск, пагинация
- AI Financial X-Ray: инсайты и рекомендации по выписке; суммы
  экономии считает backend по формуле на реальной аналитике —
  модель даёт только категорию и процент сокращения
- Устойчивость к нестабильности модели: постатейная валидация
  ответа, retry при пустом ответе, детерминированные fallback'и
  (инсайт и рекомендация синтезируются из аналитики, если GigaChat
  недоступен или вернул пустоту)
- «Хочу экономить X»: распределение целевой суммы по категориям
  (AI или пропорциональный fallback без сети), проверка достижимости
- Чат-консультант: свободный диалог на основе агрегированного
  профиля по всем выпискам пользователя
- Распознавание чеков по фото: GigaChat Vision, двухшаговый flow —
  превью с возможностью правки, затем подтверждение пользователем
- Ручной ввод операций (доход/расход) со справочником категорий
- Автоопределение обязательных расходов: аренда, ЖКХ, подписки,
  кредиты, связь — по паттернам регулярности платежей (25–35 дней)
- Тренд доходов/расходов по месяцам across все выписки
- Авторизация: регистрация, вход, выход; все бизнес-маршруты под
  auth:sanctum; данные изолированы по пользователю

 Архитектура

- Тонкие контроллеры: вся бизнес-логика в app/Services
- FormRequest для валидации входа, русские сообщения об ошибках
- StatementParserInterface — общий контракт парсеров выписок
  (CSV реализован, PDF подключается через тот же интерфейс)
- GigaChatService — единая точка входа в GigaChat: кэш OAuth-токена,
  retry при сетевых сбоях, робастное извлечение JSON, управление
  проверкой SSL
- InsightPersistenceService: валидация ответа модели по схеме,
  отклонение невалидных пунктов поштучно вместо отказа всего
  ответа, детерминированный расчёт сумм экономии
- Statement::forCurrentUser() — центральный scope доступа к данным
  и точка расширения авторизации: без auth возвращает демо-бакет
  (user_id IS NULL), с auth фильтрует по пользователю

 Быстрый старт

Требования: PHP 8.3+, Composer, PostgreSQL. Вне России для живых
вызовов GigaChat может потребоваться VPN с выходом в РФ; все
AI-фичи имеют fallback, поэтому приложение остаётся рабочим и без сети.

    composer install
    cp .env.example .env
    php artisan key:generate
    # заполнить БД и GIGACHAT_* в .env (см. ниже)
    php artisan migrate --seed
    php artisan serve

API доступен на http://127.0.0.1:8000.
migrate --seed создаёт демо-пользователя с набором выписок.

 Переменные окружения

    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=finbalance
    DB_USERNAME=postgres
    DB_PASSWORD=secret

    GIGACHAT_AUTH_KEY=готовый ключ авторизации из кабинета developers.sber.ru
    GIGACHAT_SCOPE=GIGACHAT_API_PERS
    GIGACHAT_VERIFY_SSL=true
    GIGACHAT_VISION_MODEL=GigaChat-2-Max

GIGACHAT_VERIFY_SSL=false — только для локальной разработки при
проблемах с сертификатом НУЦ Минцифры; в проде всегда true.

 API

Публичные маршруты:

    POST /api/auth/register     регистрация
    POST /api/auth/login        вход, возвращает Bearer-токен

Маршруты под auth:sanctum:

    POST /api/auth/logout       выход, отзывает текущий токен
    GET  /api/auth/me           текущий пользователь

    GET    /api/statements                  список выписок пользователя
    POST   /api/statements/upload           загрузка CSV-выписки
    GET    /api/statements/{id}             карточка выписки
    DELETE /api/statements/{id}             удаление со связанными данными

    GET  /api/statements/{id}/analytics           аналитика по выписке
    GET  /api/statements/{id}/transactions        транзакции с фильтрами
    POST /api/statements/{id}/transactions/manual ручная операция

    POST /api/statements/{id}/ai/analyze    AI-анализ (?force=true — пересчёт)
    GET  /api/statements/{id}/ai/insights   сохранённые инсайты и рекомендации
    POST /api/statements/{id}/savings-plan  план «хочу экономить X»

    POST /api/statements/{id}/receipts/scan     распознавание чека (превью)
    POST /api/statements/{id}/receipts/confirm  подтверждение и сохранение

    POST /api/chat                        чат с финансовым консультантом
    GET  /api/categories                  справочник категорий (расход/доход)
    GET  /api/profile/trend               динамика по месяцам (все выписки)
    GET  /api/profile/mandatory-expenses  автоопределённые обязательные расходы

 Демо-доступ

    demo@finbalance.ru / demo12345

Пользователь и тестовые выписки создаются DemoUserSeeder (идемпотентный).

 Тесты

    php artisan test

Feature- и unit-тесты покрывают: импорт и edge cases парсера,
арифметику аналитики, фильтры транзакций, авторизацию и изоляцию
данных, AI-пайплайн на Http::fake (кэширование токена, валидация
ответа модели, fallback'и, расчёт экономии). Тесты идут на SQLite;
SQL в сервисах написан кросс-драйверно (pgsql/sqlite).

 Структура проекта

    app/
      Http/Controllers/   тонкие контроллеры (Statement, AiInsight,
                          Receipt, Auth, Category, Profile, Chat)
      Http/Requests/      валидация (upload, фильтры, receipts,
                          manual, auth, savings-plan)
      Models/             Statement, Transaction, AiInsight, User
      Services/
        Parsers/          StatementParserInterface, CsvStatementParser
        AI/               GigaChatService
        StatementImportService, AnalyticsService,
        TransactionQueryService, TransactionCategorizerService,
        TransactionNormalizerService, TransactionManualCreateService,
        AiAnalysisService, InsightPersistenceService,
        SavingsPlanService, ProfileAggregationService,
        MandatoryExpensesDetectorService
    config/categories.php категории и правила ключевых слов
    database/seeders/     DemoUserSeeder
    tests/Feature, tests/Unit

 Принципы надёжности AI

1. Модель не считает деньги: суммы экономии считает backend по
   формуле category_amount × reduction_percentage / 100
2. Ответ модели валидируется по схеме; невалидный пункт отклоняется
   поштучно, валидные сохраняются
3. Пустой или нечитаемый ответ — не авария: одна повторная попытка,
   затем детерминированный fallback из аналитики
4. Результат анализа кэшируется: повторный вызов без force=true не
   тратит запросы к GigaChat
5. Категории «Прочее» и «Переводы» исключены из рекомендаций:
   оптимизировать их бессмысленно

 Roadmap

- Финальная доводка парсинга PDF-выписок (интерфейс готов)
- Очереди для импорта больших файлов
- Docker-композиция для деплоя
- Управление подписками на основе данных детектора
