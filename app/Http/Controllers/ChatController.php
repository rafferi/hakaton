<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiServiceException;
use App\Http\Requests\ChatRequest;
use App\Services\AI\ChatService;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    /**
     * POST /api/chat — персональный финансовый чат-консультант.
     */
    public function reply(ChatRequest $request, ChatService $chat): JsonResponse
    {
        try {
            $reply = $chat->reply(
                (string) $request->validated()['message'],
                $request->validated()['history'] ?? []
            );
        } catch (AiServiceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatus());
        }

        return response()->json([
            'reply' => $reply,
        ]);
    }
}
