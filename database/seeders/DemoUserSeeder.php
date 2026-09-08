<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Statement;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    /**
     * Демо-пользователь + перенос бесхозных выписок.
     *
     * Идемпотентен: firstOrCreate не дублирует пользователя,
     * а UPDATE затрагивает только statements с user_id IS NULL
     * (ai_insights привязаны к statements — переезжают сами).
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@finbalance.ru'],
            [
                'name' => 'demo',
                'password' => 'demo12345',
            ]
        );

        Statement::whereNull('user_id')->update(['user_id' => $user->id]);
    }
}
