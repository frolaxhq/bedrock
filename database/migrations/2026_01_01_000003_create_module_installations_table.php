<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 191)->index();
            // NULL for terminal operations; a database constraint guards concurrent requests.
            $table->string('active_key', 191)->nullable()->unique();
            $table->string('status')->default('queued')->index();
            $table->text('from')->nullable();
            $table->text('zip')->nullable();
            $table->boolean('offline')->default(false);
            $table->string('connection');
            $table->string('queue');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_installations');
    }
};
