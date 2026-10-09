<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('build_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->string('branch')->nullable();
            $table->char('commit_sha', 40)->nullable();
            $table->string('status', 20)->default('queued');
            $table->foreignId('runner_id')->nullable()->constrained('runners')->nullOnDelete();
            $table->json('parameters')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->foreignId('webhook_delivery_id')->nullable()->constrained('github_webhook_deliveries')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'queued_at']);
            $table->index(['status', 'lease_expires_at']);
            $table->index(['project_id', 'created_at']);
            $table->index(['build_profile_id', 'created_at']);
            $table->index(['runner_id', 'status']);
            $table->index('commit_sha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builds');
    }
};
