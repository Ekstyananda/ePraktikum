<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PracticumPortal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'portal_test') {
            throw new \RuntimeException('Preview hanya pada database pengujian terisolasi.');
        }
        $password = env('PORTAL_DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('Isi PORTAL_DEMO_PASSWORD dengan kata sandi pengujian acak.');
        }
        User::updateOrCreate(['email' => 'admin@example.test'], ['name' => 'Administrator — Data contoh', 'password' => $password, 'role' => 'admin', 'active' => true]);
        $u = User::updateOrCreate(['email' => 'aslab@example.test'], ['name' => 'Aslab — Data contoh', 'password' => $password, 'role' => 'aslab', 'active' => true]);
        DB::table('semesters')->updateOrInsert(['code' => 'DEMO'], ['label' => 'Data contoh — Semester uji', 'status' => 'draft']);
        DB::table('practicums')->updateOrInsert(['code' => 'DEMO-SBD'], ['name' => 'Sistem Basis Data — Data contoh']);
        $semester = DB::table('semesters')->where('code', 'DEMO')->value('id');
        $practicum = DB::table('practicums')->where('code', 'DEMO-SBD')->value('id');
        if (! DB::table('practicum_slugs')->where('practicum_id', $practicum)->exists()) {
            app(PracticumPortal::class)->registerInitial($practicum, 'DEMO-SBD');
        }
        DB::table('practicum_offerings')->updateOrInsert(['semester_id' => $semester, 'practicum_id' => $practicum], ['status' => 'draft']);
        $o = DB::table('practicum_offerings')->where('semester_id', $semester)->where('practicum_id', $practicum)->value('id');
        foreach (['Sesi 1 — Data contoh', 'Sesi 2 — Data contoh'] as $label) {
            DB::table('practicum_sessions')->updateOrInsert(['offering_id' => $o, 'label' => $label], ['room' => 'Lab — Data contoh', 'capacity' => 25]);
        }
        DB::table('staff_assignments')->updateOrInsert(['user_id' => $u->id, 'offering_id' => $o], ['all_sessions' => true]);
    }
}
