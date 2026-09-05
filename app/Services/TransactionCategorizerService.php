<?php

declare(strict_types=1);

namespace App\Services;

class TransactionCategorizerService
{
    /**
     * @return array{category:string, confidence:float}
     */
    public function categorize(string $description): array
    {
        $rules = config('categories.rules', []);
        $default = config('categories.default', [
            'category' => 'Прочее',
            'confidence' => 0.3,
        ]);

        $normalized = mb_strtolower($description);

        foreach ($rules as $category => $keywords) {
            foreach ((array) $keywords as $keyword) {
                $keyword = mb_strtolower((string) $keyword);

                if ($keyword !== '' && str_contains($normalized, $keyword)) {
                    return [
                        'category' => $category,
                        'confidence' => 0.9,
                    ];
                }
            }
        }

        return [
            'category' => $default['category'] ?? 'Прочее',
            'confidence' => (float) ($default['confidence'] ?? 0.3),
        ];
    }
}
