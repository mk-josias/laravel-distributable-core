<?php

declare(strict_types=1);

use Distributable\Tests\Fixtures\Payments\Models\ContactShadow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new ContactShadow)->getTable(), function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->softDeletes();
        });
    }
};
