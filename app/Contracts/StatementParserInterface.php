<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface StatementParserInterface
{
    /**
     * Парсит файл выписки.
     *
     * Возвращает не только сырые транзакции, но и количество строк данных
     * (data_rows) — после пропуска заголовка/BOM и пустых строк. Это нужно,
     * чтобы отличить пустой файл (0 строк) от файла, где есть строки,
     * но ни одна не распозналась (проблема формата).
     *
     * @return array{
     *     transactions: array<int, array{
     *         date: string,
     *         amount: float,
     *         type: string,
     *         description: string
     *     }>,
     *     data_rows: int
     * }
     */
    public function parse(UploadedFile $file): array;
}
