<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('stock_import_id')->nullable()->constrained('stock_imports')->nullOnDelete();
            $table->date('traded_on');
            $table->decimal('price', 16, 4);
            $table->timestamps();

            $table->unique(['company_id', 'traded_on']);
            $table->index('traded_on');
            $table->index('stock_import_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_prices');
    }
};
