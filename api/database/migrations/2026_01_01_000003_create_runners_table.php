<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('software_version', 64)->nullable();
            $table->unsignedInteger('protocol_version')->default(1);
            $table->string('status', 20)->default('offline');
            $table->unsignedBigInteger('current_build_id')->nullable()->index();
            $table->string('os_info', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('token_rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runners');
    }
};
