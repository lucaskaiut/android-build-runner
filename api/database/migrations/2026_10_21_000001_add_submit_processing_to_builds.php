<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->json('submit_config')->nullable()->after('submitted_at');
            $table->timestamp('submit_started_at')->nullable()->after('submit_config');
            $table->timestamp('submit_lease_expires_at')->nullable()->after('submit_started_at');
            $table->unsignedInteger('submit_attempts')->default(0)->after('submit_lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->dropColumn([
                'submit_config',
                'submit_started_at',
                'submit_lease_expires_at',
                'submit_attempts',
            ]);
        });
    }
};
