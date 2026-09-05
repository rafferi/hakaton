<?php

declare(strict_types=1);

namespace App\Services\Parsers;

use App\Contracts\StatementParserInterface;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class CsvStatementParser implements StatementParserInterface
{
    private const MAX_ROWS = 50000;

    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('CSV file is not readable.');
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Cannot open CSV file.');
        }

        try {
            return $this->parseHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    private function parseHandle($handle): array
    {
        // Удаляем BOM, если есть
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            return [];
        }

        $firstLine = $this->normalizeEncoding($firstLine);
        $delimiter = $this->detectDelimiter($firstLine);

        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") {
            fseek($handle, 3);
        }

        // Читаем заголовки
        $headerLine = fgetcsv($handle, 0, $delimiter);
        if ($headerLine === false) {
            return [];
        }

        $header = array_map(
            fn ($h) => strtolower(trim($this->normalizeEncoding((string) $h))),
            $headerLine
        );

        $columnMap = $this->mapColumns($header);

        $transactions = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            if ($rowNumber > self::MAX_ROWS) {
                break;
            }

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $transaction = $this->buildTransaction($row, $columnMap);

            if ($transaction !== null) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    private function normalizeEncoding(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
        }

        return $text;
    }

    private function detectDelimiter(string $line): string
    {
        $delimiters = [';', ',', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $d) {
            $counts[$d] = substr_count($line, $d);
        }

        arsort($counts);
        $best = array_key_first($counts);

        return ($counts[$best] > 0) ? $best : ',';
    }

    private function mapColumns(array $header): array
    {
        $dateKeywords = ['date', 'дата', 'operation_date', 'дата операции'];
        $amountKeywords = ['amount', 'sum', 'сумма', 'amount_rub', 'сумма операции'];
        $descriptionKeywords = ['description', 'desc', 'описание', 'details', 'детали', 'назначение'];

        $map = ['date' => null, 'amount' => null, 'description' => null];

        foreach ($header as $index => $name) {
            foreach ($dateKeywords as $kw) {
                if (str_contains($name, $kw)) {
                    $map['date'] = $index;
                    break 2;
                }
            }
        }

        foreach ($header as $index => $name) {
            foreach ($amountKeywords as $kw) {
                if (str_contains($name, $kw)) {
                    $map['amount'] = $index;
                    break 2;
                }
            }
        }

        foreach ($header as $index => $name) {
            foreach ($descriptionKeywords as $kw) {
                if (str_contains($name, $kw)) {
                    $map['description'] = $index;
                    break 2;
                }
            }
        }

        // Фоллбэк: если заголовки не распознаны, предполагаем порядок
        if ($map['date'] === null) {
            $map['date'] = 0;
        }
        if ($map['description'] === null) {
            $map['description'] = count($header) >= 2 ? 1 : 0;
        }
        if ($map['amount'] === null) {
            $map['amount'] = count($header) >= 3 ? 2 : count($header) - 1;
        }

        return $map;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function buildTransaction(array $row, array $columnMap): ?array
    {
        $rawDate = $this->normalizeEncoding((string) ($row[$columnMap['date']] ?? ''));
        $rawDescription = $this->normalizeEncoding((string) ($row[$columnMap['description']] ?? ''));
        $rawAmount = $this->normalizeEncoding((string) ($row[$columnMap['amount']] ?? ''));

        $date = $this->parseDate($rawDate);
        if ($date === null) {
            return null;
        }

        $amount = $this->parseAmount($rawAmount);
        if ($amount === null) {
            return null;
        }

        $type = $this->determineType($amount, $rawDescription);

        return [
            'date' => $date,
            'amount' => $amount,
            'type' => $type,
            'description' => trim($rawDescription),
        ];
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // DD.MM.YYYY → YYYY-MM-DD
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        // YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        // DD/MM/YYYY
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        try {
            $dt = new \DateTime($raw);

            return $dt->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private function parseAmount(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Убираем пробелы и символы валют
        $raw = preg_replace('/[^\d,\.\-\+]/u', '', $raw);

        // Если есть и запятая, и точка — запятая считается разделителем дробной части
        if (strpos($raw, ',') !== false && strpos($raw, '.') !== false) {
            $raw = str_replace('.', '', $raw);
        }

        $raw = str_replace(',', '.', $raw);

        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function determineType(float $amount, string $description): string
    {
        $desc = mb_strtolower($description);

        if (str_contains($desc, 'перевод') || str_contains($desc, 'перечисление') || str_contains($desc, 'sbp') || str_contains($desc, 'сбп') || str_contains($desc, 'p2p') || str_contains($desc, 'п2п')) {
            return 'transfer';
        }

        return $amount >= 0 ? 'credit' : 'debit';
    }
}
