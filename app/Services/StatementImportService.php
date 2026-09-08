<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StatementParserInterface;
use App\Models\Statement;
use App\Models\Transaction;
use App\Services\Parsers\CsvStatementParser;
use App\Services\Parsers\PdfStatementParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StatementImportService
{
    public function __construct(
        private TransactionNormalizerService $normalizer,
        private TransactionCategorizerService $categorizer,
    ) {}

    private function parserFor(UploadedFile $file): StatementParserInterface
    {
        $ext = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => new PdfStatementParser,
            default => new CsvStatementParser,
        };
    }

    /**
     * @return array{statement:?Statement, imported_transactions_count:int, error:?string}
     */
    public function import(UploadedFile $file): array
    {
        $parser = $this->parserFor($file);
        $parsed = $parser->parse($file);
        $rawTransactions = $parsed['transactions'];

        if (empty($rawTransactions)) {
            return [
                'statement' => null,
                'imported_transactions_count' => 0,
                'error' => $parsed['data_rows'] === 0 ? 'empty_file' : 'unrecognized_format',
            ];
        }

        $preparedTransactions = [];
        $minDate = null;
        $maxDate = null;

        foreach ($rawTransactions as $raw) {
            $normalized = $this->normalizer->normalize($raw);
            $categorization = $this->categorizer->categorize($raw['description']);

            $date = $raw['date'];

            if ($minDate === null || $date < $minDate) {
                $minDate = $date;
            }
            if ($maxDate === null || $date > $maxDate) {
                $maxDate = $date;
            }

            $preparedTransactions[] = [
                'date' => $date,
                'amount' => $raw['amount'],
                'type' => $raw['type'],
                'raw_description' => $raw['description'],
                'normalized_description' => $normalized['normalized_description'],
                'merchant' => $normalized['merchant'],
                'recipient' => $normalized['recipient'],
                'category' => $categorization['category'],
                'category_confidence' => $categorization['confidence'],
            ];
        }

        return DB::transaction(function () use ($file, $preparedTransactions, $minDate, $maxDate) {
            $statement = Statement::create([
                // Без авторизации всегда null (демо-бакет) — это ожидаемо.
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'file_type' => strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)),
                'period_from' => $minDate,
                'period_to' => $maxDate,
                'transactions_count' => count($preparedTransactions),
            ]);

            $now = now();
            $toInsert = array_map(function (array $row) use ($statement, $now) {
                $row['statement_id'] = $statement->id;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            }, $preparedTransactions);

            // Bulk insert РїР°С‡РєР°РјРё РїРѕ 500
            foreach (array_chunk($toInsert, 500) as $chunk) {
                Transaction::insert($chunk);
            }

            return [
                'statement' => $statement->fresh(),
                'imported_transactions_count' => count($preparedTransactions),
                'error' => null,
            ];
        });
    }
}
