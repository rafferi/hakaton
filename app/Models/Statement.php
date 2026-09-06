<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Statement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
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

    /**
     * Центральная точка per-user фильтрации (структурная готовность
     * к будущей авторизации, без её реализации).
     *
     * Когда авторизация будет подключена, этот метод автоматически
     * начнёт фильтровать per-user, без изменений в вызывающем коде:
     * авторизованный видит только свои выписки, неавторизованный —
     * демо-бакет (user_id IS NULL), куда сейчас попадают все данные.
     */
    public function scopeForCurrentUser(Builder $query): Builder
    {
        if (auth()->check()) {
            return $query->where('user_id', auth()->id());
        }

        return $query->whereNull('user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function aiInsights(): HasMany
    {
        return $this->hasMany(AiInsight::class);
    }
}
