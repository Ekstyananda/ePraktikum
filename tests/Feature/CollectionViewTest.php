<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CollectionViewTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $staff = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $restricted = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'CV', 'label' => 'Semester — Data contoh', 'status' => 'active']);
        $p = DB::table('practicums')->insertGetId(['code' => 'CV', 'name' => 'SBD — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p, 'status' => 'active']);
        $a = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A', 'capacity' => 50]);
        $b = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi B', 'capacity' => 50]);
        DB::table('staff_assignments')->insert(['user_id' => $staff->id, 'offering_id' => $o, 'all_sessions' => true]);
        $grant = DB::table('staff_assignments')->insertGetId(['user_id' => $restricted->id, 'offering_id' => $o, 'all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $grant, 'offering_id' => $o, 'session_id' => $b]);
        $this->actingAs($admin);
        $e = [];
        foreach ([[$a, 'Andi Pratama'], [$b, 'Bunga Lestari']] as $i => [$s, $name]) {
            $e[] = DB::transaction(fn () => app(Roster::class)->add(['nbi' => '00700'.$i, 'name' => $name, 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-01-01'], $o));
        }
        auth()->logout();
        $m1 = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Satu']);
        $m2 = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 2, 'title' => 'Dua']);
        $sm = [];
        foreach ([$a, $b] as $s) {
            foreach ([$m1, $m2] as $k => $m) {
                $sm[$s][$m] = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $s, 'meeting_id' => $m, 'starts_at' => now()->subDays(10 - $k * 7), 'ends_at' => now()->subDays(10 - $k * 7)->addHours(2), 'room' => 'Lab']);
            }
        }
        $task = function (string $type, string $mode, string $title, int $origin, int $collectAt) use ($o, $a, $b, $sm) {
            $id = DB::table('assignments')->insertGetId(['offering_id' => $o, 'origin_meeting_id' => $origin, 'type' => $type, 'mode' => $mode, 'title' => $title]);
            foreach ([$a, $b] as $s) {
                DB::table('assignment_schedules')->insert(['offering_id' => $o, 'assignment_id' => $id, 'session_id' => $s, 'collection_session_meeting_id' => $sm[$s][$collectAt], 'opens_at' => now()->subDays(20), 'due_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
            }

            return $id;
        };
        $pend1 = $task('pendahuluan', 'print', 'Pendahuluan 1 — Data contoh', $m1, $m1);
        $pend2 = $task('pendahuluan', 'print', 'Pendahuluan 2 — Data contoh', $m2, $m2);
        $akt1 = $task('aktivitas', 'print', 'Aktivitas 1 — Data contoh', $m1, $m2);
        $digital = $task('custom', 'digital', 'Query digital — Data contoh', $m2, $m2);
        $schedule = DB::table('assignment_schedules')->where('assignment_id', $akt1)->where('session_id', $a)->value('id');
        DB::table('submissions')->insert(['offering_id' => $o, 'assignment_id' => $akt1, 'enrollment_id' => $e[0], 'schedule_id' => $schedule, 'mode' => 'print', 'status' => 'received', 'received_at' => now()->subDays(3), 'recorded_at' => now(), 'receiver_id' => $admin->id]);
        $component = DB::table('grading_components')->insertGetId(['offering_id' => $o, 'assignment_id' => $akt1, 'label' => 'Aktivitas 1', 'max_score' => 100]);
        DB::table('grades')->insert(['offering_id' => $o, 'component_id' => $component, 'enrollment_id' => $e[0], 'score' => 88, 'status' => 'graded', 'evaluator_id' => $admin->id]);

        return compact('admin', 'staff', 'restricted', 'o', 'a', 'b', 'e', 'm1', 'm2', 'pend1', 'pend2', 'akt1', 'digital');
    }

    public function test_meeting_view_follows_collection_schedule_and_mode_tabs(): void
    {
        $f = $this->fixtures();
        $url = "/praktikum/{$f['o']}/pengumpulan";
        $this->actingAs($f['staff']);
        $m2 = $this->get("$url?mode=print&meeting_id={$f['m2']}")->assertOk();
        $m2->assertSee('Pendahuluan 2 — Data contoh')->assertSee('Aktivitas 1 — Data contoh')->assertDontSee('Pendahuluan 1 — Data contoh')->assertDontSee('Query digital — Data contoh');
        $m2->assertSee('Diterima')->assertSee('88')->assertSee('Belum diterima')->assertSee('2 tugas cetak · 1 dari 4 penerimaan tercatat');
        // Pendahuluan 2 is listed before Aktivitas 1 for the same student.
        $html = $m2->getContent();
        $this->assertLessThan(strpos($html, 'Aktivitas 1 — Data contoh'), strpos($html, 'Pendahuluan 2 — Data contoh'));
        $this->get("$url?mode=print&meeting_id={$f['m1']}")->assertSee('Pendahuluan 1 — Data contoh')->assertDontSee('Aktivitas 1 — Data contoh');
        $this->get("$url?mode=digital&meeting_id={$f['m2']}")->assertSee('Query digital — Data contoh')->assertDontSee('Aktivitas 1 — Data contoh');
        // Default meeting is the latest started one (meeting 2).
        $this->get($url)->assertSee('Aktivitas 1 — Data contoh');
        $this->get("$url?mode=cetak")->assertSessionHasErrors('mode');
    }

    public function test_meeting_view_and_final_reports_respect_session_scope(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['restricted']);
        $this->get("/praktikum/{$f['o']}/pengumpulan?meeting_id={$f['m2']}")->assertOk()->assertSee('Bunga Lestari')->assertDontSee('Andi Pratama');
        $this->get("/praktikum/{$f['o']}/pengumpulan?session_id={$f['a']}")->assertForbidden();
        $this->get("/praktikum/{$f['o']}/laporan-akhir?session_id={$f['a']}")->assertOk()->assertSee('Belum ada tugas bertipe Laporan akhir');
        DB::table('user_permissions')->insert(['assignment_id' => DB::table('staff_assignments')->where('user_id', $f['restricted']->id)->value('id'), 'permission_key' => 'submissions.manage', 'allowed' => false]);
        $this->get("/praktikum/{$f['o']}/pengumpulan")->assertForbidden();
        $this->get("/praktikum/{$f['o']}/laporan-akhir")->assertForbidden();
    }

    public function test_final_report_page_shows_receipt_and_individual_checklist_progress(): void
    {
        $f = $this->fixtures();
        $final = DB::table('assignments')->insertGetId(['offering_id' => $f['o'], 'type' => 'final', 'mode' => 'print', 'title' => 'Laporan akhir — Data contoh']);
        $items = [];
        foreach (['cover', 'pendahuluan_1', 'aktivitas_1'] as $i => $key) {
            $items[] = DB::table('report_checklist_items')->insertGetId(['assignment_id' => $final, 'key' => $key, 'label' => $key, 'sort_order' => $i]);
        }
        $schedule = DB::table('assignment_schedules')->insertGetId(['offering_id' => $f['o'], 'assignment_id' => $final, 'session_id' => $f['a'], 'opens_at' => now()->subDay(), 'due_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
        $sub = DB::table('submissions')->insertGetId(['offering_id' => $f['o'], 'assignment_id' => $final, 'enrollment_id' => $f['e'][0], 'schedule_id' => $schedule, 'mode' => 'print', 'status' => 'received', 'received_at' => now(), 'recorded_at' => now(), 'receiver_id' => $f['admin']->id]);
        foreach (array_slice($items, 0, 2) as $item) {
            DB::table('report_checklist_results')->insert(['submission_id' => $sub, 'assignment_id' => $final, 'item_id' => $item, 'completed' => true, 'checked_by' => $f['admin']->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $page = $this->actingAs($f['staff'])->get("/praktikum/{$f['o']}/laporan-akhir")->assertOk();
        $page->assertSee('Andi Pratama')->assertSee('2/3 bagian')->assertSee('Bunga Lestari')->assertSee('Belum diterima');
        $page->assertSee("/praktikum/{$f['o']}/tugas/$final/penerimaan/{$f['e'][0]}", false);
        // The sidebar highlights Laporan Akhir on the receipt form of a final report.
        $this->get("/praktikum/{$f['o']}/tugas/$final/penerimaan/{$f['e'][0]}")->assertOk()->assertSee('class="selected" href="http://localhost/praktikum/'.$f['o'].'/laporan-akhir" title="Laporan Akhir"  aria-current="page"', false);
    }

    public function test_page_size_is_selectable_and_validated_on_lists(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['admin']);
        DB::table('assignments')->insert(['offering_id' => $f['o'], 'type' => 'final', 'mode' => 'print', 'title' => 'Laporan akhir']);
        $lists = ["/praktikum/{$f['o']}/pengajuan", "/praktikum/{$f['o']}/kiriman-digital", "/praktikum/{$f['o']}/kelola-pengumuman", "/praktikum/{$f['o']}/tugas", "/praktikum/{$f['o']}/tugas/{$f['akt1']}/penerimaan", "/praktikum/{$f['o']}/nilai", "/praktikum/{$f['o']}/sesi", '/pengaturan/master/semester', "/praktikum/{$f['o']}/pengumpulan", "/praktikum/{$f['o']}/laporan-akhir"];
        foreach ($lists as $url) {
            $this->get("$url?per_page=50")->assertOk()->assertSee('<option value="50" selected>50 per halaman</option>', false);
            $this->get("$url?per_page=7")->assertSessionHasErrors('per_page');
        }
        $rows = $this->get("/praktikum/{$f['o']}/pengumpulan?meeting_id={$f['m2']}&per_page=10")->viewData('rows');
        $this->assertSame(10, $rows->perPage());
    }
}
