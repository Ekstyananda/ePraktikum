<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PracticumPortal;
use App\Services\Roster;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** M5 preview data for the isolated portal_test database only. Everything is labelled Data contoh. */
class PublicPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'portal_test') {
            throw new \RuntimeException('Preview M5 hanya pada portal_test terisolasi.');
        }
        $this->call(PreviewSeeder::class);
        if (DB::table('practicums')->where('code', 'DEMO-M5')->exists()) {
            throw new \RuntimeException('Preview M5 sudah ada.');
        }
        DB::transaction(function () {
            $admin = User::where('email', 'admin@example.test')->firstOrFail();
            $aslab = User::where('email', 'aslab@example.test')->firstOrFail();
            auth()->setUser($admin);
            $semester = DB::table('semesters')->where('code', 'DEMO')->value('id');
            DB::table('semesters')->where('id', $semester)->update(['status' => 'active']);
            $p = DB::table('practicums')->insertGetId(['code' => 'DEMO-M5', 'name' => 'M5 — Data contoh']);
            app(PracticumPortal::class)->registerInitial($p, 'DEMO-M5');
            $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $semester, 'practicum_id' => $p, 'status' => 'active']);
            $a = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A — Data contoh', 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Lab A — Data contoh', 'capacity' => 20, 'responsible_user_id' => $aslab->id]);
            $b = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi B — Data contoh', 'weekday' => 3, 'start_time' => '13:00', 'end_time' => '15:00', 'room' => 'Lab B — Data contoh', 'capacity' => 3]);
            DB::table('staff_assignments')->insert(['user_id' => $aslab->id, 'offering_id' => $o, 'all_sessions' => true]);
            foreach (['Andi Pratama', 'Bunga Lestari', 'Candra Wijaya', 'Dewi Anggraini', 'Eka Saputra'] as $i => $name) {
                app(Roster::class)->add(['nbi' => '0050000'.($i + 1), 'name' => $name.' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $i < 3 ? $a : $b, 'valid_from' => '2026-01-01'], $o);
            }
            $first = null;
            for ($n = 1; $n <= 5; $n++) {
                $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => $n, 'title' => 'Pertemuan '.$n.' — Data contoh']);
                $first ??= $m;
                // Sesi A meets on Monday and Sesi B on Wednesday, matching the weekly schedule above.
                $day = now('Asia/Jakarta')->next(CarbonInterface::MONDAY)->addWeeks($n - 1);
                DB::table('session_meetings')->insert([
                    ['offering_id' => $o, 'session_id' => $a, 'meeting_id' => $m, 'starts_at' => $day->copy()->addHours(8)->utc(), 'ends_at' => $day->copy()->addHours(10)->utc(), 'room' => 'Lab A — Data contoh'],
                    ['offering_id' => $o, 'session_id' => $b, 'meeting_id' => $m, 'starts_at' => $day->copy()->addDays(2)->addHours(13)->utc(), 'ends_at' => $day->copy()->addDays(2)->addHours(15)->utc(), 'room' => 'Lab B — Data contoh'],
                ]);
            }
            DB::table('request_windows')->insert(['offering_id' => $o, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(30), 'instructions' => 'Lampirkan bukti bila ada. Pengajuan diperiksa paling lambat 2 hari kerja — Data contoh.', 'created_at' => now(), 'updated_at' => now()]);
            $digital = DB::table('assignments')->insertGetId(['offering_id' => $o, 'origin_meeting_id' => $first, 'type' => 'custom', 'mode' => 'digital', 'title' => 'Query SQL digital — Data contoh', 'instructions' => 'Unggah file .sql — Data contoh', 'mandatory' => false]);
            foreach ([$a, $b] as $session) {
                DB::table('assignment_schedules')->insert(['offering_id' => $o, 'assignment_id' => $digital, 'session_id' => $session, 'opens_at' => now()->subDay(), 'due_at' => now()->addDays(5), 'closes_at' => now()->addDays(7), 'allow_late' => true]);
            }
            $lab = DB::table('assignments')->insertGetId(['offering_id' => $o, 'origin_meeting_id' => $first, 'type' => 'lab', 'mode' => 'direct', 'title' => 'Praktik Lab 1 — Data contoh']);
            $component = DB::table('grading_components')->insertGetId(['offering_id' => $o, 'assignment_id' => $lab, 'label' => 'Praktik Lab 1 — Data contoh', 'max_score' => 100]);
            $andi = DB::table('enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')->where('e.offering_id', $o)->where('s.nbi', '00500001')->value('e.id');
            DB::table('grades')->insert(['offering_id' => $o, 'component_id' => $component, 'enrollment_id' => $andi, 'score' => 45, 'status' => 'graded', 'evaluator_id' => $admin->id]);
            DB::table('remedial_programs')->insert(['offering_id' => $o, 'component_id' => $component, 'title' => 'Remidi Praktik Lab 1 — Data contoh', 'instructions' => 'Kerjakan ulang soal lab — Data contoh.', 'eligibility_rule' => 'Ditentukan pengelola sesuai ketentuan resmi — Data contoh.', 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(5), 'scheduled_at' => now()->addDays(6), 'room' => 'Lab A — Data contoh', 'requires_file' => false, 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
