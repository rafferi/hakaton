<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Statement;
use App\Models\Transaction;

class TransactionManualCreateService
{
    /**
     * Создаёт транзакцию вручную (ручной ввод дохода/расхода, подтверждение чека).
     *
     * @param  array{date: string, description: string, amount: float, category: string, type: string, merchant?: ?string}  $data
     */
    public function create(Statement $statement, array $data): Transaction
    {
        $transaction = Transaction::create([
            'statement_id' => $statement->id,
            'date' => $data['date'],
            'amount' => $data['amount'],
            'type' => $data['type'],
            'raw_description' => $data['description'],
            'normalized_description' => $data['description'],
            'merchant' => $data['merchant'] ?? null,
            'recipient' => null,
            'category' => $data['category'],
            // Ручной ввод — максимальная уверенность.
            'category_confidence' => 1.0,
        ]);

        $statement->increment('transactions_count');

        return $transaction;
    }
}
