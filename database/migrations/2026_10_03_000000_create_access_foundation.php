<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->enum('role', ['admin', 'aslab'])->default('aslab');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('semesters', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('label');
            $t->date('starts_at')->nullable();
            $t->date('ends_at')->nullable();
            $t->enum('status', ['draft', 'active', 'locked'])->default('draft');
            $t->timestamps();
        });
        Schema::create('practicums', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('practicum_offerings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('semester_id')->constrained()->restrictOnDelete();
            $t->foreignId('practicum_id')->constrained()->restrictOnDelete();
            $t->enum('status', ['draft', 'active', 'locked'])->default('draft');
            $t->unique(['semester_id', 'practicum_id']);
            $t->timestamps();
        });
        Schema::create('practicum_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('practicum_offerings')->restrictOnDelete();
            $t->string('label');
            $t->unsignedTinyInteger('weekday')->nullable();
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->string('room')->nullable();
            $t->unsignedInteger('capacity')->nullable();
            $t->foreignId('responsible_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['offering_id', 'label']);
            $t->unique(['id', 'offering_id']);
            $t->timestamps();
        });
        Schema::create('staff_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('offering_id')->constrained('practicum_offerings')->restrictOnDelete();
            $t->boolean('all_sessions')->default(true);
            $t->unique(['user_id', 'offering_id']);
            $t->unique(['id', 'offering_id']);
            $t->timestamps();
        });
        Schema::create('staff_session_scopes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('assignment_id');
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('offering_id');
            $t->unique(['assignment_id', 'session_id']);
            $t->foreign(['assignment_id', 'offering_id'])->references(['id', 'offering_id'])->on('staff_assignments')->cascadeOnDelete();
            $t->foreign(['session_id', 'offering_id'])->references(['id', 'offering_id'])->on('practicum_sessions')->restrictOnDelete();
        });
        Schema::create('user_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('staff_assignments')->cascadeOnDelete();
            $t->string('permission_key', 80);
            $t->boolean('allowed');
            $t->unique(['assignment_id', 'permission_key']);
        });
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('offering_id')->nullable()->constrained('practicum_offerings')->restrictOnDelete();
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id');
            $t->string('action');
            $t->json('before_json')->nullable();
            $t->json('after_json')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('request_id');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'user_permissions', 'staff_session_scopes', 'staff_assignments', 'practicum_sessions', 'practicum_offerings', 'practicums', 'semesters'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active', 'version']));
    }
};
