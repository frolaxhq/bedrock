<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('version');
            $table->string('provider');
            $table->string('path');
            $table->string('status')->default('discovered');
            $table->string('health')->default('healthy');
            $table->string('failure_code')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('manifest')->nullable();
            $table->json('dependencies')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('bedrock.modules.table', 'modules');

        return is_string($table) && $table !== '' ? $table : 'modules';
    }
};
