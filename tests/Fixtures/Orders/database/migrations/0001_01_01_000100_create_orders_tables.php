<?php

declare(strict_types=1);

use Distributable\Migrations\ShadowMigration;
use Distributable\Tests\Fixtures\Orders\Models\CustomerShadow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->integer('total');
            $table->string('note')->default('');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->integer('amount');
        });

        Schema::create('paid_orders', function (Blueprint $table): void {
            $table->integer('order_id');
        });

        (new class extends ShadowMigration
        {
            protected function shadow(): string
            {
                return CustomerShadow::class;
            }

            public function up(): void
            {
                Schema::create($this->table(), function (Blueprint $table): void {
                    $table->unsignedBigInteger('id')->primary();
                    $table->string('name');
                    $table->softDeletes();
                });
            }
        })->up();
    }
};
