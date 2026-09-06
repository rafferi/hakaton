<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavingsPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Только форма: required|numeric|>0. Бизнес-правило
     * "target <= total_expenses" проверяет SavingsPlanService
     * (ему всё равно нужна аналитика для плана) и отвечает 422
     * с текстом из ТЗ.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_monthly_saving' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_monthly_saving.required' => 'Укажите целевую сумму месячной экономии.',
            'target_monthly_saving.numeric' => 'Целевая сумма должна быть числом.',
            'target_monthly_saving.min' => 'Целевая сумма должна быть больше 0.',
        ];
    }
}
