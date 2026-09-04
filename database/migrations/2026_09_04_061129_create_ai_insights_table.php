<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::create('ai_insights', function (Blueprint $table) use ($driver) {
            $table->id();

            $table->foreignId('statement_id')
                ->constrained('statements')
                ->cascadeOnDelete();

            $table->string('type', 50)->index();
            $table->string('title');
            $table->text('description')->nullable();

            if ($driver === 'pgsql') {
                $table->jsonb('data')->default(DB::raw("'{}'::jsonb"));
            } else {
                $table->json('data')->default(DB::raw("'{}'"));
            }

            $table->decimal('potential_saving', 15, 2)->nullable();

            $table->timestamps();

            $table->index(['statement_id', 'type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE ai_insights
                ADD CONSTRAINT ai_insights_potential_saving_non_negative
                CHECK (
                    potential_saving IS NULL
                    OR potential_saving >= 0
                );
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
