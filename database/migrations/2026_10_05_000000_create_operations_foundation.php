<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M6: announcements, settings, backup history and versioned final results (admin reopen keeps history). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            // Null offering means a general announcement managed by admin.
            $t->foreignId('offering_id')->nullable()->constrained('practicum_offerings')->restrictOnDelete();
            $t->string('title', 180);
            $t->text('body');
            $t->string('audience', 20)->default('public');
            $t->string('status', 20)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'audience', 'published_at']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->json('value_json');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('backup_runs', function (Blueprint $t) {
            $t->id();
            $t->string('status', 20)->default('queued');
            $t->string('trigger', 20);
            $t->foreignId('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('queued_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->string('artifact_ref', 200)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->char('checksum', 64)->nullable();
            $t->json('manifest_json')->nullable();
            $t->text('error_summary')->nullable();
            $t->timestamp('pruned_at')->nullable();
            $t->index(['status', 'finished_at']);
        });
        Schema::table('final_results', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1)->after('enrollment_id');
            $t->timestamp('superseded_at')->nullable();
            $t->foreignId('superseded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('supersede_reason')->nullable();
        });
        // Exactly one active final per enrollment; superseded versions stay as history.
        DB::statement('ALTER TABLE final_results ADD active_enrollment_id BIGINT UNSIGNED AS (IF(superseded_at IS NULL, enrollment_id, NULL)) STORED');
        Schema::table('final_results', function (Blueprint $t) {
            $t->index('enrollment_id', 'final_results_enrollment_index');
            $t->dropUnique(['enrollment_id']);
            $t->unique('active_enrollment_id', 'final_results_one_active');
            $t->unique(['enrollment_id', 'version'], 'final_results_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('final_results', function (Blueprint $t) {
            $t->dropUnique('final_results_one_active');
            $t->dropUnique('final_results_version_unique');
            $t->unique('enrollment_id');
            $t->dropIndex('final_results_enrollment_index');
            $t->dropColumn('active_enrollment_id');
            $t->dropConstrainedForeignId('superseded_by');
            $t->dropColumn(['version', 'superseded_at', 'supersede_reason']);
        });
        foreach (['backup_runs', 'settings', 'announcements'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
