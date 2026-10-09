<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Roster;
use App\View\Composers\ManagerShell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesPortalPublic;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use MakesPortalPublic;
    use RefreshDatabase;

    private function fixtures(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $staff = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $outsider = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'UI', 'label' => 'Semester — Data contoh']);
        $p = DB::table('practicums')->insertGetId(['code' => 'UI', 'name' => 'SBD — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p]);
        $p2 = DB::table('practicums')->insertGetId(['code' => 'UX', 'name' => 'Praktikum lain — Data contoh']);
        $foreign = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p2]);
        $a = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A', 'capacity' => 50]);
        $b = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi B', 'capacity' => 50]);
        $grant = DB::table('staff_assignments')->insertGetId(['user_id' => $staff->id, 'offering_id' => $o, 'all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $grant, 'offering_id' => $o, 'session_id' => $a]);
        $this->actingAs($admin);
        foreach ([[$a, 3], [$b, 2]] as [$session, $n]) {
            for ($i = 0; $i < $n; $i++) {
                DB::transaction(fn () => app(Roster::class)->add(['nbi' => '00'.$session.$i, 'name' => 'Praktikan '.$session.$i.' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $session, 'valid_from' => '2026-01-01'], $o));
            }
        }
        $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan — Data contoh']);
        $start = now('Asia/Jakarta')->startOfDay()->addHours(8)->utc();
        $smA = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $a, 'meeting_id' => $m, 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'room' => 'Ruang A — Data contoh']);
        $smB = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $b, 'meeting_id' => $m, 'starts_at' => $start->copy()->addHours(3), 'ends_at' => $start->copy()->addHours(5), 'room' => 'Ruang B — Data contoh']);

        return compact('admin', 'staff', 'outsider', 'o', 'foreign', 'a', 'b', 'grant', 'm', 'smA', 'smB');
    }

    public function test_dashboard_counts_follow_session_scope(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['admin'])->get('/dashboard?praktikum='.$f['o'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertViewHas('summary', fn ($s) => $s['students'] === 5 && $s['schedule']->count() === 2)->assertSee('Ruang B — Data contoh');

        $this->actingAs($f['staff'])->get('/dashboard')->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['students'] === 3 && $s['schedule']->pluck('id')->all() === [$f['smA']])
            ->assertSee('Ruang A — Data contoh')->assertDontSee('Ruang B — Data contoh');
    }

    public function test_offering_switch_rejects_offering_outside_scope(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['staff'])->get('/dashboard?praktikum='.$f['foreign'])->assertForbidden();
        $this->withSession([ManagerShell::SESSION_KEY => $f['foreign']])->get('/dashboard')->assertOk()
            ->assertViewHas('current', fn ($o) => $o->id === $f['o'])->assertDontSee('Praktikum lain — Data contoh');
        $this->actingAs($f['outsider'])->get('/dashboard')->assertOk()->assertViewHas('summary', null)->assertSee('Belum ada praktikum');
    }

    public function test_attendance_overview_is_scoped_and_permission_checked(): void
    {
        $f = $this->fixtures();
        $url = '/praktikum/'.$f['o'].'/presensi';
        $this->actingAs($f['staff'])->get($url)->assertOk()->assertSee('Ruang A — Data contoh')->assertDontSee('Ruang B — Data contoh');
        $this->get($url.'?session_id='.$f['b'])->assertOk()->assertDontSee('Ruang B — Data contoh');
        DB::table('user_permissions')->insert(['assignment_id' => $f['grant'], 'permission_key' => 'attendance.manage', 'allowed' => false]);
        $this->get($url)->assertForbidden();
        $this->actingAs($f['outsider'])->get($url)->assertForbidden();
        $this->actingAs($f['admin'])->get($url)->assertOk()->assertSee('Ruang B — Data contoh');
    }

    public function test_sidebar_menu_follows_permissions_and_future_items_are_not_links(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['staff'])->get('/dashboard')->assertOk()
            ->assertSee('/praktikum/'.$f['o'].'/presensi', false)->assertDontSee('/praktikum/'.$f['o'].'/aturan-nilai', false)
            ->assertSee('/praktikum/'.$f['o'].'/rekap', false)->assertDontSee('Pengaturan Aslab');
        $this->actingAs($f['admin'])->get('/dashboard')->assertOk()->assertSee('/praktikum/'.$f['o'].'/aturan-nilai', false)->assertSee('Pengaturan Aslab');
    }

    public function test_home_lists_only_published_materials_without_manager_shell(): void
    {
        $f = $this->fixtures();
        $file = (string) Str::uuid();
        DB::table('files')->insert(['id' => $file, 'storage_path' => 'private/ui-test.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 10, 'checksum' => str_repeat('a', 64), 'uploaded_by' => $f['admin']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('materials')->insert([
            ['meeting_id' => $f['m'], 'file_id' => $file, 'title' => 'Modul terbit — Data contoh', 'published_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()],
            ['meeting_id' => $f['m'], 'file_id' => $file, 'title' => 'Modul draf — Data contoh', 'published_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        auth()->logout();
        $slug = $this->makePublic($f['o']);
        $this->get('/')->assertOk()->assertSee('SBD — Data contoh')->assertDontSee('Modul draf — Data contoh');
        $this->get('/'.$slug)->assertOk()->assertSee('Modul terbit — Data contoh')->assertDontSee('Modul draf — Data contoh')
            ->assertDontSee('id="sidebar"', false)->assertDontSee('Praktikan 0')->assertSee('Login Aslab');
    }
}
