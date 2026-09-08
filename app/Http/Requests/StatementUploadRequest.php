<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StatementUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Осознанно используем extensions вместо mimes: правило mimes проверяет
        // MIME-тип через finfo по содержимому, и реальные Excel/Windows-экспорты
        // CSV (разделитель ";", BOM, кодировка Windows-1251) на разных машинах
        // определяются как application/vnd.ms-excel или application/octet-stream,
        // из-за чего легитимные выписки отклонялись. Проверка расширения
        // стабильна на всех платформах, а от переименованных бинарников
        // защищает парсер: CsvStatementParser читает только текстовые строки
        // через fgetcsv, и файл без валидных строк отклоняется с 422
        // "No transactions found in the uploaded file."
        return [
            'file' => [
                'required',
                'file',
                'extensions:csv,txt,pdf',
                'max:10240', // 10MB в KB — PDF обычно тяжелее CSV
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Файл выписки обязателен для загрузки.',
            'file.file' => 'Загружаемый объект должен быть файлом.',
            'file.extensions' => 'Допустимы файлы формата CSV или PDF.',
            'file.max' => 'Максимальный размер файла — 10 МБ.',
        ];
    }
}
