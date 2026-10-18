<?php

declare(strict_types=1);

use Foundation\Iam\Shadows\UserShadow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new UserShadow)->getTable(), function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new UserShadow)->getTable());
    }
};
