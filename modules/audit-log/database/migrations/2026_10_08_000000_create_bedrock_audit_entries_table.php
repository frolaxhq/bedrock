<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bedrock_audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 128)->index();
            $table->timestamp('occurred_at')->index();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable()->index();
            $table->string('subject_type', 128);
            $table->string('subject_id')->nullable();
            $table->string('module_key')->nullable()->index();
            $table->json('metadata');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bedrock_audit_entries');
    }
};
