<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('repo_url', 2048);
            $table->string('default_branch')->default('main');
            $table->string('working_directory')->default('.');
            $table->json('allowed_branches')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('webhook_enabled')->default(false);
            $table->text('webhook_secret')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
