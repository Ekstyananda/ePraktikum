<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicPortalController;
use App\Models\User;
use App\Services\PracticumPortal;
use App\Services\Roster;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesPortalPublic;
use Tests\TestCase;

/**
 * Multi-practicum portal: hub, slug registry, data isolation per practicum, disabled services,
 * offering changes while a form is open, Tampilan Portal permissions and cover handling.
 */
class MultiPracticumTest extends TestCase
{
    use MakesPortalPublic;
    use RefreshDatabase;

    /** Two public practicums (SBD and PCD) with one student, a session, a meeting, a module and an announcement each. */
    private function fixtures(): array
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $aslab = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'G26', 'label' => 'Gasal 2026 — Data contoh', 'status' => 'active', 'starts_at' => '2026-09-01']);
        $f = ['admin' => $admin, 'aslab' => $aslab, 'sem' => $sem];
        foreach (['sbd' => ['SBD', 'Sistem Basis Data — Data contoh'], 'pcd' => ['PCD', 'Pengolahan Citra Digital — Data contoh']] as $key => [$code, $name]) {
            $p = DB::table('practicums')->insertGetId(['code' => $code, 'name' => $name]);
            $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p, 'status' => 'active']);
            $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi '.$code, 'capacity' => 10, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Lab '.$code]);
            $this->actingAs($admin);
            $e = DB::transaction(fn () => app(Roster::class)->add(['nbi' => $key === 'sbd' ? '1461900001' : '1461900002', 'name' => 'Praktikan '.$code, 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-01-01'], $o));
            $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan '.$code]);
            $start = now()->addDays(2)->startOfHour();
            $sm = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $s, 'meeting_id' => $m, 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'room' => 'Ruang '.$code]);
            $file = (string) Str::uuid();
            Storage::disk('local')->put('materials/'.$file, '%PDF-1.7 '.$code);
            DB::table('files')->insert(['id' => $file, 'storage_path' => 'materials/'.$file, 'original_name' => 'modul-'.$key.'.pdf', 'mime' => 'application/pdf', 'size' => 12, 'checksum' => str_repeat('a', 64), 'visibility' => 'private', 'uploaded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
            $mat = DB::table('materials')->insertGetId(['meeting_id' => $m, 'title' => 'Modul '.$code, 'file_id' => $file, 'published_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
            $ann = DB::table('announcements')->insertGetId(['offering_id' => $o, 'title' => 'Info '.$code, 'body' => 'Isi '.$code, 'audience' => 'public', 'status' => 'published', 'published_at' => now()->subHour(), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('request_windows')->insert(['offering_id' => $o, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(7), 'instructions' => 'Instruksi', 'created_at' => now(), 'updated_at' => now()]);
            auth()->logout();
            $f[$key] = ['p' => $p, 'o' => $o, 's' => $s, 'e' => $e, 'm' => $m, 'sm' => $sm, 'mat' => $mat, 'ann' => $ann, 'nbi' => $key === 'sbd' ? '1461900001' : '1461900002', 'name' => 'Praktikan '.$code, 'slug' => $this->makePublic($o)];
        }
        $f['general'] = DB::table('announcements')->insertGetId(['offering_id' => null, 'title' => 'Info umum lab', 'body' => 'Untuk semua', 'audience' => 'public', 'status' => 'published', 'published_at' => now()->subHour(), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);

        return $f;
    }

    private function izin(array $x, array $extra = []): array
    {
        return $extra + ['offering_id' => $x['o'], 'session_id' => $x['s'], 'nbi' => $x['nbi'], 'name' => $x['name'], 'type' => 'izin', 'source_execution_id' => $x['sm'], 'reason' => 'Sakit — Data contoh', 'confirmed' => 1];
    }

    private function identity(array $f, int $practicum, array $extra = []): array
    {
        $p = DB::table('practicums')->where('id', $practicum)->first();

        return $extra + [
            'version' => $p->portal_version, 'short_name' => $p->short_name ?: 'X', 'accent' => $p->accent, 'hero_preset' => $p->hero_preset, 'hero_mode' => 'preset',
            'services' => array_keys(array_filter(PracticumPortal::services($p))), 'confirmed' => 1,
        ];
    }

    private function png(int $w, int $h, string $append = ''): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 200));
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean().$append;

        return UploadedFile::fake()->createWithContent('sampul.png', $bytes);
    }

    // ---------- Hub and slugs ----------

    public function test_hub_lists_only_public_practicums_and_each_slug_resolves_to_its_offering(): void
    {
        $f = $this->fixtures();
        $draft = DB::table('practicums')->insertGetId(['code' => 'JKD', 'name' => 'Jaringan Komputer Draf']);
        DB::table('practicum_offerings')->insert(['semester_id' => $f['sem'], 'practicum_id' => $draft, 'status' => 'draft']);
        app(PracticumPortal::class)->registerInitial($draft, 'JKD');

        $this->get('/')->assertOk()->assertSee('/'.$f['sbd']['slug'], false)->assertSee('/'.$f['pcd']['slug'], false)->assertDontSee('Jaringan Komputer Draf');
        $this->assertSame(['sbd', 'pcd'], [$f['sbd']['slug'], $f['pcd']['slug']]);
        $this->get('/sbd')->assertOk()->assertSee('Sistem Basis Data')->assertSee('Modul SBD')->assertDontSee('Modul PCD');
        $this->get('/pcd')->assertOk()->assertSee('Pengolahan Citra Digital')->assertSee('Modul PCD')->assertDontSee('Modul SBD');
        // Known practicum without a public offering: friendly closed page, still 404.
        $this->get('/jkd')->assertNotFound()->assertSee('Jaringan Komputer Draf');
        $this->get('/tidak-ada')->assertNotFound();
    }

    public function test_reserved_invalid_and_taken_slugs_are_rejected(): void
    {
        $f = $this->fixtures();
        $portal = app(PracticumPortal::class);
        foreach (['login', 'pengumuman', 'A B', '-x', 'x', str_repeat('a', 51)] as $bad) {
            try {
                $portal->validateSlug($bad);
                $this->fail("Slug '$bad' must be rejected.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $url = "/praktikum/{$f['pcd']['o']}/tampilan-portal";
        $this->actingAs($f['admin'])->post($url, $this->identity($f, $f['pcd']['p'], ['slug' => 'sbd', 'reason' => 'Coba pakai slug praktikum lain']))->assertSessionHasErrors('slug');
        $this->post($url, $this->identity($f, $f['pcd']['p'], ['slug' => 'dashboard', 'reason' => 'Coba pakai slug sistem']))->assertSessionHasErrors('slug');
        $this->assertSame('pcd', DB::table('practicums')->where('id', $f['pcd']['p'])->value('slug'));
        // Aliases stay reserved: once SBD moves to basis-data, PCD still cannot take "sbd".
        $this->post("/praktikum/{$f['sbd']['o']}/tampilan-portal", $this->identity($f, $f['sbd']['p'], ['slug' => 'basis-data', 'reason' => 'Nama alamat lebih jelas']))->assertSessionHasNoErrors();
        $this->post($url, $this->identity($f, $f['pcd']['p'], ['slug' => 'sbd', 'reason' => 'Coba pakai alias praktikum lain']))->assertSessionHasErrors('slug');
        // Database guarantees: one current slug per practicum, slugs unique across current and alias rows.
        $this->expectException(QueryException::class);
        DB::table('practicum_slugs')->insert(['slug' => 'sbd-lain', 'practicum_id' => $f['sbd']['p'], 'is_current' => true]);
    }

    public function test_two_admins_saving_the_same_slug_exactly_one_wins_and_the_other_gets_a_validation_message(): void
    {
        $f = $this->fixtures();
        $admin2 = User::factory()->create(['role' => 'admin', 'active' => true]);
        // Simulate the race: PCD's save passes the pre-check, then SBD's save commits "lab-data" just before PCD inserts.
        $raced = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$raced, $f) {
            if (! $raced && str_starts_with($sql, 'insert into `practicum_slugs`') && in_array('lab-data', $bindings, true) && in_array($f['pcd']['p'], $bindings, true)) {
                $raced = true;
                DB::table('practicum_slugs')->where('practicum_id', $f['sbd']['p'])->update(['is_current' => false]);
                DB::table('practicum_slugs')->insert(['slug' => 'lab-data', 'practicum_id' => $f['sbd']['p'], 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('practicums')->where('id', $f['sbd']['p'])->update(['slug' => 'lab-data']);
            }
        });
        $this->actingAs($admin2)->post("/praktikum/{$f['pcd']['o']}/tampilan-portal", $this->identity($f, $f['pcd']['p'], ['slug' => 'lab-data', 'reason' => 'Admin kedua, bersamaan']))
            ->assertSessionHasErrors(['slug' => 'Slug baru saja dipakai pengguna lain. Pilih slug lain.']);
        $this->assertTrue($raced);
        // In this single-connection simulation the request transaction also rolls back the competing row;
        // what matters is that the loser got a validation message and never owns the slug.
        $this->assertDatabaseMissing('practicum_slugs', ['slug' => 'lab-data', 'practicum_id' => $f['pcd']['p']]);
        // The loser keeps its own current slug and version (nothing half-saved).
        $this->assertSame('pcd', DB::table('practicums')->where('id', $f['pcd']['p'])->value('slug'));
        $this->assertSame(1, DB::table('practicum_slugs')->where('practicum_id', $f['pcd']['p'])->where('is_current', true)->count());
        $this->assertSame(1, DB::table('practicums')->where('id', $f['pcd']['p'])->value('portal_version'));
    }

    public function test_old_slug_redirects_get_with_301_and_post_through_old_slug_is_saved_for_the_same_practicum(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['admin'])->post("/praktikum/{$f['sbd']['o']}/tampilan-portal", $this->identity($f, $f['sbd']['p'], ['slug' => 'basis-data']))->assertSessionHasErrors('reason');
        $this->post("/praktikum/{$f['sbd']['o']}/tampilan-portal", $this->identity($f, $f['sbd']['p'], ['slug' => 'basis-data', 'reason' => 'Nama alamat lebih jelas']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'portal.slug_changed', 'reason' => 'Nama alamat lebih jelas']);
        auth()->logout();
        $this->get('/sbd')->assertStatus(301)->assertRedirect('/basis-data');
        $this->get('/sbd/jadwal?session=1')->assertStatus(301)->assertRedirect('/basis-data/jadwal?session=1');
        $this->get('/basis-data')->assertOk();
        $this->get('/basis-data/jadwal')->assertOk();
        $this->get('/basis-data/modul')->assertOk()->assertSee('Modul SBD');
        // A form opened before the rename posts to the old slug: processed, not redirected, stored for SBD.
        $this->post('/sbd/pengajuan', $this->izin($f['sbd']))->assertOk();
        $this->assertDatabaseHas('academic_requests', ['offering_id' => $f['sbd']['o'], 'enrollment_id' => $f['sbd']['e'], 'type' => 'izin']);
        // Renaming back reuses the alias row; still one current row.
        $this->actingAs($f['admin'])->post("/praktikum/{$f['sbd']['o']}/tampilan-portal", $this->identity($f, $f['sbd']['p'], ['slug' => 'sbd', 'reason' => 'Kembali ke alamat lama']))->assertSessionHasNoErrors();
        $this->assertSame(['basis-data' => 0, 'sbd' => 1], DB::table('practicum_slugs')->where('practicum_id', $f['sbd']['p'])->orderBy('slug')->pluck('is_current', 'slug')->map(fn ($v) => (int) $v)->all());
    }

    public function test_legacy_urls_forward_to_the_only_portal_or_to_the_hub(): void
    {
        $f = $this->fixtures();
        $this->get('/jadwal')->assertRedirect('/');
        $this->get('/modul/'.$f['pcd']['mat'].'/unduh')->assertStatus(301)->assertRedirect('/pcd/modul/'.$f['pcd']['mat'].'/unduh');
        $this->get('/pengumuman/'.$f['pcd']['ann'])->assertRedirect('/pcd/pengumuman/'.$f['pcd']['ann']);
        $this->get('/pengumuman/'.$f['general'])->assertOk()->assertSee('Info umum lab');
        DB::table('practicum_offerings')->where('id', $f['pcd']['o'])->update(['status' => 'draft']);
        $this->get('/jadwal?x=1')->assertStatus(301)->assertRedirect('/sbd/jadwal?x=1');
    }

    // ---------- Isolation ----------

    public function test_a_portal_never_serves_modules_announcements_or_schedules_of_another_practicum(): void
    {
        $f = $this->fixtures();
        $this->get('/sbd/modul')->assertOk()->assertSee('Modul SBD')->assertDontSee('Modul PCD');
        $this->get('/sbd/modul/'.$f['pcd']['mat'].'/unduh')->assertNotFound();
        $this->get('/sbd/modul/'.$f['sbd']['mat'].'/unduh')->assertOk();
        $this->get('/sbd/pengumuman')->assertOk()->assertSee('Info SBD')->assertSee('Info umum lab')->assertDontSee('Info PCD');
        $this->get('/sbd/pengumuman/'.$f['pcd']['ann'])->assertNotFound();
        $this->get('/sbd/pengumuman/'.$f['general'])->assertOk();
        $this->get('/sbd/jadwal')->assertOk()->assertSee('Ruang SBD')->assertDontSee('Ruang PCD');
        $this->get('/')->assertSee('Info umum lab')->assertDontSee('Info PCD');
    }

    public function test_forms_reject_offerings_sessions_and_schedules_of_another_practicum_and_store_nothing(): void
    {
        $f = $this->fixtures();
        // PCD student/offering posted through the SBD portal.
        $this->from('/sbd/pengajuan')->post('/sbd/pengajuan', $this->izin($f['pcd']))->assertSessionHasErrors('offering_id');
        // SBD offering but PCD session / execution.
        $this->post('/sbd/pengajuan', $this->izin($f['sbd'], ['session_id' => $f['pcd']['s']]))->assertSessionHasErrors();
        $this->post('/sbd/pengajuan', $this->izin($f['sbd'], ['source_execution_id' => $f['pcd']['sm']]))->assertSessionHasErrors();
        // Schedule of PCD posted to SBD.
        $a = DB::table('assignments')->insertGetId(['offering_id' => $f['pcd']['o'], 'title' => 'Tugas PCD', 'type' => 'aktivitas', 'mode' => 'digital', 'origin_meeting_id' => $f['pcd']['m'], 'active' => 1, 'mandatory' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sc = DB::table('assignment_schedules')->insertGetId(['offering_id' => $f['pcd']['o'], 'assignment_id' => $a, 'session_id' => $f['pcd']['s'], 'opens_at' => now()->subDay(), 'due_at' => now()->addDay(), 'closes_at' => now()->addDays(2), 'created_at' => now(), 'updated_at' => now()]);
        $pdf = UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.7\n%%EOF\n");
        $this->post('/sbd/pengumpulan', ['offering_id' => $f['sbd']['o'], 'session_id' => $f['sbd']['s'], 'nbi' => $f['sbd']['nbi'], 'name' => $f['sbd']['name'], 'schedule_id' => $sc, 'file' => $pdf, 'confirmed' => 1])->assertSessionHasErrors();
        $this->assertDatabaseCount('academic_requests', 0);
        $this->assertDatabaseCount('public_deliveries', 0);
    }

    // ---------- Services ----------

    public function test_disabled_service_returns_404_for_get_and_post_and_stores_nothing(): void
    {
        $f = $this->fixtures();
        DB::table('practicums')->where('id', $f['sbd']['p'])->update(['services' => json_encode(['jadwal' => true, 'modul' => false, 'pengumpulan' => false, 'pengajuan' => false, 'remidi' => false])]);
        $this->get('/sbd/modul')->assertNotFound();
        $this->get('/sbd/modul/'.$f['sbd']['mat'].'/unduh')->assertNotFound();
        $this->get('/sbd/pengajuan')->assertNotFound();
        $this->get('/sbd/remidi')->assertNotFound();
        $this->get('/sbd/pengumpulan')->assertNotFound();
        $this->get('/sbd/jadwal')->assertOk();
        $this->get('/sbd')->assertOk()->assertDontSee('Modul SBD')->assertDontSee('/sbd/pengajuan', false);
        $this->post('/sbd/pengajuan', $this->izin($f['sbd']))->assertNotFound();
        $this->post('/sbd/remidi', $this->izin($f['sbd'], ['type' => 'remidi']))->assertNotFound();
        $this->post('/sbd/pengumpulan', ['offering_id' => $f['sbd']['o']])->assertNotFound();
        // Legacy POST without slug is blocked by the same switch.
        $this->post('/pengajuan', $this->izin($f['sbd']))->assertNotFound();
        // Remidi type cannot sneak through the pengajuan endpoint of a portal that has pengajuan enabled.
        $this->post('/pcd/pengajuan', $this->izin($f['pcd'], ['type' => 'remidi']))->assertNotFound();
        $this->assertDatabaseCount('academic_requests', 0);
        // Other practicum unaffected.
        $this->get('/pcd/modul')->assertOk();
    }

    // ---------- Offerings ----------

    public function test_newest_active_offering_is_public_by_semester_start_then_id(): void
    {
        $f = $this->fixtures();
        $portal = app(PracticumPortal::class);
        $older = DB::table('semesters')->insertGetId(['code' => 'G25', 'label' => 'Gasal 2025', 'status' => 'active', 'starts_at' => '2025-09-01']);
        DB::table('practicum_offerings')->insert(['semester_id' => $older, 'practicum_id' => $f['sbd']['p'], 'status' => 'active']);
        $this->assertSame($f['sbd']['o'], $portal->activeOffering($f['sbd']['p'])->id);
        $newer = DB::table('semesters')->insertGetId(['code' => 'G27', 'label' => 'Genap 2027', 'status' => 'active', 'starts_at' => '2027-02-01']);
        $o2 = DB::table('practicum_offerings')->insertGetId(['semester_id' => $newer, 'practicum_id' => $f['sbd']['p'], 'status' => 'active']);
        $this->assertSame($o2, $portal->activeOffering($f['sbd']['p'])->id);
        // Same semester start: highest id wins. Locked semester is never public.
        $twin = DB::table('semesters')->insertGetId(['code' => 'G27B', 'label' => 'Genap 2027 B', 'status' => 'active', 'starts_at' => '2027-02-01']);
        $o3 = DB::table('practicum_offerings')->insertGetId(['semester_id' => $twin, 'practicum_id' => $f['sbd']['p'], 'status' => 'active']);
        $this->assertSame($o3, $portal->activeOffering($f['sbd']['p'])->id);
        DB::table('semesters')->whereIn('id', [$newer, $twin])->update(['status' => 'locked']);
        $this->assertSame($f['sbd']['o'], $portal->activeOffering($f['sbd']['p'])->id);
        $this->get('/sbd')->assertOk()->assertSee('Gasal 2026');
    }

    public function test_form_sent_after_the_active_offering_changed_is_rejected_with_input_kept_and_nothing_saved(): void
    {
        $f = $this->fixtures();
        // Page opened for the current offering; meanwhile a newer semester's offering becomes active.
        $newer = DB::table('semesters')->insertGetId(['code' => 'G27', 'label' => 'Genap 2027', 'status' => 'active', 'starts_at' => '2027-02-01']);
        $o2 = DB::table('practicum_offerings')->insertGetId(['semester_id' => $newer, 'practicum_id' => $f['sbd']['p'], 'status' => 'active']);
        $this->from('/sbd/pengajuan')->post('/sbd/pengajuan', $this->izin($f['sbd']))
            ->assertRedirect('/sbd/pengajuan')->assertSessionHasErrors(['offering_id' => PublicPortalController::PERIOD_CHANGED])
            ->assertSessionHasInput('nbi', $f['sbd']['nbi'])->assertSessionHasInput('reason', 'Sakit — Data contoh');
        // Same through the legacy endpoint and through an old slug alias.
        $this->from('/pengajuan')->post('/pengajuan', $this->izin($f['sbd']))->assertSessionHasErrors(['offering_id' => PublicPortalController::PERIOD_CHANGED]);
        // Offering closed entirely (no public offering left): closed page, nothing processed.
        DB::table('practicum_offerings')->whereIn('id', [$f['sbd']['o'], $o2])->update(['status' => 'draft']);
        $this->post('/sbd/pengajuan', $this->izin($f['sbd']))->assertNotFound();
        $this->assertDatabaseCount('academic_requests', 0);
        $this->assertDatabaseMissing('academic_requests', ['offering_id' => $o2]);
    }

    // ---------- Tampilan Portal ----------

    public function test_portal_manage_permission_default_deny_grant_and_restricted_scope(): void
    {
        $f = $this->fixtures();
        $url = "/praktikum/{$f['sbd']['o']}/tampilan-portal";
        $a = DB::table('staff_assignments')->insertGetId(['user_id' => $f['aslab']->id, 'offering_id' => $f['sbd']['o'], 'all_sessions' => true]);
        $this->actingAs($f['aslab'])->get($url)->assertForbidden();
        $this->post($url, $this->identity($f, $f['sbd']['p']))->assertForbidden();
        DB::table('user_permissions')->insert(['assignment_id' => $a, 'permission_key' => 'portal.manage', 'allowed' => true]);
        $this->get($url)->assertOk()->assertSee('semua semester')->assertDontSee('id="slug"', false);
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['tagline' => 'Basis data relasional']))->assertSessionHasNoErrors();
        $this->assertSame('Basis data relasional', DB::table('practicums')->where('id', $f['sbd']['p'])->value('tagline'));
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['services' => ['jadwal', 'modul']]))->assertSessionHasNoErrors();
        $this->assertSame(['jadwal' => true, 'modul' => true, 'pengumpulan' => false, 'pengajuan' => false, 'remidi' => false], PracticumPortal::services(DB::table('practicums')->where('id', $f['sbd']['p'])->first()));
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['services' => array_keys(PracticumPortal::SERVICES)]))->assertSessionHasNoErrors();
        // Aslab with the permission cannot change the address.
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['slug' => 'basis-data', 'reason' => 'Aslab mencoba ubah alamat']))->assertSessionHasErrors('slug');
        // Permission on SBD does not reach PCD.
        $this->get("/praktikum/{$f['pcd']['o']}/tampilan-portal")->assertForbidden();
        // Session-restricted assignment: denied even with the permission.
        DB::table('staff_assignments')->where('id', $a)->update(['all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $a, 'offering_id' => $f['sbd']['o'], 'session_id' => $f['sbd']['s']]);
        $this->get($url)->assertForbidden();
        $this->actingAs($f['admin'])->get($url)->assertOk()->assertSee('id="slug"', false)->assertSee('qr-box', false)->assertSee('vendor/qrcode-generator/qrcode.js', false);
    }

    public function test_identity_is_escaped_palette_only_versioned_and_needs_confirmation(): void
    {
        $f = $this->fixtures();
        $url = "/praktikum/{$f['sbd']['o']}/tampilan-portal";
        $this->actingAs($f['admin']);
        $this->post($url, array_diff_key($this->identity($f, $f['sbd']['p']), ['confirmed' => 1]))->assertSessionHasErrors('confirmed');
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['accent' => '#ff0000']))->assertSessionHasErrors('accent');
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['accent' => 'red;background:url(x)']))->assertSessionHasErrors('accent');
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['contact_url' => 'javascript:alert(1)']))->assertSessionHasErrors('contact_url');
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['services' => ['jadwal', 'shell']]))->assertSessionHasErrors('services.1');
        $xss = '<script>alert(1)</script>';
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['display_name' => $xss, 'tagline' => '"><img src=x onerror=alert(2)>', 'accent' => 'teal']))->assertSessionHasNoErrors();
        // Stale version (form opened before another save) does not overwrite.
        $this->post($url, $this->identity($f, $f['sbd']['p'], ['version' => 1, 'tagline' => 'Basi']))->assertStatus(409);
        auth()->logout();
        foreach (['/', '/sbd'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
        $this->get('/sbd')->assertSee(PracticumPortal::PALETTE['teal']['accent'], false);
        // Empty display name falls back to the master name.
        $this->actingAs($f['admin'])->post($url, $this->identity($f, $f['sbd']['p'], ['display_name' => '']))->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('/sbd')->assertSee('Sistem Basis Data — Data contoh');
    }

    public function test_cover_upload_validates_type_size_dimensions_and_is_reencoded_to_webp(): void
    {
        $f = $this->fixtures();
        $url = "/praktikum/{$f['sbd']['o']}/tampilan-portal";
        $this->actingAs($f['admin']);
        $up = fn (UploadedFile $file) => $this->post($url, $this->identity($f, $f['sbd']['p'], ['hero_mode' => 'upload', 'cover' => $file]));
        $up(UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors('cover');
        $up(UploadedFile::fake()->createWithContent('x.png', '<?php system($_GET["c"]); ?>'))->assertSessionHasErrors('cover');
        $up(UploadedFile::fake()->createWithContent('x.php', '<?php echo 1;'))->assertSessionHasErrors('cover');
        $up(UploadedFile::fake()->create('besar.png', 1100, 'image/png'))->assertSessionHasErrors('cover');
        $up($this->png(500, 250))->assertSessionHasErrors('cover');
        $up($this->png(2600, 1000))->assertSessionHasErrors('cover');
        $this->assertDatabaseCount('files', 2);
        $this->assertSame([], Storage::disk('local')->files('portal/covers'));
        // Valid PNG with a payload appended: stored as a fresh WebP without the payload.
        $up($this->png(1200, 600, '<?php system("id"); ?>'))->assertSessionHasNoErrors();
        $p = DB::table('practicums')->where('id', $f['sbd']['p'])->first();
        $file = DB::table('files')->where('id', $p->hero_file_id)->first();
        $this->assertSame('image/webp', $file->mime);
        $bytes = Storage::disk('local')->get($file->storage_path);
        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertSame('WEBP', substr($bytes, 8, 4));
        $this->assertStringNotContainsString('<?php', $bytes);
        $this->assertSame([1200, 600], array_slice(getimagesizefromstring($bytes), 0, 2));
        auth()->logout();
        $this->get('/sampul/'.$f['sbd']['p'])->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/sbd')->assertSee('/sampul/'.$f['sbd']['p'], false);
        // Not public anymore: cover is not served.
        DB::table('practicum_offerings')->where('id', $f['sbd']['o'])->update(['status' => 'draft']);
        $this->get('/sampul/'.$f['sbd']['p'])->assertNotFound();
    }
}
