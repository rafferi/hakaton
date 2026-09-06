<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Проверка по РАСШИРЕНИЮ, а не по MIME-содержимому (урок истории
     * проекта: finfo на Windows/мобильных экспортах даёт непредсказуемые
     * vnd.ms-excel/octet-stream). HEIC пропущен валидацией, но самим
     * GigaChat API не поддерживается (только jpeg/png/tiff/bmp) —
     * такой файл будет отклонён уже на шаге upload с понятной 422.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'receipt' => [
                'required',
                'file',
                'extensions:jpg,jpeg,png,heic',
                'max:10240',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipt.required' => 'Приложите фотографию чека.',
            'receipt.file' => 'Загружаемый объект должен быть файлом.',
            'receipt.extensions' => 'Допустимы только изображения (JPG, PNG).',
            'receipt.max' => 'Максимальный размер файла — 10 МБ.',
        ];
    }
}
