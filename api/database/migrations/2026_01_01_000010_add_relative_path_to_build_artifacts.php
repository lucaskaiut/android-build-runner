<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('build_artifacts', 'relative_path')) {
            Schema::table('build_artifacts', function (Blueprint $table) {
                // Path of the file relative to the profile working directory,
                // e.g. "android/app/build/outputs/apk/release/app-release.apk".
                $table->string('relative_path', 500)->nullable()->after('original_name');
            });
        }

        $indexes = $this->indexNames();

        // MySQL refuses to drop the unique index while it is the only index
        // backing the build_id foreign key, so give the FK its own index.
        if (! $indexes->contains('build_artifacts_build_id_index')) {
            Schema::table('build_artifacts', function (Blueprint $table) {
                $table->index('build_id', 'build_artifacts_build_id_index');
            });
        }

        if ($indexes->contains('build_artifacts_dedup')) {
            Schema::table('build_artifacts', function (Blueprint $table) {
                $table->dropUnique('build_artifacts_dedup');
                $table->unique(
                    ['build_id', 'sha256', 'size_bytes', 'relative_path'],
                    'build_artifacts_dedup',
                );
            });
        } else {
            Schema::table('build_artifacts', function (Blueprint $table) {
                $table->unique(
                    ['build_id', 'sha256', 'size_bytes', 'relative_path'],
                    'build_artifacts_dedup',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('build_artifacts', function (Blueprint $table) {
            $table->dropUnique('build_artifacts_dedup');
            $table->unique(['build_id', 'sha256', 'size_bytes'], 'build_artifacts_dedup');
        });

        if (Schema::hasColumn('build_artifacts', 'relative_path')) {
            Schema::table('build_artifacts', function (Blueprint $table) {
                $table->dropColumn('relative_path');
            });
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function indexNames(): \Illuminate\Support\Collection
    {
        return collect(DB::select('SHOW INDEX FROM build_artifacts'))->pluck('Key_name');
    }
};
