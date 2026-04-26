<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('position');
            $table->text('title');
            $table->text('title_uz')->nullable();
            $table->text('url')->unique();
            $table->longText('content')->nullable();
            $table->longText('summary_uz')->nullable();
            $table->date('digest_date')->useCurrent();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('digest_date', 'articles_digest_date_idx');
            $table->index('position', 'articles_position_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE INDEX articles_fts_idx ON articles
                USING gin(to_tsvector('simple', title || ' ' || COALESCE(content, '')))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
