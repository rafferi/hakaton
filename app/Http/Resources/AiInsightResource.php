<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiInsightResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'data' => $this->data ?? [],
            'potential_saving' => $this->potential_saving !== null
                ? (float) $this->potential_saving
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
