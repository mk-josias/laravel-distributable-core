<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->integer('amount');
            $table->string('note')->default('');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->integer('amount');
        });

        Schema::create('sent_receipts', function (Blueprint $table): void {
            $table->integer('payment_id');
        });
    }
};
