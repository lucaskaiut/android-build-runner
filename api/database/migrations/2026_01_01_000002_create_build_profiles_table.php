<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('build_script');
            $table->string('working_directory')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(3600);
            $table->json('artifact_patterns')->nullable();
            $table->json('env')->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
            $table->index(['project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_profiles');
    }
};
