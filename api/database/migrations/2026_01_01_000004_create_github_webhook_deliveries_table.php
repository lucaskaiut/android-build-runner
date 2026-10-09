<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('delivery_id', 100)->nullable()->unique();
            $table->string('event', 64)->nullable();
            $table->string('ref', 255)->nullable();
            $table->char('sha', 40)->nullable();
            $table->string('status', 20);
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('build_id')->nullable()->index();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
    }
};
