<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_id')->constrained()->cascadeOnDelete();
            // Identity of the batch sent by the runner. Retries reuse the same
            // batch_id + line_no so ingestion is idempotent.
            $table->char('batch_id', 36)->nullable();
            $table->unsignedSmallInteger('line_no')->default(0);
            $table->string('stream', 10);
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['build_id', 'batch_id', 'line_no'], 'build_logs_batch_unique');
            $table->index(['build_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_logs');
    }
};
