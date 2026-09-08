<?php

use App\Models\Statement;
use App\Models\Transaction;
use App\Services\MandatoryExpensesDetectorService;

function makeMandatoryStatement(array $rows): Statement
{
    $statement = Statement::create([
        'user_id' => auth()->id(),
        'file_name' => 'mandatory-test.csv',
        'file_type' => 'csv',
    ]);

    foreach ($rows as $row) {
        Transaction::create(array_merge([
            'statement_id' => $statement->id,
            'raw_description' => 'test expense',
            'normalized_description' => null,
            'merchant' => null,
            'recipient' => null,
            'category' => null,
            'category_confidence' => null,
        ], $row));
    }

    return $statement;
}

test('mandatory expenses returns empty result on empty database', function () {
    actingAsNewUser();

    $result = app(MandatoryExpensesDetectorService::class)->detect();

    expect($result['mandatory_expenses'])->toBe([]);
    expect($result['total_monthly_mandatory'])->toBe(0.0);
    expect($result['summary'])->toBe([
        'subscriptions_count' => 0,
        'subscriptions_total' => 0.0,
        'utilities_total' => 0.0,
        'rent_total' => 0.0,
        'loans_total' => 0.0,
        'communication_total' => 0.0,
    ]);

    $response = $this->getJson('/api/profile/mandatory-expenses');

    $response->assertOk();
    $response->assertJsonPath('mandatory_expenses', []);
    $response->assertJsonPath('total_monthly_mandatory', 0);
});

test('mandatory expenses detects monthly subscription', function () {
    actingAsNewUser();

    makeMandatoryStatement([
        ['date' => '2026-07-01', 'amount' => -699, 'type' => 'debit', 'merchant' => 'Netflix', 'category' => 'Подписки'],
        ['date' => '2026-08-01', 'amount' => -699, 'type' => 'debit', 'merchant' => 'Netflix', 'category' => 'Подписки'],
        ['date' => '2026-09-01', 'amount' => -699, 'type' => 'debit', 'merchant' => 'Netflix', 'category' => 'Подписки'],
    ]);

    $result = app(MandatoryExpensesDetectorService::class)->detect();

    expect($result['mandatory_expenses'])->toHaveCount(1);

    $item = $result['mandatory_expenses'][0];
    expect($item['type'])->toBe('subscription');
    expect($item['category'])->toBe('Подписки');
    expect($item['merchant'])->toBe('Netflix');
    expect($item['average_amount'])->toEqualWithDelta(699.0, 0.01);
    expect($item['frequency'])->toBe('monthly');
    expect($item['occurrences'])->toBe(3);
    expect($item['last_date'])->toBe('2026-09-01');
    expect($item['monthly_total'])->toEqualWithDelta(699.0, 0.01);

    expect($result['total_monthly_mandatory'])->toEqualWithDelta(699.0, 0.01);
    expect($result['summary']['subscriptions_count'])->toBe(1);
    expect($result['summary']['subscriptions_total'])->toEqualWithDelta(699.0, 0.01);
});

test('mandatory expenses detects utilities with varying amounts', function () {
    actingAsNewUser();

    makeMandatoryStatement([
        ['date' => '2026-07-05', 'amount' => -4100, 'type' => 'debit', 'merchant' => 'ЕИРЦ', 'category' => 'ЖКХ'],
        ['date' => '2026-08-05', 'amount' => -4250, 'type' => 'debit', 'merchant' => 'ЕИРЦ', 'category' => 'ЖКХ'],
        ['date' => '2026-09-05', 'amount' => -4180, 'type' => 'debit', 'merchant' => 'ЕИРЦ', 'category' => 'ЖКХ'],
    ]);

    $result = app(MandatoryExpensesDetectorService::class)->detect();

    expect($result['mandatory_expenses'])->toHaveCount(1);

    $item = $result['mandatory_expenses'][0];
    expect($item['type'])->toBe('utilities');
    expect($item['category'])->toBe('ЖКХ');
    expect($item['merchant'])->toBe('ЕИРЦ');
    expect($item['average_amount'])->toEqualWithDelta((4100 + 4250 + 4180) / 3, 0.01);
    expect($item['occurrences'])->toBe(3);

    expect($result['summary']['utilities_total'])->toEqualWithDelta((4100 + 4250 + 4180) / 3, 0.01);
});

test('mandatory expenses detects rent by recipient', function () {
    actingAsNewUser();

    makeMandatoryStatement([
        ['date' => '2026-08-01', 'amount' => -45000, 'type' => 'debit', 'recipient' => 'Иванов И.И.', 'category' => 'Жильё'],
        ['date' => '2026-09-01', 'amount' => -45000, 'type' => 'debit', 'recipient' => 'Иванов И.И.', 'category' => 'Жильё'],
    ]);

    $result = app(MandatoryExpensesDetectorService::class)->detect();

    expect($result['mandatory_expenses'])->toHaveCount(1);

    $item = $result['mandatory_expenses'][0];
    expect($item['type'])->toBe('rent');
    expect($item['category'])->toBe('Жильё');
    expect($item['merchant'])->toBe('Иванов И.И.');
    expect($item['average_amount'])->toEqualWithDelta(45000.0, 0.01);
    expect($item['occurrences'])->toBe(2);

    expect($result['summary']['rent_total'])->toEqualWithDelta(45000.0, 0.01);
});

test('mandatory expenses keeps only regular payments in mixed data', function () {
    actingAsNewUser();

    makeMandatoryStatement([
        // Регулярная подписка — должна найтись.
        ['date' => '2026-07-01', 'amount' => -699, 'type' => 'debit', 'merchant' => 'Netflix', 'category' => 'Подписки'],
        ['date' => '2026-08-01', 'amount' => -699, 'type' => 'debit', 'merchant' => 'Netflix', 'category' => 'Подписки'],
        // Регулярная связь — должна найтись.
        ['date' => '2026-08-10', 'amount' => -750, 'type' => 'debit', 'merchant' => 'МТС', 'category' => 'Связь и интернет'],
        ['date' => '2026-09-10', 'amount' => -750, 'type' => 'debit', 'merchant' => 'МТС', 'category' => 'Связь и интернет'],
        // Один и тот же merchant дважды, но с интервалом 5 дней — не monthly.
        ['date' => '2026-09-01', 'amount' => -1200, 'type' => 'debit', 'merchant' => 'Пятёрочка', 'category' => 'Продукты'],
        ['date' => '2026-09-06', 'amount' => -1500, 'type' => 'debit', 'merchant' => 'Пятёрочка', 'category' => 'Продукты'],
        // Разовые траты — не кандидаты вообще.
        ['date' => '2026-09-02', 'amount' => -3000, 'type' => 'debit', 'merchant' => 'Кинотеатр', 'category' => 'Развлечения'],
        // Доход и перевод — не расходы, игнорируются.
        ['date' => '2026-09-01', 'amount' => 100000, 'type' => 'credit', 'merchant' => null, 'category' => 'Зарплата'],
        ['date' => '2026-09-03', 'amount' => -5000, 'type' => 'transfer', 'merchant' => null, 'recipient' => 'Иван', 'category' => 'Переводы'],
    ]);

    $response = $this->getJson('/api/profile/mandatory-expenses');

    $response->assertOk();

    $expenses = $response->json('mandatory_expenses');
    expect($expenses)->toHaveCount(2);
    expect(array_column($expenses, 'type'))->toBe(['communication', 'subscription']);

    // Сортировка по monthly_total по убыванию: МТС 750 первее Netflix 699.
    expect($expenses[0]['merchant'])->toBe('МТС');
    expect($expenses[1]['merchant'])->toBe('Netflix');

    $response->assertJsonPath('total_monthly_mandatory', 1449);
    $response->assertJsonPath('summary.subscriptions_count', 1);
    $response->assertJsonPath('summary.communication_total', 750);
});
