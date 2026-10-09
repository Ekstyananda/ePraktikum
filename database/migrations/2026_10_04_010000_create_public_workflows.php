<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', fn (Blueprint $t) => $t->unsignedBigInteger('uploaded_by')->nullable()->change());
        Schema::table('submissions', fn (Blueprint $t) => $t->unsignedBigInteger('receiver_id')->nullable()->change());
        Schema::table('submission_versions', fn (Blueprint $t) => $t->unsignedBigInteger('recorded_by')->nullable()->change());
        Schema::create('public_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->unsignedBigInteger('assignment_id');
            $t->unsignedBigInteger('schedule_id');
            $t->foreign(['enrollment_id', 'offering_id'], 'delivery_enrollment_fk')->references(['id', 'offering_id'])->on('enrollments')->restrictOnDelete();
            $t->foreign(['assignment_id', 'offering_id'], 'delivery_assignment_fk')->references(['id', 'offering_id'])->on('assignments')->restrictOnDelete();
            $t->foreign(['schedule_id', 'assignment_id', 'offering_id'], 'delivery_schedule_fk')->references(['id', 'assignment_id', 'offering_id'])->on('assignment_schedules')->restrictOnDelete();
            $t->foreignId('submission_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('file_id');
            $t->foreign('file_id')->references('id')->on('files')->restrictOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->string('status', 30)->default('pending');
            $t->dateTime('received_at');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('request_windows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->unique()->constrained('practicum_offerings')->restrictOnDelete();
            $t->dateTime('opens_at');
            $t->dateTime('closes_at');
            $t->text('instructions');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('remedial_programs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('component_id');
            $t->foreign(['component_id', 'offering_id'], 'program_component_fk')->references(['id', 'offering_id'])->on('grading_components')->restrictOnDelete();
            $t->string('title', 180);
            $t->text('instructions');
            $t->text('eligibility_rule');
            $t->dateTime('opens_at');
            $t->dateTime('closes_at');
            $t->dateTime('scheduled_at');
            $t->string('room', 150);
            $t->boolean('requires_file')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['id', 'offering_id']);
            $t->timestamps();
        });
        Schema::create('academic_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->string('type', 30);
            $t->string('status', 30)->default('pending');
            $t->unsignedBigInteger('source_session_id');
            $t->unsignedBigInteger('target_session_id')->nullable();
            $t->unsignedBigInteger('source_execution_id')->nullable();
            $t->unsignedBigInteger('target_execution_id')->nullable();
            $t->unsignedBigInteger('program_id')->nullable();
            $t->foreign(['enrollment_id', 'offering_id'], 'request_enrollment_fk')->references(['id', 'offering_id'])->on('enrollments')->restrictOnDelete();
            foreach (['source', 'target'] as $side) {
                $t->foreign([$side.'_session_id', 'offering_id'], 'request_'.$side.'_session_fk')->references(['id', 'offering_id'])->on('practicum_sessions')->restrictOnDelete();
                $t->foreign([$side.'_execution_id', $side.'_session_id', 'offering_id'], 'request_'.$side.'_execution_fk')->references(['id', 'session_id', 'offering_id'])->on('session_meetings')->restrictOnDelete();
            }
            $t->foreign(['program_id', 'offering_id'], 'request_program_fk')->references(['id', 'offering_id'])->on('remedial_programs')->restrictOnDelete();
            $t->date('effective_date')->nullable();
            $t->text('reason');
            $t->uuid('evidence_file_id')->nullable();
            $t->foreign('evidence_file_id')->references('id')->on('files')->restrictOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->foreignId('decision_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('decision_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->dateTime('scheduled_at')->nullable();
            $t->string('room', 150)->nullable();
            $t->json('original_grade')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['offering_id', 'status', 'type']);
        });
        Schema::create('request_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->constrained('academic_requests')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->dateTime('performed_at');
            $t->string('attendance', 30)->nullable();
            $t->decimal('score', 10, 4)->nullable();
            $t->text('note');
            $t->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['request_id', 'version']);
        });
        Schema::table('meeting_participants', fn (Blueprint $t) => $t->foreignId('approved_request_id')->nullable()->constrained('academic_requests')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('meeting_participants', fn (Blueprint $t) => $t->dropConstrainedForeignId('approved_request_id'));
        foreach (['request_results', 'academic_requests', 'remedial_programs', 'request_windows', 'public_deliveries'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
