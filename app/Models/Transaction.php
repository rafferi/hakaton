<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'statement_id',
        'date',
        'amount',
        'type',
        'raw_description',
        'normalized_description',
        'merchant',
        'recipient',
        'category',
        'category_confidence',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'category_confidence' => 'decimal:4',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
    }
}
