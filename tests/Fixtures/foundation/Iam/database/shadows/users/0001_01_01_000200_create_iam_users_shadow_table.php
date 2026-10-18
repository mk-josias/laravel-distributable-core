<?php

declare(strict_types=1);

use Foundation\Iam\Shadows\UserShadow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Microservices\Migrations\ShadowMigration;

return new class extends ShadowMigration
{
    protected function shadow(): string
    {
        return UserShadow::class;
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
