<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PracticumPortal;
use App\Services\Roster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssessmentPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'portal_test') {
            throw new \RuntimeException('Preview M4 hanya portal_test terisolasi.');
        }
        $this->call(PreviewSeeder::class);
        if (DB::table('practicums')->where('code', 'DEMO-M4')->exists()) {
            throw new \RuntimeException('Preview M4 sudah ada.');
        }
        DB::transaction(function () {
            $p = DB::table('practicums')->insertGetId(['code' => 'DEMO-M4', 'name' => 'M4 — Data contoh']);
            app(PracticumPortal::class)->registerInitial($p, 'DEMO-M4');
            $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => DB::table('semesters')->where('code', 'DEMO')->value('id'), 'practicum_id' => $p]);
            $session = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A — Data contoh', 'capacity' => 50]);
            DB::table('practicum_sessions')->insert(['offering_id' => $o, 'label' => 'Sesi B — Data contoh', 'capacity' => 50]);
            $u = User::where('email', 'aslab@example.test')->firstOrFail();
            DB::table('staff_assignments')->insert(['user_id' => $u->id, 'offering_id' => $o, 'all_sessions' => true]);
            for ($n = 1; $n <= 5; $n++) {
                $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => $n, 'title' => 'Pertemuan '.$n.' — Data contoh']);
                if ($n === 2) {
                    DB::table('session_meetings')->insert(['offering_id' => $o, 'session_id' => $session, 'meeting_id' => $m, 'starts_at' => '2026-10-02 01:00:00', 'ends_at' => '2026-10-02 03:00:00', 'room' => 'Lab — Data contoh']);
                }
            }for ($i = 1; $i <= 3; $i++) {
                app(Roster::class)->add(['nbi' => '004000'.$i, 'name' => 'Praktikan M4 '.$i.' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $session, 'valid_from' => '2026-10-01'], $o);
            }
        });
    }
}
