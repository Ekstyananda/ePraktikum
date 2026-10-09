<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PracticumPortal;
use App\Services\Roster;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Multi-practicum preview: M5 data plus a second practicum (PCD) with its own identity. portal_test only. */
class MultiPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDatabaseName() !== 'portal_test') {
            throw new \RuntimeException('Preview multi-praktikum hanya pada portal_test terisolasi.');
        }
        $this->call(PublicPreviewSeeder::class);
        DB::transaction(function () {
            $admin = User::where('email', 'admin@example.test')->firstOrFail();
            User::updateOrCreate(['email' => 'admin2@example.test'], ['name' => 'Administrator Kedua — Data contoh', 'password' => env('PORTAL_DEMO_PASSWORD'), 'role' => 'admin', 'active' => true]);
            auth()->setUser($admin);
            $semester = DB::table('semesters')->where('code', 'DEMO')->value('id');
            DB::table('practicums')->where('code', 'DEMO-M5')->update(['display_name' => 'Sistem Basis Data — Data contoh', 'short_name' => 'SBD', 'tagline' => 'Modul SQL, jadwal sesi, pengumpulan query dan pengajuan — Data contoh', 'accent' => 'blue', 'hero_preset' => 'database', 'contact' => 'Koordinator aslab SBD — Data contoh']);
            // The older demo practicum stays out of the hub so the preview shows exactly two portals.
            DB::table('practicum_offerings')->whereIn('practicum_id', DB::table('practicums')->where('code', 'DEMO-SBD')->pluck('id'))->update(['status' => 'draft']);

            $p = DB::table('practicums')->insertGetId(['code' => 'DEMO-PCD', 'name' => 'Pengolahan Citra Digital — Data contoh']);
            app(PracticumPortal::class)->registerInitial($p, 'DEMO-PCD');
            DB::table('practicums')->where('id', $p)->update(['short_name' => 'PCD', 'tagline' => 'Praktikum OpenCV: filter, segmentasi dan deteksi tepi — Data contoh', 'accent' => 'teal', 'hero_preset' => 'image', 'contact' => 'Koordinator aslab PCD — Data contoh',
                'services' => json_encode(['jadwal' => true, 'modul' => true, 'pengumpulan' => true, 'pengajuan' => true, 'remidi' => false])]);
            $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $semester, 'practicum_id' => $p, 'status' => 'active']);
            $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi C — Data contoh', 'weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00', 'room' => 'Lab Citra — Data contoh', 'capacity' => 20]);
            foreach (['Fajar Nugroho', 'Gita Permata'] as $i => $name) {
                app(Roster::class)->add(['nbi' => '0060000'.($i + 1), 'name' => $name.' — Data contoh', 'sim_class' => 'B', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-01-01'], $o);
            }
            for ($n = 1; $n <= 3; $n++) {
                $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => $n, 'title' => 'Citra '.$n.' — Data contoh']);
                $day = now('Asia/Jakarta')->next(CarbonInterface::TUESDAY)->addWeeks($n - 1);
                DB::table('session_meetings')->insert(['offering_id' => $o, 'session_id' => $s, 'meeting_id' => $m, 'starts_at' => $day->copy()->addHours(10)->utc(), 'ends_at' => $day->copy()->addHours(12)->utc(), 'room' => 'Lab Citra — Data contoh']);
                $file = (string) Str::uuid();
                $bytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
                Storage::disk('local')->put('materials/'.$file.'.pdf', $bytes);
                DB::table('files')->insert(['id' => $file, 'storage_path' => 'materials/'.$file.'.pdf', 'original_name' => 'modul-citra-'.$n.'.pdf', 'mime' => 'application/pdf', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'visibility' => 'private', 'uploaded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('materials')->insert(['meeting_id' => $m, 'title' => 'Modul Citra '.$n.' — Data contoh', 'file_id' => $file, 'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('request_windows')->insert(['offering_id' => $o, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(30), 'instructions' => 'Pengajuan PCD — Data contoh.', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('announcements')->insert([
                ['offering_id' => $o, 'title' => 'Bawa laptop dengan OpenCV — Data contoh', 'body' => 'Pasang **OpenCV 4** sebelum pertemuan 1.', 'audience' => 'public', 'status' => 'published', 'published_at' => now()->subHour(), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
                ['offering_id' => null, 'title' => 'Lab tutup saat libur nasional — Data contoh', 'body' => 'Berlaku untuk semua praktikum.', 'audience' => 'public', 'status' => 'published', 'published_at' => now()->subHours(2), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
            ]);
        });
    }
}
