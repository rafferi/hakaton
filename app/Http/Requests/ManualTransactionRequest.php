<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ручной ввод дохода/расхода — переводы (transfer) этим путём запрещены.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:debit,credit'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Укажите дату.',
            'date.date_format' => 'Дата должна быть в формате ГГГГ-ММ-ДД.',
            'description.required' => 'Укажите описание.',
            'amount.required' => 'Укажите сумму.',
            'amount.numeric' => 'Сумма должна быть числом.',
            'amount.min' => 'Сумма должна быть положительной.',
            'category.required' => 'Укажите категорию.',
            'type.in' => 'Тип должен быть debit или credit.',
        ];
    }
}
