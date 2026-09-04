<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInsight extends Model
{
    use HasFactory;

    protected $fillable = [
        'statement_id',
        'type',
        'title',
        'description',
        'data',
        'potential_saving',
    ];

    protected $casts = [
        'data' => 'array',
        'potential_saving' => 'decimal:2',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
    }
}
