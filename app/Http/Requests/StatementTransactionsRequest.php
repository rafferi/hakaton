<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StatementTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:30'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Порядок диапазона проверяется только когда заданы обе границы,
     * одиночные date_from / date_to и amount_min / amount_max валидны сами по себе.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();

            if (! empty($data['date_from']) && ! empty($data['date_to']) && $data['date_to'] < $data['date_from']) {
                $validator->errors()->add('date_to', 'Дата окончания должна быть не раньше даты начала.');
            }

            if (isset($data['amount_min'], $data['amount_max']) && $data['amount_min'] !== null && $data['amount_max'] !== null && $data['amount_max'] < $data['amount_min']) {
                $validator->errors()->add('amount_max', 'Максимальная сумма должна быть не меньше минимальной.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.date_format' => 'Дата начала должна быть в формате ГГГГ-ММ-ДД.',
            'date_to.date_format' => 'Дата окончания должна быть в формате ГГГГ-ММ-ДД.',
            'amount_min.numeric' => 'Минимальная сумма должна быть числом.',
            'amount_max.numeric' => 'Максимальная сумма должна быть числом.',
            'sort.in' => 'Сортировка должна быть asc или desc.',
            'per_page.max' => 'Максимум 100 записей на страницу.',
        ];
    }
}
