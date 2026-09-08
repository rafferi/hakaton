<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/register — регистрация + сразу токен.
     *
     * Пустой name подменяется локальной частью email.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            $name = (string) str($data['email'])->before('@');
        }

        $user = User::create([
            'name' => $name,
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->userPayload($user),
        ], 201);
    }

    /**
     * POST /api/auth/login — неверные данные всегда 422 с одним
     * сообщением, не раскрываем, какое поле неверно.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check((string) $data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Неверный email или пароль.',
            ]);
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * POST /api/auth/logout — отзывает только текущий токен.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Вы вышли из системы.',
        ]);
    }

    /**
     * GET /api/auth/me — текущий пользователь по Bearer-токену.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * @return array{id: int, email: string, name: string}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
        ];
    }
}
