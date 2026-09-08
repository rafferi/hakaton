<?php

use App\Models\Statement;
use App\Models\Transaction;
use App\Services\AI\ProfileAggregationService;

function makeTrendStatement(array $rows): Statement
{
    $statement = Statement::create([
        'user_id' => auth()->id(),
        'file_name' => 'trend-test.csv',
        'file_type' => 'csv',
    ]);

    foreach ($rows as $row) {
        Transaction::create(array_merge([
            'statement_id' => $statement->id,
            'normalized_description' => null,
            'merchant' => null,
            'recipient' => null,
            'category_confidence' => null,
        ], $row));
    }

    return $statement;
}

test('monthly trend groups by month with correct sums and chronological order', function () {
    actingAsNewUser();

    makeTrendStatement([
        ['date' => '2026-05-10', 'amount' => 10000, 'type' => 'credit', 'raw_description' => 'Salary', 'category' => 'Зарплата'],
        ['date' => '2026-05-12', 'amount' => -3000, 'type' => 'debit', 'raw_description' => 'Groceries', 'category' => 'Продукты'],
    ]);

    makeTrendStatement([
        ['date' => '2026-08-03', 'amount' => -2000, 'type' => 'debit', 'raw_description' => 'Taxi', 'category' => 'Такси'],
        ['date' => '2026-09-01', 'amount' => 5000, 'type' => 'credit', 'raw_description' => 'Salary', 'category' => 'Зарплата'],
        ['date' => '2026-09-02', 'amount' => -7000, 'type' => 'transfer', 'raw_description' => 'Transfer', 'category' => 'Переводы', 'recipient' => 'Иван'],
        ['date' => '2026-09-15', 'amount' => -1000, 'type' => 'debit', 'raw_description' => 'Cafe', 'category' => 'Кафе и рестораны'],
    ]);

    $trend = app(ProfileAggregationService::class)->buildMonthlyTrend();

    // Только месяцы с транзакциями — 2026-06/07 отсутствуют, нулевых строк нет.
    expect(array_column($trend, 'month'))->toBe(['2026-05', '2026-08', '2026-09']);

    expect($trend[0]['income'])->toEqualWithDelta(10000.0, 0.01);
    expect($trend[0]['expenses'])->toEqualWithDelta(3000.0, 0.01);
    expect($trend[0]['balance'])->toEqualWithDelta(7000.0, 0.01);
    expect($trend[0]['transactions_count'])->toBe(2);

    expect($trend[1]['income'])->toEqualWithDelta(0.0, 0.01);
    expect($trend[1]['expenses'])->toEqualWithDelta(2000.0, 0.01);
    expect($trend[1]['balance'])->toEqualWithDelta(-2000.0, 0.01);
    expect($trend[1]['transactions_count'])->toBe(1);

    // Перевод не входит в income/expenses, но входит в transactions_count.
    expect($trend[2]['income'])->toEqualWithDelta(5000.0, 0.01);
    expect($trend[2]['expenses'])->toEqualWithDelta(1000.0, 0.01);
    expect($trend[2]['balance'])->toEqualWithDelta(4000.0, 0.01);
    expect($trend[2]['transactions_count'])->toBe(3);
});

test('monthly trend is empty when there are no transactions', function () {
    actingAsNewUser();

    expect(app(ProfileAggregationService::class)->buildMonthlyTrend())->toBe([]);
});

test('profile trend endpoint returns months wrapped in object', function () {
    actingAsNewUser();

    makeTrendStatement([
        ['date' => '2026-09-07', 'amount' => 5000, 'type' => 'credit', 'raw_description' => 'Salary', 'category' => 'Зарплата'],
        ['date' => '2026-09-08', 'amount' => -1500, 'type' => 'debit', 'raw_description' => 'Groceries', 'category' => 'Продукты'],
    ]);

    $response = $this->getJson('/api/profile/trend');

    $response->assertOk();
    $response->assertJsonPath('months.0.month', '2026-09');
    $response->assertJsonPath('months.0.income', 5000);
    $response->assertJsonPath('months.0.expenses', 1500);
    $response->assertJsonPath('months.0.balance', 3500);
    $response->assertJsonPath('months.0.transactions_count', 2);
});

test('profile trend endpoint returns empty months when no data', function () {
    actingAsNewUser();

    $response = $this->getJson('/api/profile/trend');

    $response->assertOk();
    $response->assertJsonPath('months', []);
});
