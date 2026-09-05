<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statements', function (Blueprint $table) {
            $table->id();

            $table->string('file_name');
            $table->string('file_type', 20)->index();

            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            $table->unsignedInteger('transactions_count')->default(0);

            $table->timestamps();

            $table->index(['period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statements');
    }
};

