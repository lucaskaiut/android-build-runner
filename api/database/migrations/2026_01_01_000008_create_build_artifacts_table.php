<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('type', 10)->default('other');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->unique(['build_id', 'sha256', 'size_bytes'], 'build_artifacts_dedup');
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_artifacts');
    }
};
