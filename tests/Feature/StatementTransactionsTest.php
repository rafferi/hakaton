<?php

use App\Models\Statement;
use App\Models\Transaction;

function makeStatementList(array $rows): Statement
{
    $statement = Statement::create([
        'file_name' => 'transactions-test.csv',
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

function seedTransactions(): Statement
{
    return makeStatementList([
        ['date' => '2026-09-07', 'amount' => 10000, 'type' => 'credit', 'raw_description' => 'Salary', 'category' => 'Зарплата'],
        ['date' => '2026-09-07', 'amount' => -3000, 'type' => 'debit', 'raw_description' => 'Groceries Market', 'category' => 'Продукты', 'merchant' => 'Market'],
        ['date' => '2026-09-05', 'amount' => -1000, 'type' => 'debit', 'raw_description' => 'Taxi ride', 'category' => 'Такси'],
        ['date' => '2026-09-06', 'amount' => -5000, 'type' => 'transfer', 'raw_description' => 'Transfer out', 'category' => 'Переводы', 'recipient' => 'Ivan'],
        ['date' => '2026-09-01', 'amount' => -500, 'type' => 'debit', 'raw_description' => 'ниците кофе', 'category' => 'Кафе', 'merchant' => 'Coffee House'],
    ]);
}

test('transactions endpoint returns paginated list sorted by date desc', function () {
    $statement = seedTransactions();

    $response = $this->getJson("/api/statements/{$statement->id}/transactions");

    $response->assertOk();
    $response->assertJsonCount(5, 'data');
    $response->assertJsonPath('meta.total', 5);
    $response->assertJsonPath('meta.per_page', 20);

    $dates = array_column($response->json('data'), 'date');
    expect($dates)->toBe(['2026-09-07', '2026-09-07', '2026-09-06', '2026-09-05', '2026-09-01']);

    // При равной дате первым идёт больший id (вторичная сортировка id desc).
    // Resource shape: normalized fallback to raw when normalized is null
    $response->assertJsonPath('data.0.description', 'Groceries Market');
    expect($response->json('data.0'))->toHaveKeys(
        ['id', 'date', 'amount', 'type', 'description', 'merchant', 'recipient', 'category', 'category_confidence']
    );
});

test('transactions endpoint filters by category and type', function () {
    $statement = seedTransactions();

    $byCategory = $this->getJson("/api/statements/{$statement->id}/transactions?category=".urlencode('Продукты'));
    $byCategory->assertOk();
    $byCategory->assertJsonCount(1, 'data');
    $byCategory->assertJsonPath('data.0.category', 'Продукты');

    $byType = $this->getJson("/api/statements/{$statement->id}/transactions?type=transfer");
    $byType->assertOk();
    $byType->assertJsonCount(1, 'data');
    $byType->assertJsonPath('data.0.type', 'transfer');
    $byType->assertJsonPath('data.0.recipient', 'Ivan');
});

test('transactions endpoint filters by date range, amount range and search', function () {
    $statement = seedTransactions();

    // date_from без date_to — валидно
    $dates = $this->getJson("/api/statements/{$statement->id}/transactions?date_from=2026-09-06&date_to=2026-09-07");
    $dates->assertOk();
    $dates->assertJsonPath('meta.total', 3);

    // amount через ABS: 500..1000 покрывает -500 и -1000, но не -3000/-5000
    $amounts = $this->getJson("/api/statements/{$statement->id}/transactions?amount_min=500&amount_max=1000");
    $amounts->assertOk();
    $amounts->assertJsonPath('meta.total', 2);

    // search регистронезависимый, по merchant в том числе
    $search = $this->getJson("/api/statements/{$statement->id}/transactions?search=coffee");
    $search->assertOk();
    $search->assertJsonCount(1, 'data');
    $search->assertJsonPath('data.0.merchant', 'Coffee House');
});

test('transactions endpoint paginates and validates input', function () {
    $statement = seedTransactions();

    $page = $this->getJson("/api/statements/{$statement->id}/transactions?per_page=2&page=2");
    $page->assertOk();
    $page->assertJsonCount(2, 'data');
    $page->assertJsonPath('meta.total', 5);
    $page->assertJsonPath('meta.current_page', 2);
    $page->assertJsonPath('meta.per_page', 2);

    // amount_min > amount_max → 422, а не 500
    $invalid = $this->getJson("/api/statements/{$statement->id}/transactions?amount_min=1000&amount_max=100");
    $invalid->assertStatus(422);
    $invalid->assertJsonValidationErrors('amount_max');

    // неверный формат даты → 422
    $badDate = $this->getJson("/api/statements/{$statement->id}/transactions?date_from=not-a-date");
    $badDate->assertStatus(422);

    // per_page выше максимума → 422
    $tooBig = $this->getJson("/api/statements/{$statement->id}/transactions?per_page=500");
    $tooBig->assertStatus(422);

    // несуществующая выписка → 404
    $this->getJson('/api/statements/999999/transactions')->assertNotFound();
});

test('transactions endpoint returns empty data when nothing matches', function () {
    $statement = seedTransactions();

    $empty = $this->getJson("/api/statements/{$statement->id}/transactions?category=NoSuchCategory");
    $empty->assertOk();
    $empty->assertJsonCount(0, 'data');
    $empty->assertJsonPath('meta.total', 0);

    $emptyStatement = Statement::create(['file_name' => 'empty.csv', 'file_type' => 'csv']);
    $noTx = $this->getJson("/api/statements/{$emptyStatement->id}/transactions");
    $noTx->assertOk();
    $noTx->assertJsonCount(0, 'data');
    $noTx->assertJsonPath('meta.total', 0);
});
