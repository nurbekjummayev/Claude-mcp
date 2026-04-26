<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('prompt');
            $table->text('system_prompt')->nullable();
            $table->json('context')->nullable();
            $table->string('status', 20)->default('pending');
            $table->longText('response')->nullable();
            $table->text('error')->nullable();
            $table->string('model', 50)->default('claude-sonnet-4-5');
            $table->unsignedInteger('tokens_input')->default(0);
            $table->unsignedInteger('tokens_output')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->index('status', 'ai_tasks_status_idx');
            $table->index(['created_at'], 'ai_tasks_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tasks');
    }
};
