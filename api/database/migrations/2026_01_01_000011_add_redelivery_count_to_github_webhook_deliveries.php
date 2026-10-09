<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_webhook_deliveries', function (Blueprint $table) {
            // How many times GitHub re-sent this same delivery id.
            $table->unsignedInteger('redelivery_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('github_webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn('redelivery_count');
        });
    }
};
