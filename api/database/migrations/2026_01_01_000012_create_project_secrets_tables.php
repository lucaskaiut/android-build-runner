<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->text('value');
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('project_secret_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->longText('content');
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->unique(['project_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_secret_files');
        Schema::dropIfExists('project_secrets');
    }
};
