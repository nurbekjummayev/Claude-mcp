<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('user_id', 50);
            $table->string('role', 20);
            $table->text('message');
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'conversations_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
