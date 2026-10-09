<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['semesters', 'practicums', 'practicum_offerings', 'practicum_sessions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        }
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->string('nbi', 40)->unique();
            $t->string('name', 150);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('supervisors', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('identity_code', 80)->nullable()->unique();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('name');
        });
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('practicum_offerings')->restrictOnDelete();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->string('sim_class', 80);
            $t->string('class_category', 80);
            $t->foreignId('supervisor_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['offering_id', 'student_id']);
            $t->unique(['id', 'offering_id']);
            $t->timestamps();
        });
        Schema::create('session_memberships', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('enrollment_id');
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('offering_id');
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->unsignedBigInteger('open_enrollment_id')->nullable()->storedAs('CASE WHEN valid_until IS NULL THEN enrollment_id ELSE NULL END');
            $t->unique('open_enrollment_id');
            $t->foreign(['enrollment_id', 'offering_id'])->references(['id', 'offering_id'])->on('enrollments')->restrictOnDelete();
            $t->foreign(['session_id', 'offering_id'])->references(['id', 'offering_id'])->on('practicum_sessions')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('import_previews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('offering_id')->constrained('practicum_offerings')->restrictOnDelete();
            $t->longText('payload');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('expires_at');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        foreach (['import_previews', 'session_memberships', 'enrollments', 'supervisors', 'students'] as $table) {
            Schema::dropIfExists($table);
        }foreach (['semesters', 'practicums', 'practicum_offerings', 'practicum_sessions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('version'));
        }
    }
};
