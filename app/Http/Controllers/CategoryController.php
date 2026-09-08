<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * GET /api/categories — справочник категорий из конфига.
     * income — явный список config income.categories; expense — все
     * остальные categories.rules. Без бизнес-логики, только форматирование.
     */
    public function index(): JsonResponse
    {
        /** @var array<string, list<string>> $rules */
        $rules = config('categories.rules', []);
        /** @var list<string> $income */
        $income = config('categories.income.categories', []);

        $incomeNormalized = array_map(
            fn ($name): string => mb_strtolower(trim((string) $name)),
            $income
        );

        $expense = [];
        $incomeOut = [];

        foreach (array_keys($rules) as $category) {
            $category = (string) $category;

            if (in_array(mb_strtolower(trim($category)), $incomeNormalized, true)) {
                $incomeOut[] = $category;
            } else {
                $expense[] = $category;
            }
        }

        return response()->json([
            'expense' => array_values($expense),
            'income' => array_values($incomeOut),
        ]);
    }
}
