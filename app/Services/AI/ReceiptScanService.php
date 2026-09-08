<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Statement;
use App\Models\Transaction;
use App\Services\TransactionCategorizerService;
use App\Services\TransactionManualCreateService;
use Illuminate\Http\UploadedFile;

/**
 * Двухшаговый flow распознавания чеков БЕЗ автосохранения.
 * scan() — только превью (GigaChat + категоризатор), confirm() — запись.
 */
class ReceiptScanService
{
    public function __construct(
        private GigaChatService $gigachat,
        private TransactionCategorizerService $categorizer,
        private TransactionManualCreateService $manual,
    ) {}

    /**
     * Шаг 1: фото → превью. Ничего не пишет в БД.
     *
     * @return array{date: string, merchant: string, amount: float, suggested_category: string, category_confidence: float}
     */
    public function scan(UploadedFile $file): array
    {
        $fileId = $this->gigachat->uploadFile($file);
        $parsed = $this->gigachat->analyzeReceipt($fileId);

        // Та же rule-based логика, что для CSV-транзакций.
        $categorization = $this->categorizer->categorize($parsed['merchant']);

        return [
            'date' => $parsed['date'],
            'merchant' => $parsed['merchant'],
            'amount' => $parsed['amount'],
            'suggested_category' => $categorization['category'],
            'category_confidence' => $categorization['confidence'],
        ];
    }

    /**
     * Шаг 2: подтверждённые (возможно отредактированные) данные → Transaction.
     * Чек — всегда расход: делегирует общему сервису с type='debit'.
     *
     * @param  array{date: string, merchant: string, amount: float, category: string}  $data
     */
    public function confirm(Statement $statement, array $data): Transaction
    {
        return $this->manual->create($statement, [
            'date' => $data['date'],
            'description' => $data['merchant'],
            'amount' => $data['amount'],
            'category' => $data['category'],
            'type' => 'debit',
            'merchant' => $data['merchant'],
        ]);
    }
}
