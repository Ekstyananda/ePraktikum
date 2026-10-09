<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PracticumPortal;
use App\Services\Roster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MeetingPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'portal_test') {
            throw new \RuntimeException('Preview M3 hanya pada portal_test terisolasi.');
        }
        $this->call(PreviewSeeder::class);
        if (DB::table('practicums')->where('code', 'DEMO-M3')->exists()) {
            throw new \RuntimeException('Preview M3 sudah ada; jangan mengubah snapshot contoh yang sudah dibuat.');
        }
        DB::transaction(function () {
            $p = DB::table('practicums')->insertGetId(['code' => 'DEMO-M3', 'name' => 'M3 — Data contoh']);
            app(PracticumPortal::class)->registerInitial($p, 'DEMO-M3');
            $semester = DB::table('semesters')->where('code', 'DEMO')->value('id');
            $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $semester, 'practicum_id' => $p, 'status' => 'active']);
            $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A — Data contoh', 'room' => 'Lab — Data contoh', 'capacity' => 100]);
            DB::table('practicum_sessions')->insert(['offering_id' => $o, 'label' => 'Sesi B — Data contoh', 'room' => 'Lab B — Data contoh', 'capacity' => 100]);
            $u = User::where('email', 'aslab@example.test')->firstOrFail();
            DB::table('staff_assignments')->insert(['user_id' => $u->id, 'offering_id' => $o, 'all_sessions' => true]);
            for ($i = 1; $i <= 67; $i++) {
                app(Roster::class)->add(['nbi' => '009900'.str_pad($i, 4, '0', STR_PAD_LEFT), 'name' => 'Praktikan '.str_pad($i, 2, '0', STR_PAD_LEFT).' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-10-01'], $o);
            }
        });
    }
}
