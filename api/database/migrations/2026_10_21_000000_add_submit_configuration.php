<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_profiles', function (Blueprint $table) {
            $table->boolean('auto_submit')->default(false)->after('is_active');
            $table->json('submit_config')->nullable()->after('auto_submit');
        });

        Schema::table('builds', function (Blueprint $table) {
            $table->string('submit_status', 20)->nullable()->after('status');
            $table->text('submit_message')->nullable()->after('submit_status');
            $table->string('submit_version_code', 32)->nullable()->after('submit_message');
            $table->timestamp('submitted_at')->nullable()->after('submit_version_code');
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->dropColumn(['submit_status', 'submit_message', 'submit_version_code', 'submitted_at']);
        });

        Schema::table('build_profiles', function (Blueprint $table) {
            $table->dropColumn(['auto_submit', 'submit_config']);
        });
    }
};
