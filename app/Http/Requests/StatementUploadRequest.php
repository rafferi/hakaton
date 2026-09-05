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
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:5120', // 5MB в KB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Файл выписки обязателен для загрузки.',
            'file.file' => 'Загружаемый объект должен быть файлом.',
            'file.mimes' => 'Допустимы только файлы формата CSV.',
            'file.max' => 'Максимальный размер файла — 5 МБ.',
        ];
    }
}
