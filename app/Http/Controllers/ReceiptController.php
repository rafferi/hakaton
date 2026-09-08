<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiServiceException;
use App\Http\Requests\ReceiptConfirmRequest;
use App\Http\Requests\ReceiptScanRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Statement;
use App\Services\AI\ReceiptScanService;
use Illuminate\Http\JsonResponse;

class ReceiptController extends Controller
{
    /**
     * POST /api/statements/{statement}/receipts/scan — превью, без записи в БД.
     */
    public function scan(
        Statement $statement,
        ReceiptScanRequest $request,
        ReceiptScanService $scanner,
    ): JsonResponse {
        $statement = Statement::forCurrentUser()->findOrFail($statement->id);

        try {
            $preview = $scanner->scan($request->file('receipt'));
        } catch (AiServiceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatus());
        }

        return response()->json($preview);
    }

    /**
     * POST /api/statements/{statement}/receipts/confirm — запись транзакции.
     */
    public function confirm(
        Statement $statement,
        ReceiptConfirmRequest $request,
        ReceiptScanService $scanner,
    ): JsonResponse {
        $statement = Statement::forCurrentUser()->findOrFail($statement->id);

        $transaction = $scanner->confirm($statement, $request->validated());

        return response()->json([
            'data' => new TransactionResource($transaction),
            'message' => 'Транзакция добавлена',
        ], 201);
    }
}
