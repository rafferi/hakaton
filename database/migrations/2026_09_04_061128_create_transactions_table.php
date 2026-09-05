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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('statement_id')
                ->constrained('statements')
                ->cascadeOnDelete();

            $table->date('date');

            $table->decimal('amount', 15, 2);

            $table->string('type', 30)->nullable();

            $table->text('raw_description');
            $table->text('normalized_description')->nullable();

            $table->string('merchant')->nullable();
            $table->string('recipient')->nullable();

            $table->string('category')->nullable();
            $table->decimal('category_confidence', 5, 4)->nullable();

            $table->timestamps();

            $table->index(['statement_id', 'date']);
            $table->index('date');
            $table->index('category');
            $table->index('merchant');
            $table->index('type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE transactions
                ADD CONSTRAINT transactions_category_confidence_range
                CHECK (
                    category_confidence IS NULL
                    OR (
                        category_confidence >= 0
                        AND category_confidence <= 1
                    )
                );
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

