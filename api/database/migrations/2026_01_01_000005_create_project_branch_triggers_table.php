<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_branch_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('build_profile_id')->constrained()->cascadeOnDelete();
            $table->string('branch_pattern', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'branch_pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_branch_triggers');
    }
};
