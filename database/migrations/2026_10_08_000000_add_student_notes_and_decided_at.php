<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cek Status details: a note written for the student, separate from audit reasons and internal notes
 * (existing notes are never published), and the time a public delivery was reviewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['academic_requests', 'public_deliveries', 'submissions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->text('student_note')->nullable());
        }
        Schema::table('public_deliveries', fn (Blueprint $t) => $t->timestamp('decided_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('public_deliveries', fn (Blueprint $t) => $t->dropColumn('decided_at'));
        foreach (['academic_requests', 'public_deliveries', 'submissions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('student_note'));
        }
    }
};
