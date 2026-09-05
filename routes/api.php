<?php

declare(strict_types=1);

use App\Http\Controllers\AiInsightController;
use App\Http\Controllers\StatementController;
use Illuminate\Support\Facades\Route;

Route::prefix('statements')->group(function () {
    Route::get('/', [StatementController::class, 'index']);
    Route::post('/upload', [StatementController::class, 'upload']);
    Route::get('/{statement}', [StatementController::class, 'show']);
    Route::get('/{statement}/analytics', [StatementController::class, 'analytics']);
    Route::get('/{statement}/transactions', [StatementController::class, 'transactions']);
    Route::get('/{statement}/ai/insights', [AiInsightController::class, 'insights']);
    Route::post('/{statement}/ai/analyze', [AiInsightController::class, 'analyze']);
    Route::delete('/{statement}', [StatementController::class, 'destroy']);
});
