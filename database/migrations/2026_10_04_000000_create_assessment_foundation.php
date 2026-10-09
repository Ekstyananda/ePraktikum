<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_meetings', fn (Blueprint $t) => $t->unique(['id', 'session_id', 'offering_id'], 'execution_session_offering_unique'));
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('practicum_offerings')->restrictOnDelete();
            $t->unsignedBigInteger('origin_meeting_id')->nullable();
            $t->foreign(['origin_meeting_id', 'offering_id'], 'assignment_origin_fk')->references(['id', 'offering_id'])->on('meetings')->restrictOnDelete();
            $t->enum('type', ['pendahuluan', 'aktivitas', 'lab', 'final', 'custom']);
            $t->enum('mode', ['print', 'digital', 'direct']);
            $t->string('title', 180);
            $t->text('instructions')->nullable();
            $t->boolean('mandatory')->default(true);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['id', 'offering_id']);
            $t->timestamps();
        });
        Schema::create('assignment_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('assignment_id');
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('collection_session_meeting_id')->nullable();
            $t->foreign(['collection_session_meeting_id', 'session_id', 'offering_id'], 'schedule_collection_fk')->references(['id', 'session_id', 'offering_id'])->on('session_meetings')->restrictOnDelete();
            $t->dateTime('opens_at');
            $t->dateTime('due_at');
            $t->dateTime('closes_at');
            $t->boolean('allow_late')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['assignment_id', 'session_id']);
            $t->unique(['id', 'assignment_id', 'offering_id']);
            $t->foreign(['assignment_id', 'offering_id'], 'schedule_assignment_fk')->references(['id', 'offering_id'])->on('assignments')->restrictOnDelete();
            $t->foreign(['session_id', 'offering_id'], 'schedule_session_fk')->references(['id', 'offering_id'])->on('practicum_sessions')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('assignment_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->unsignedBigInteger('schedule_id');
            $t->string('mode', 20);
            $t->string('status', 30);
            $t->dateTime('received_at')->nullable();
            $t->timestamp('recorded_at');
            $t->foreignId('receiver_id')->constrained('users')->restrictOnDelete();
            $t->boolean('is_late')->default(false);
            $t->string('late_decision', 30)->default('pending');
            $t->text('note')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unique(['assignment_id', 'enrollment_id']);
            $t->unique(['id', 'assignment_id']);
            $t->foreign(['assignment_id', 'offering_id'], 'submission_assignment_fk')->references(['id', 'offering_id'])->on('assignments')->restrictOnDelete();
            $t->foreign(['enrollment_id', 'offering_id'], 'submission_enrollment_fk')->references(['id', 'offering_id'])->on('enrollments')->restrictOnDelete();
            $t->foreign(['schedule_id', 'assignment_id', 'offering_id'], 'submission_schedule_fk')->references(['id', 'assignment_id', 'offering_id'])->on('assignment_schedules')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('submission_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version_number');
            $t->uuid('file_id');
            $t->foreign('file_id')->references('id')->on('files')->restrictOnDelete();
            $t->dateTime('submitted_at');
            $t->timestamp('recorded_at');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->text('revision_note')->nullable();
            $t->unique(['submission_id', 'version_number']);
            $t->timestamps();
        });
        Schema::create('report_checklist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $t->string('key', 60);
            $t->string('label', 180);
            $t->unsignedInteger('sort_order');
            $t->unique(['assignment_id', 'key']);
            $t->unique(['id', 'assignment_id']);
        });
        Schema::create('report_checklist_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('assignment_id');
            $t->unsignedBigInteger('submission_id');
            $t->unsignedBigInteger('item_id');
            $t->boolean('completed')->default(false);
            $t->foreignId('checked_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['submission_id', 'item_id']);
            $t->foreign(['submission_id', 'assignment_id'], 'checklist_submission_fk')->references(['id', 'assignment_id'])->on('submissions')->restrictOnDelete();
            $t->foreign(['item_id', 'assignment_id'], 'checklist_item_fk')->references(['id', 'assignment_id'])->on('report_checklist_items')->restrictOnDelete();
        });
        Schema::create('grading_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('assignment_id');
            $t->string('label', 180);
            $t->decimal('weight', 7, 4)->nullable();
            $t->decimal('max_score', 10, 4);
            $t->boolean('mandatory')->default(true);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unique('assignment_id');
            $t->unique(['id', 'offering_id']);
            $t->foreign(['assignment_id', 'offering_id'], 'component_assignment_fk')->references(['id', 'offering_id'])->on('assignments')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('component_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->decimal('score', 10, 4)->nullable();
            $t->enum('status', ['ungraded', 'graded', 'missing_zero'])->default('ungraded');
            $t->text('note')->nullable();
            $t->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->unique(['component_id', 'enrollment_id']);
            $t->foreign(['component_id', 'offering_id'], 'grade_component_fk')->references(['id', 'offering_id'])->on('grading_components')->restrictOnDelete();
            $t->foreign(['enrollment_id', 'offering_id'], 'grade_enrollment_fk')->references(['id', 'offering_id'])->on('enrollments')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('grading_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('practicum_offerings')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('config_json');
            $t->string('status', 20)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['offering_id', 'version']);
            $t->unique(['id', 'offering_id']);
            $t->timestamps();
        });
        Schema::create('final_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->unsignedBigInteger('rules_id');
            $t->decimal('score', 10, 4);
            $t->string('letter', 20);
            $t->string('decision', 20);
            $t->json('snapshot_json');
            $t->foreignId('finalized_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('finalized_at');
            $t->unique('enrollment_id');
            $t->foreign(['enrollment_id', 'offering_id'], 'final_enrollment_fk')->references(['id', 'offering_id'])->on('enrollments')->restrictOnDelete();
            $t->foreign(['rules_id', 'offering_id'], 'final_rules_fk')->references(['id', 'offering_id'])->on('grading_rules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['final_results', 'grading_rules', 'grades', 'grading_components', 'report_checklist_results', 'report_checklist_items', 'submission_versions', 'submissions', 'assignment_schedules', 'assignments'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('session_meetings', fn (Blueprint $t) => $t->dropUnique('execution_session_offering_unique'));
    }
};
