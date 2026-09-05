<?php

use App\Models\Statement;
use App\Models\Transaction;
use App\Services\AnalyticsService;

function makeStatementWithTransactions(array $rows): Statement
{
    $statement = Statement::create([
        'file_name' => 'analytics-test.csv',
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

test('analytics calculates totals and by_category', function () {
    // 2026-09-05 — суббота, 2026-09-06 — воскресенье, 2026-09-07 — понедельник.
    $statement = makeStatementWithTransactions([
        ['date' => '2026-09-07', 'amount' => 10000, 'type' => 'credit', 'raw_description' => 'Salary', 'category' => 'Зарплата'],
        ['date' => '2026-09-07', 'amount' => -3000, 'type' => 'debit', 'raw_description' => 'Groceries', 'category' => 'Продукты'],
        ['date' => '2026-09-05', 'amount' => -1000, 'type' => 'debit', 'raw_description' => 'Taxi', 'category' => 'Такси'],
        ['date' => '2026-09-06', 'amount' => -5000, 'type' => 'transfer', 'raw_description' => 'Transfer', 'category' => 'Переводы', 'recipient' => 'Иван'],
    ]);

    $analytics = app(AnalyticsService::class)->analyze($statement);

    // totals: переводы не входят в доходы/расходы
    expect($analytics['totals']['total_income'])->toEqualWithDelta(10000.0, 0.01);
    expect($analytics['totals']['total_expenses'])->toEqualWithDelta(4000.0, 0.01);
    expect($analytics['totals']['balance'])->toEqualWithDelta(6000.0, 0.01);
    expect($analytics['totals']['average_expense'])->toEqualWithDelta(2000.0, 0.01);
    expect($analytics['totals']['transactions_count'])->toBe(4);

    // by_category: оборот = 10000 + 3000 + 1000 + 5000 = 19000
    $byCategory = $analytics['by_category'];
    expect($byCategory)->toHaveCount(4);
    expect($byCategory[0]['category'])->toBe('Зарплата');
    expect($byCategory[0]['amount'])->toEqualWithDelta(10000.0, 0.01);
    expect($byCategory[0]['percentage'])->toEqualWithDelta(52.63, 0.01);
    expect($byCategory[1]['category'])->toBe('Переводы');
    expect($byCategory[2]['category'])->toBe('Продукты');
    expect($byCategory[3]['category'])->toBe('Такси');

    $percentageSum = array_sum(array_column($byCategory, 'percentage'));
    expect($percentageSum)->toEqualWithDelta(100.0, 0.05);
});

test('analytics timeline is cumulative and extras split weekend', function () {
    $statement = makeStatementWithTransactions([
        ['date' => '2026-09-07', 'amount' => 10000, 'type' => 'credit', 'raw_description' => 'Salary', 'category' => 'Зарплата'],
        ['date' => '2026-09-07', 'amount' => -3000, 'type' => 'debit', 'raw_description' => 'Groceries', 'category' => 'Продукты'],
        ['date' => '2026-09-05', 'amount' => -1000, 'type' => 'debit', 'raw_description' => 'Taxi', 'category' => 'Такси'],
        ['date' => '2026-09-06', 'amount' => -5000, 'type' => 'transfer', 'raw_description' => 'Transfer', 'category' => 'Переводы', 'recipient' => 'Иван'],
    ]);

    $analytics = app(AnalyticsService::class)->analyze($statement);

    // timeline: накопительный balance, сортировка по дате
    $timeline = $analytics['timeline'];
    expect(array_column($timeline, 'date'))->toBe(['2026-09-05', '2026-09-06', '2026-09-07']);
    expect($timeline[0]['expenses'])->toEqualWithDelta(1000.0, 0.01);
    expect($timeline[0]['balance'])->toEqualWithDelta(-1000.0, 0.01);
    expect($timeline[1]['balance'])->toEqualWithDelta(-1000.0, 0.01);
    expect($timeline[2]['income'])->toEqualWithDelta(10000.0, 0.01);
    expect($timeline[2]['balance'])->toEqualWithDelta(6000.0, 0.01);

    // top_recipients
    expect($analytics['top_recipients'])->toHaveCount(1);
    expect($analytics['top_recipients'][0]['recipient'])->toBe('Иван');
    expect($analytics['top_recipients'][0]['amount'])->toEqualWithDelta(5000.0, 0.01);

    // extras: перевод в выходные не считается тратой; активных дней 3
    expect($analytics['extras']['weekend_spending'])->toEqualWithDelta(1000.0, 0.01);
    expect($analytics['extras']['weekday_spending'])->toEqualWithDelta(3000.0, 0.01);
    expect($analytics['extras']['average_daily_spending'])->toEqualWithDelta(4000.0 / 3, 0.01);
    expect($analytics['extras']['largest_transaction']['amount'])->toEqualWithDelta(10000.0, 0.01);
    expect($analytics['extras']['largest_transaction']['date'])->toBe('2026-09-07');
});

test('analytics returns zeros for empty statement', function () {
    $statement = Statement::create([
        'file_name' => 'empty.csv',
        'file_type' => 'csv',
    ]);

    $analytics = app(AnalyticsService::class)->analyze($statement);

    expect($analytics['totals'])->toBe([
        'total_income' => 0.0,
        'total_expenses' => 0.0,
        'balance' => 0.0,
        'average_expense' => 0.0,
        'transactions_count' => 0,
    ]);
    expect($analytics['by_category'])->toBe([]);
    expect($analytics['timeline'])->toBe([]);
    expect($analytics['top_recipients'])->toBe([]);
    expect($analytics['extras']['weekend_spending'])->toBe(0.0);
    expect($analytics['extras']['weekday_spending'])->toBe(0.0);
    expect($analytics['extras']['average_daily_spending'])->toBe(0.0);
    expect($analytics['extras']['largest_transaction'])->toBeNull();
});

test('analytics endpoint returns json for statement', function () {
    $statement = makeStatementWithTransactions([
        ['date' => '2026-09-07', 'amount' => 5000, 'type' => 'income', 'raw_description' => 'Salary', 'category' => 'Зарплата'],
    ]);

    $response = $this->getJson("/api/statements/{$statement->id}/analytics");

    $response->assertOk();
    $response->assertJsonPath('totals.total_income', 5000);
    $response->assertJsonPath('totals.balance', 5000);
    $response->assertJsonPath('totals.transactions_count', 1);
});
