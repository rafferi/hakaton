<?php

use App\Models\Statement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

test('register validates email and password', function () {
    $badEmail = $this->postJson('/api/auth/register', [
        'email' => 'not-an-email',
        'password' => 'password123',
    ]);
    $badEmail->assertStatus(422);
    $badEmail->assertJsonValidationErrors('email');

    $shortPassword = $this->postJson('/api/auth/register', [
        'email' => 'user@example.com',
        'password' => 'short',
    ]);
    $shortPassword->assertStatus(422);
    $shortPassword->assertJsonValidationErrors('password');

    expect(User::where('email', 'user@example.com')->exists())->toBeFalse();
});

test('register creates user with token and defaults name from email', function () {
    $response = $this->postJson('/api/auth/register', [
        'email' => 'ivan@example.com',
        'password' => 'password123',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('user.email', 'ivan@example.com');
    $response->assertJsonPath('user.name', 'ivan');
    expect($response->json('token'))->toBeString();

    // Явный name сохраняется как есть.
    $named = $this->postJson('/api/auth/register', [
        'name' => 'Иван',
        'email' => 'ivan2@example.com',
        'password' => 'password123',
    ]);
    $named->assertCreated();
    $named->assertJsonPath('user.name', 'Иван');
});

test('login succeeds with correct credentials', function () {
    User::factory()->create([
        'email' => 'login@example.com',
        'password' => 'secret123',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'login@example.com',
        'password' => 'secret123',
    ]);

    $response->assertOk();
    $response->assertJsonPath('user.email', 'login@example.com');
    expect($response->json('token'))->toBeString();
});

test('login with wrong password returns 422 without revealing the field', function () {
    User::factory()->create([
        'email' => 'login2@example.com',
        'password' => 'secret123',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'login2@example.com',
        'password' => 'wrongpass',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.email.0', 'Неверный email или пароль.');
});

test('me returns user with token and 401 without', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);

    // Сначала без токена: withToken() ниже прилипает к заголовкам
    // всего теста, поэтому порядок важен.
    $this->getJson('/api/auth/me')->assertUnauthorized();

    $withToken = $this->withToken($user->createToken('api')->plainTextToken)
        ->getJson('/api/auth/me');

    $withToken->assertOk();
    $withToken->assertJsonPath('user.email', 'me@example.com');
    $withToken->assertJsonPath('user.name', $user->name);
});

test('protected routes require token', function () {
    $this->getJson('/api/statements')->assertUnauthorized();
    $this->getJson('/api/profile/trend')->assertUnauthorized();
    $this->getJson('/api/categories')->assertUnauthorized();
});

test('user sees only own statements', function () {
    $user = actingAsNewUser();

    Statement::create([
        'user_id' => $user->id,
        'file_name' => 'mine.csv',
        'file_type' => 'csv',
    ]);
    Statement::create([
        'user_id' => null,
        'file_name' => 'demo.csv',
        'file_type' => 'csv',
    ]);

    $response = $this->getJson('/api/statements');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.file_name', 'mine.csv');
});

test('logout revokes current token', function () {
    $register = $this->postJson('/api/auth/register', [
        'email' => 'logout@example.com',
        'password' => 'password123',
    ]);
    $register->assertCreated();

    $token = $register->json('token');

    $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

    // В тестах все HTTP-вызовы делят один application-инстанс, а sanctum —
    // это RequestGuard с мемоизированным user. forgetGuards() сбрасывает
    // закешированные гарды и эмулирует свежий HTTP-запрос (как в проде).
    Auth::forgetGuards();

    // Тот же токен больше не работает.
    $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
});

test('user cannot access another users statement', function () {
    $owner = actingAsNewUser(['email' => 'owner@example.com']);

    $statement = Statement::create([
        'user_id' => $owner->id,
        'file_name' => 'owner.csv',
        'file_type' => 'csv',
    ]);

    // Переключаемся на пользователя B: чужая выписка — 404, не 200.
    Sanctum::actingAs(User::factory()->create(['email' => 'intruder@example.com']));

    $this->getJson("/api/statements/{$statement->id}")->assertNotFound();
    $this->getJson("/api/statements/{$statement->id}/analytics")->assertNotFound();
    $this->getJson("/api/statements/{$statement->id}/transactions")->assertNotFound();
    $this->getJson("/api/statements/{$statement->id}/ai/insights")->assertNotFound();
    $this->deleteJson("/api/statements/{$statement->id}")->assertNotFound();

    // Выписка на месте: владелец по-прежнему видит её (и удаление не сработало).
    // Здесь forgetGuards() не нужен: actingAs ставит user напрямую на гард,
    // токенов в игре нет (в отличие от logout-теста с реальным Bearer).
    Sanctum::actingAs($owner);

    $this->getJson("/api/statements/{$statement->id}")->assertOk();
    expect(Statement::whereKey($statement->id)->exists())->toBeTrue();
});
