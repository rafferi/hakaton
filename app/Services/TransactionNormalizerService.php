<?php

declare(strict_types=1);

namespace App\Services;

class TransactionNormalizerService
{
    /**
     * @param  array{date:string, amount:float, type:string, description:string}  $rawTransaction
     * @return array{normalized_description:string, merchant:?string, recipient:?string}
     */
    public function normalize(array $rawTransaction): array
    {
        $description = $rawTransaction['description'] ?? '';
        $type = $rawTransaction['type'] ?? 'debit';

        $normalized = $this->normalizeDescription($description);
        $merchant = $this->extractMerchant($normalized, $type);
        $recipient = $this->extractRecipient($normalized, $type);

        return [
            'normalized_description' => $normalized,
            'merchant' => $merchant,
            'recipient' => $recipient,
        ];
    }

    private function normalizeDescription(string $description): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $description);
        $normalized = trim($normalized, " \t\n\r\0\x0B.,;:-");

        return $normalized;
    }

    private function extractMerchant(string $description, string $type): ?string
    {
        if ($type === 'credit' || $type === 'transfer') {
            return null;
        }

        $patterns = [
            '/^покупка\s+/iu',
            '/^оплата\s+/iu',
            '/^purchase\s+/iu',
            '/^payment\s+/iu',
            '/^trn\s*:\s*/i',
            '/^card\s+\d+\s*/i',
        ];

        $cleaned = $description;
        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        // Используем # как разделитель вместо / чтобы избежать конфликтов
        if (preg_match('#^([A-Za-zА-Яа-я0-9\s\.\&\-]+?)(?:\s*[/,|;]\s*|$)#u', trim($cleaned), $m)) {
            $merchant = trim($m[1]);

            if (mb_strlen($merchant) > 3 && mb_strlen($merchant) < 100) {
                return $merchant;
            }
        }

        return null;
    }

    private function extractRecipient(string $description, string $type): ?string
    {
        if ($type !== 'transfer') {
            return null;
        }

        // Используем # как разделитель
        if (preg_match('#(?:перевод|перечисление|sbp|сбп|p2p|п2п)\s+(?:.*?[\s,;])?([А-Яа-яA-Za-z\s\.]+?)(?:\s*[0-9\*]{4,})?$#iu', $description, $m)) {
            $recipient = trim($m[1]);

            if (mb_strlen($recipient) > 1 && mb_strlen($recipient) < 100) {
                return $recipient;
            }
        }

        return null;
    }
}
