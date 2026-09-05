<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StatementTransactionsRequest;
use App\Http\Requests\StatementUploadRequest;
use App\Http\Resources\StatementResource;
use App\Http\Resources\TransactionResource;
use App\Models\Statement;
use App\Services\AnalyticsService;
use App\Services\StatementImportService;
use App\Services\TransactionQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StatementController extends Controller
{
    public function __construct(
        private StatementImportService $importService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $statements = Statement::query()
            ->withCount('transactions')
            ->orderByDesc('id')
            ->paginate(20);

        return StatementResource::collection($statements);
    }

    public function show(Statement $statement): StatementResource
    {
        return new StatementResource(
            $statement->loadCount('transactions')
        );
    }

    public function destroy(Statement $statement): JsonResponse
    {
        $statement->delete();

        return response()->json([
            'message' => 'Statement deleted.',
            'id' => $statement->id,
        ]);
    }

    public function analytics(Statement $statement, AnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->analyze($statement));
    }

    public function transactions(
        Statement $statement,
        StatementTransactionsRequest $request,
        TransactionQueryService $query,
    ): AnonymousResourceCollection {
        return TransactionResource::collection(
            $query->paginate($statement, $request->validated())
        );
    }

    public function upload(StatementUploadRequest $request): JsonResponse
    {
        $result = $this->importService->import($request->file('file'));

        if ($result['statement'] === null) {
            return response()->json([
                'message' => 'No transactions found in the uploaded file.',
            ], 422);
        }

        return response()->json([
            'data' => new StatementResource($result['statement']),
            'imported_transactions_count' => $result['imported_transactions_count'],
        ], 201);
    }
}
