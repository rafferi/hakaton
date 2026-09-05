<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'amount' => (float) $this->amount,
            'type' => $this->type,
            'description' => $this->normalized_description ?? $this->raw_description,
            'merchant' => $this->merchant,
            'recipient' => $this->recipient,
            'category' => $this->category,
            'category_confidence' => $this->category_confidence !== null
                ? (float) $this->category_confidence
                : null,
        ];
    }
}
