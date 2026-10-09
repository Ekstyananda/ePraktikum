<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('practicum_offerings')->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('title', 180);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['offering_id', 'number']);
            $t->unique(['id', 'offering_id']);
            $t->timestamps();
        });
        Schema::create('session_meetings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('meeting_id');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('room', 150);
            $t->string('status', 30)->default('scheduled');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('snapshot_created_at')->nullable();
            $t->json('snapshot_meta')->nullable();
            $t->unique(['session_id', 'meeting_id']);
            $t->unique(['id', 'meeting_id', 'offering_id']);
            $t->foreign(['session_id', 'offering_id'])->references(['id', 'offering_id'])->on('practicum_sessions')->restrictOnDelete();
            $t->foreign(['meeting_id', 'offering_id'])->references(['id', 'offering_id'])->on('meetings')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('meeting_participants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('session_meeting_id');
            $t->unsignedBigInteger('meeting_id');
            $t->unsignedBigInteger('offering_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->string('nbi', 40);
            $t->string('name', 150);
            $t->string('sim_class', 80);
            $t->string('class_category', 80);
            $t->string('session_label');
            $t->string('source', 30)->default('membership');
            $t->unsignedInteger('print_order');
            $t->unique(['session_meeting_id', 'enrollment_id']);
            $t->unique(['meeting_id', 'enrollment_id']);
            $t->unique(['session_meeting_id', 'print_order']);
            $t->foreign(['session_meeting_id', 'meeting_id', 'offering_id'], 'participants_execution_offering_fk')->references(['id', 'meeting_id', 'offering_id'])->on('session_meetings')->restrictOnDelete();
            $t->foreign(['enrollment_id', 'offering_id'])->references(['id', 'offering_id'])->on('enrollments')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('participant_id')->unique()->constrained('meeting_participants')->restrictOnDelete();
            $t->enum('status', ['unrecorded', 'present', 'excused', 'sick', 'absent'])->default('unrecorded');
            $t->text('note')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('storage_path')->unique();
            $t->string('original_name', 180);
            $t->string('mime', 120);
            $t->unsignedBigInteger('size');
            $t->string('visibility', 20)->default('private');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('checksum', 64);
            $t->timestamps();
        });
        Schema::create('materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $t->string('title', 180);
            $t->uuid('file_id');
            $t->foreign('file_id')->references('id')->on('files')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('attendance_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_meeting_id')->constrained()->restrictOnDelete();
            $t->uuid('file_id')->unique();
            $t->foreign('file_id')->references('id')->on('files')->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['attendance_documents', 'materials', 'files', 'attendances', 'meeting_participants', 'session_meetings', 'meetings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
