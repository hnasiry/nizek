<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('stock_prices_tmp', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('traded_on');
            $table->bigInteger('price');

            $table->unique(['company_id', 'traded_on']);
            $table->index('traded_on');
        });

        DB::table('stock_prices')
            ->orderBy('id')
            ->lazy()
            ->each(function ($row): void {
                $price = bcmul((string) $row->price, '1000000', 0);

                DB::table('stock_prices_tmp')->insert([
                    'id' => $row->id,
                    'company_id' => $row->company_id,
                    'traded_on' => $row->traded_on,
                    'price' => $price,
                ]);
            });

        Schema::drop('stock_prices');
        Schema::rename('stock_prices_tmp', 'stock_prices');

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('stock_prices_tmp', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('traded_on');
            $table->decimal('price', 18, 6);

            $table->unique(['company_id', 'traded_on']);
            $table->index('traded_on');
        });

        DB::table('stock_prices')
            ->orderBy('id')
            ->lazy()
            ->each(function ($row): void {
                $price = bcdiv((string) $row->price, '1000000', 6);

                DB::table('stock_prices_tmp')->insert([
                    'id' => $row->id,
                    'company_id' => $row->company_id,
                    'traded_on' => $row->traded_on,
                    'price' => $price,
                ]);
            });

        Schema::drop('stock_prices');
        Schema::rename('stock_prices_tmp', 'stock_prices');

        Schema::enableForeignKeyConstraints();
    }
};
