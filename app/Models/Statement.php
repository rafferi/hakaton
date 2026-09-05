<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Statement extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_name',
        'file_type',
        'period_from',
        'period_to',
        'transactions_count',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'transactions_count' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function aiInsights(): HasMany
    {
        return $this->hasMany(AiInsight::class);
    }
}

