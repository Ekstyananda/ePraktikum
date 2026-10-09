<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-practicum public portal: identity per practicum and one slug registry
 * (current slug + old aliases) so uniqueness holds across both.
 */
return new class extends Migration
{
    /** Kept in sync with App\Services\PracticumPortal::RESERVED; copied so the migration never changes behaviour later. */
    private const RESERVED = ['login', 'logout', 'dashboard', 'praktikum', 'pengaturan', 'cek-status', 'pengumuman', 'modul', 'jadwal', 'pengajuan', 'remidi', 'pengumpulan', 'up', 'health', 'vendor', 'assets', 'storage', 'sampul', 'favicon-ico', 'robots-txt', 'api', 'admin'];

    public function up(): void
    {
        Schema::table('practicums', function (Blueprint $t) {
            $t->string('slug', 60)->nullable()->after('code');
            $t->string('display_name', 150)->nullable();
            $t->string('short_name', 20)->nullable();
            $t->string('tagline', 255)->nullable();
            $t->string('accent', 20)->default('blue');
            $t->string('hero_preset', 20)->default('database');
            $t->uuid('hero_file_id')->nullable();
            $t->foreign('hero_file_id')->references('id')->on('files')->restrictOnDelete();
            $t->string('contact', 500)->nullable();
            $t->string('contact_url', 255)->nullable();
            $t->json('services')->nullable();
            $t->unsignedInteger('portal_version')->default(1);
        });
        Schema::create('practicum_slugs', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 60)->unique();
            $t->foreignId('practicum_id')->constrained()->restrictOnDelete();
            $t->boolean('is_current')->default(false);
            $t->timestamps();
        });
        // One current slug per practicum.
        DB::statement('ALTER TABLE practicum_slugs ADD current_practicum_id BIGINT UNSIGNED AS (IF(is_current, practicum_id, NULL)) STORED');
        Schema::table('practicum_slugs', fn (Blueprint $t) => $t->unique('current_practicum_id', 'practicum_slugs_one_current'));

        // Backfill: slug from code; empty, reserved or colliding values fall back to praktikum-<id> and a numeric suffix.
        $taken = [];
        foreach (DB::table('practicums')->orderBy('id')->get(['id', 'code', 'name']) as $p) {
            $base = Str::slug((string) $p->code);
            if ($base === '' || in_array($base, self::RESERVED, true) || strlen($base) > 50) {
                $base = 'praktikum-'.$p->id;
            }
            $slug = $base;
            for ($n = 2; isset($taken[$slug]); $n++) {
                $slug = $base.'-'.$n;
            }
            $taken[$slug] = true;
            DB::table('practicums')->where('id', $p->id)->update(['slug' => $slug, 'short_name' => Str::upper(Str::limit((string) $p->code, 20, ''))]);
            DB::table('practicum_slugs')->insert(['slug' => $slug, 'practicum_id' => $p->id, 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('practicums', fn (Blueprint $t) => $t->unique('slug'));
    }

    public function down(): void
    {
        Schema::dropIfExists('practicum_slugs');
        Schema::table('practicums', function (Blueprint $t) {
            $t->dropUnique(['slug']);
            $t->dropConstrainedForeignId('hero_file_id');
            $t->dropColumn(['slug', 'display_name', 'short_name', 'tagline', 'accent', 'hero_preset', 'contact', 'contact_url', 'services', 'portal_version']);
        });
    }
};
