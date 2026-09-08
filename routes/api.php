<?php

declare(strict_types=1);

use App\Http\Controllers\AiInsightController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StatementController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('statements')->group(function () {
        Route::get('/', [StatementController::class, 'index']);
        Route::post('/upload', [StatementController::class, 'upload']);
        Route::get('/{statement}', [StatementController::class, 'show']);
        Route::get('/{statement}/analytics', [StatementController::class, 'analytics']);
        Route::get('/{statement}/transactions', [StatementController::class, 'transactions']);
        Route::post('/{statement}/transactions/manual', [StatementController::class, 'storeManualTransaction']);
        Route::get('/{statement}/ai/insights', [AiInsightController::class, 'insights']);
        Route::post('/{statement}/ai/analyze', [AiInsightController::class, 'analyze']);
        Route::post('/{statement}/savings-plan', [AiInsightController::class, 'savingsPlan']);
        Route::post('/{statement}/receipts/scan', [ReceiptController::class, 'scan']);
        Route::post('/{statement}/receipts/confirm', [ReceiptController::class, 'confirm']);
        Route::delete('/{statement}', [StatementController::class, 'destroy']);
        Route::get('/{statement}/report/pdf', [ReportController::class, 'statementPdf']);
    });

    Route::post('/chat', [ChatController::class, 'reply'])->middleware('throttle:20,1');

    Route::get('/profile/trend', [ProfileController::class, 'trend']);
    Route::get('/profile/mandatory-expenses', [ProfileController::class, 'mandatoryExpenses']);

    Route::get('/categories', [CategoryController::class, 'index']);
});
