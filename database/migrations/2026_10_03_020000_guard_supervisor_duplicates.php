<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisors', function (Blueprint $t) {
            $t->string('unidentified_name', 150)->nullable()->storedAs('CASE WHEN identity_code IS NULL THEN LOWER(TRIM(name)) ELSE NULL END');
            $t->unique('unidentified_name');
        });
    }

    public function down(): void
    {
        Schema::table('supervisors', function (Blueprint $t) {
            $t->dropUnique(['unidentified_name']);
            $t->dropColumn('unidentified_name');
        });
    }
};
