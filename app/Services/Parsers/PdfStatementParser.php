<?php

declare(strict_types=1);

namespace App\Services\Parsers;

use App\Contracts\StatementParserInterface;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfTextParser;

class PdfStatementParser implements StatementParserInterface
{
    private const MAX_LINES = 50000;

    /**
     * Паттерн «кандидат транзакции»: строка, в которой есть дата
     * ДД.ММ.ГГГГ (или YYYY-MM-DD) И число-сумма после описания.
     *
     * smalot/pdfparser может выдавать:
     *  - склейку:  "05.03.2026Покупка 1 500,00Пополнение"
     *  - с табами: "05.03.2026Покупка→TAB→1 500,00 Списание"
     *  - с пробелами: "05.03.2026  Покупка  1 500,00"
     *
     * Сумма НЕ обязательно в конце строки — после неё может быть
     * ещё колонка (Тип и т.п.). Поэтому lookahead вместо $.
     */
    private const CANDIDATE_PATTERN = '/'
        .'(\d{2}\.\d{2}\.\d{4}'            // ДД.ММ.ГГГГ
        .'|\d{4}-\d{2}-\d{2})'             // ИЛИ YYYY-MM-DD
        .'\s*'                              // пробелы/склейка между датой и описанием
        .'.+?'                              // описание (ленивое)
        .'\s*'                              // пробелы/табы перед суммой
        .'[-+]?\s*[\d ]+[.,]\d{2}'         // сумма
        .'(?=\s|$|[^\d])'                  // после суммы: пробел, конец строки, или не-цифра
        .'/u';

    /**
     * Паттерн суммы: «1 500,00» / «-350,00» / «1234.56»
     * Ищет сумму ЛЮБОГДЕ в строке (не только в конце).
     * Lookahead: после суммы — пробел, не-цифра, или конец строки.
     */
    private const AMOUNT_PATTERN = '/[-+]?\s*([\d ]+)[.,](\d{2})(?=\s|$|[^\d])/u';

    /** Строки-заголовки/итоги, которые НЕ являются транзакциями */
    private const SKIP_KEYWORDS = [
        'итого',
        'всего',
        'остаток',
        'баланс',
        'дата описание',
        'дата операции',
        'назначение',
        'период',
        'выписка',
        'счёт',
        'card',
        'account',
    ];

    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('PDF file is not readable.');
        }

        try {
            $text = (new PdfTextParser)->parseFile($path)->getText();
        } catch (\Throwable) {
            return ['transactions' => [], 'data_rows' => 0];
        }

        if (trim((string) $text) === '') {
            return ['transactions' => [], 'data_rows' => 0];
        }

        $transactions = [];
        $dataRows = 0;
        $lineCount = 0;

        foreach (preg_split('/\R/', (string) $text) ?: [] as $rawLine) {
            $line = trim((string) $rawLine);

            if ($line === '') {
                continue;
            }

            $lineCount++;

            if ($lineCount > self::MAX_LINES) {
                break;
            }

            // Пропускаем строки-заголовки/итоги — они НЕ считаются data_rows.
            if ($this->isSkippableLine($line)) {
                continue;
            }

            // Считаем кандидатом, только если строка содержит и дату, и число в конце.
            if (! $this->isCandidateLine($line)) {
                continue;
            }

            $dataRows++;

            $transaction = $this->parseCandidateLine($line);

            if ($transaction !== null) {
                $transactions[] = $transaction;
            }
        }

        return ['transactions' => $transactions, 'data_rows' => $dataRows];
    }

    private function isSkippableLine(string $line): bool
    {
        $lower = mb_strtolower(trim($line));

        foreach (self::SKIP_KEYWORDS as $keyword) {
            if (str_starts_with($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Быстрая проверка: строка содержит дату ДД.ММ.ГГГГ или YYYY-MM-DD
     * И число в конце строки (сумма транзакции).
     */
    private function isCandidateLine(string $line): bool
    {
        return (bool) preg_match(self::CANDIDATE_PATTERN, $line);
    }

    /**
     * Разбирает строку-кандидат на date, description, amount.
     *
     * @return array{date: string, amount: float, type: string, description: string}|null
     */
    private function parseCandidateLine(string $line): ?array
    {
        // 1) Извлекаем дату из строки.
        $date = $this->extractDate($line);
        if ($date === null) {
            return null;
        }

        // 2) Извлекаем сумму из конца строки.
        $amount = $this->extractAmountFromEnd($line);
        if ($amount === null) {
            return null;
        }

        // 3) Всё между датой и суммой — описание.
        $description = $this->extractDescription($line, $date, $amount);

        return [
            'date' => $date,
            'amount' => $amount,
            'type' => $this->determineType($amount, $description),
            'description' => $description,
        ];
    }

    private function extractDate(string $line): ?string
    {
        // DD.MM.YYYY
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $line, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        // YYYY-MM-DD
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $line, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Извлекает сумму из строки.
     * Ищет ВСЕ совпадения AMOUNT_PATTERN и берёт ПОСЛЕДНЕЕ (самое правое) —
     * сумма всегда после даты и описания, а дата (08.2026) может ошибочно
     *матчиться как сумма, т.к. тоже содержит "[digits].[digits]".
     */
    private function extractAmountFromEnd(string $line): ?float
    {
        $allMatches = [];
        preg_match_all(self::AMOUNT_PATTERN, $line, $allMatches, PREG_SET_ORDER);

        if ($allMatches === []) {
            return null;
        }

        // Берём последний match (самый правый в строке).
        $m = end($allMatches);

        $integerPart = $m[1];
        $fractionPart = $m[2];

        // PDF-таблицы без пробелов: год из описания склеивается с суммой.
        if (preg_match('/^\d{4}\s/', $integerPart)) {
            $integerPart = preg_replace('/^\d{4}\s/', '', $integerPart);
        }

        $raw = str_replace(' ', '', $integerPart).'.'.$fractionPart;

        $sign = '';
        if (preg_match('/^[-\s]+/', $m[0], $signMatch) && str_contains($signMatch[0], '-')) {
            $sign = '-';
        }

        return (float) ($sign.$raw);
    }

    private function extractDescription(string $line, string $date, float $amount): string
    {
        // Убираем дату из начала (дата может быть склеена с описанием).
        $withoutDate = preg_replace('/^\s*\d{2}\.\d{2}\.\d{4}\s*/', '', $line);
        $withoutDate = preg_replace('/^\s*\d{4}-\d{2}-\d{2}\s*/', '', $withoutDate);

        // Убираем ПОСЛЕДНЕЕ вхождение суммы (самое правое — это реальная сумма).
        $result = preg_replace('/[-+]?\s*[\d ]+[.,]\d{2}\s*(?=\s*$|\s[^\d]|\s$)/u', ' ', $withoutDate);

        return trim($result);
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        return null;
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
