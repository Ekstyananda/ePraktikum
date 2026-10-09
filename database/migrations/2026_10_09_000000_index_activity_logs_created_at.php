<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The scheduler counts changes since the last backup every minute.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', fn (Blueprint $t) => $t->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('activity_logs', fn (Blueprint $t) => $t->dropIndex(['created_at']));
    }
};
