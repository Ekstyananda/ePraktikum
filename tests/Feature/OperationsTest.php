<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment;
use App\Services\Audit;
use App\Services\Backup;
use App\Services\MeetingRoster;
use App\Services\Roster;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesPortalPublic;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use MakesPortalPublic;
    use RefreshDatabase;

    private string $backupDir;

    private function fixtures(): array
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $staff = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $restricted = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'M6', 'label' => 'Semester — Data contoh', 'status' => 'active']);
        $p = DB::table('practicums')->insertGetId(['code' => 'M6', 'name' => 'SBD — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p, 'status' => 'active']);
        $a = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A', 'capacity' => 20]);
        $b = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi B', 'capacity' => 20]);
        $grant = DB::table('staff_assignments')->insertGetId(['user_id' => $staff->id, 'offering_id' => $o, 'all_sessions' => true]);
        $rgrant = DB::table('staff_assignments')->insertGetId(['user_id' => $restricted->id, 'offering_id' => $o, 'all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $rgrant, 'offering_id' => $o, 'session_id' => $b]);
        $this->actingAs($admin);
        $e = [];
        foreach ([[$a, 'Andi Pratama'], [$b, 'Bunga Lestari'], [$b, '=HYPERLINK("x")']] as $i => [$s, $name]) {
            $e[] = DB::transaction(fn () => app(Roster::class)->add(['nbi' => '00600'.$i, 'name' => $name, 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-01-01'], $o));
        }
        $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan 1']);
        $smA = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $a, 'meeting_id' => $m, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHours(2), 'room' => 'Lab A']);
        $smB = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $b, 'meeting_id' => $m, 'starts_at' => now()->subHours(5), 'ends_at' => now()->subHours(3), 'room' => 'Lab B']);
        app(MeetingRoster::class)->snapshot($admin, $o, $smA, 1);
        app(MeetingRoster::class)->snapshot($admin, $o, $smB, 1);
        DB::table('attendances')->update(['status' => 'present', 'recorded_at' => now()]);
        auth()->logout();

        return compact('admin', 'staff', 'restricted', 'sem', 'o', 'a', 'b', 'grant', 'rgrant', 'e', 'm', 'smA', 'smB');
    }

    // ---------- Semester lock and admin reopen ----------

    public function test_semester_lock_stops_writes_and_reopen_needs_admin_reason_and_is_logged(): void
    {
        $f = $this->fixtures();
        $lock = fn ($action, $reason = 'Semester selesai — Data contoh') => $this->post("/pengaturan/master/semester/{$f['sem']}/kunci", ['action' => $action, 'version' => DB::table('semesters')->value('version'), 'reason' => $reason, 'confirmed' => 1]);
        $this->actingAs($f['staff']);
        $lock('lock')->assertForbidden();
        $this->actingAs($f['admin']);
        $lock('lock', 'pendek')->assertSessionHasErrors('reason');
        $lock('lock')->assertRedirect();
        $this->assertSame('locked', DB::table('semesters')->value('status'));
        // Writes are rejected everywhere, including the announcement editor and attendance.
        $participant = DB::table('meeting_participants')->first();
        $this->post("/praktikum/{$f['o']}/presensi/{$f['smA']}", ['selected' => [$participant->id], 'rows' => [$participant->id => ['version' => 1, 'status' => 'absent']], 'action' => 'save', 'reason' => 'Koreksi uji', 'confirmed' => 1])->assertStatus(423);
        $this->post("/praktikum/{$f['o']}/kelola-pengumuman", ['title' => 'X', 'body' => 'Y', 'audience' => 'public', 'action' => 'draft', 'version' => 0])->assertStatus(423);
        $this->post("/pengaturan/master/offering/{$f['o']}/kunci", ['action' => 'reopen', 'version' => DB::table('practicum_offerings')->value('version'), 'reason' => 'Coba buka praktikum', 'confirmed' => 1])->assertSessionHasErrors('action');
        $this->get("/pengaturan/master/semester/{$f['sem']}/edit")->assertOk();
        $this->put("/pengaturan/master/semester/{$f['sem']}", ['code' => 'M6', 'label' => 'Ubah', 'starts_at' => '2026-01-01', 'ends_at' => '2026-06-01', 'status' => 'active', 'version' => DB::table('semesters')->value('version'), 'confirmed' => 1, 'reason' => 'Ubah label'])->assertStatus(423);
        $lock('reopen', 'Koreksi nilai susulan — Data contoh')->assertRedirect();
        $this->assertSame('active', DB::table('semesters')->value('status'));
        $log = DB::table('activity_logs')->where('action', 'master.reopened')->first();
        $this->assertSame('Koreksi nilai susulan — Data contoh', $log->reason);
        $this->assertSame($f['admin']->id, $log->actor_id);
        $this->post("/praktikum/{$f['o']}/presensi/{$f['smA']}", ['selected' => [$participant->id], 'rows' => [$participant->id => ['version' => 1, 'status' => 'absent']], 'action' => 'save', 'reason' => 'Koreksi uji', 'confirmed' => 1])->assertRedirect();
    }

    // ---------- Final result reopen ----------

    private function finalResult(array $f, int $enrollment, int $version = 1): int
    {
        $rule = DB::table('grading_rules')->where('offering_id', $f['o'])->value('id') ?? DB::table('grading_rules')->insertGetId(['offering_id' => $f['o'], 'version' => 1, 'config_json' => '{}', 'status' => 'published', 'created_by' => $f['admin']->id]);

        return DB::table('final_results')->insertGetId(['offering_id' => $f['o'], 'enrollment_id' => $enrollment, 'version' => $version, 'rules_id' => $rule, 'score' => 81, 'letter' => 'A', 'decision' => 'Lulus', 'snapshot_json' => json_encode(['identity' => ['nbi' => '006000', 'name' => 'Andi Pratama'], 'rules_version' => 1, 'components' => []]), 'finalized_by' => $f['admin']->id, 'finalized_at' => now()]);
    }

    public function test_admin_reopens_final_with_reason_keeping_history_and_one_active_version(): void
    {
        $f = $this->fixtures();
        $final = $this->finalResult($f, $f['e'][0]);
        try {
            $this->finalResult($f, $f['e'][0], 2);
            $this->fail('Two active finals must be rejected by the database.');
        } catch (QueryException) {
        }
        $url = "/praktikum/{$f['o']}/nilai/{$f['e'][0]}/final/buka";
        $this->actingAs($f['staff'])->post($url, ['final_id' => $final, 'reason' => 'Koreksi nilai lab', 'confirmed' => 1])->assertForbidden();
        $this->actingAs($f['admin'])->post($url, ['final_id' => $final, 'reason' => 'pendek', 'confirmed' => 1])->assertSessionHasErrors('reason');
        $this->post($url, ['final_id' => $final, 'reason' => 'Koreksi nilai lab — Data contoh', 'confirmed' => 1])->assertRedirect();
        $this->post($url, ['final_id' => $final, 'reason' => 'Koreksi nilai lab — Data contoh', 'confirmed' => 1])->assertStatus(409);
        $row = DB::table('final_results')->find($final);
        $this->assertNotNull($row->superseded_at);
        $this->assertSame('Koreksi nilai lab — Data contoh', $row->supersede_reason);
        // Writes are open again and a new final version can be stored.
        app(Assessment::class)->writable($f['o'], $f['e'][0]);
        $this->finalResult($f, $f['e'][0], 2);
        $this->get("/praktikum/{$f['o']}/nilai/{$f['e'][0]}/final")->assertOk()->assertSee('Riwayat arsip final')->assertSee('Koreksi nilai lab — Data contoh');
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'grade.final_reopened')->count());
    }

    // ---------- Announcements ----------

    public function test_announcements_are_sanitized_scoped_and_only_published_public_ones_are_visible(): void
    {
        $f = $this->fixtures();
        $base = "/praktikum/{$f['o']}/kelola-pengumuman";
        $this->actingAs($f['restricted'])->get($base)->assertForbidden();
        $this->actingAs($f['staff']);
        $body = "**Penting** <script>alert(1)</script> [klik](javascript:alert(2)) <img src=x onerror=alert(3)>\n\n- satu\n- dua";
        $this->post($base, ['title' => 'Jadwal ujian — Data contoh', 'body' => $body, 'audience' => 'public', 'action' => 'draft', 'version' => 0])->assertRedirect();
        $id = DB::table('announcements')->value('id');
        $this->post($base, ['title' => 'Umum', 'body' => 'x', 'audience' => 'public', 'general' => 1, 'action' => 'publish', 'version' => 0])->assertSessionHasErrors('general');
        auth()->logout();
        $slug = $this->makePublic($f['o']);
        $this->get('/pengumuman')->assertDontSee('Jadwal ujian — Data contoh');
        $this->get("/$slug/pengumuman/$id")->assertNotFound();
        $this->get("/pengumuman/$id")->assertNotFound();
        $this->actingAs($f['staff'])->post("$base/$id", ['title' => 'Tanpa centang', 'body' => $body, 'audience' => 'public', 'action' => 'publish', 'version' => 1])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseHas('announcements', ['id' => $id, 'status' => 'draft', 'version' => 1]);
        $this->actingAs($f['staff'])->post("$base/$id", ['title' => 'Jadwal ujian — Data contoh', 'body' => $body, 'audience' => 'public', 'action' => 'publish', 'confirmed' => 1, 'version' => 1, 'reason' => 'Terbitkan'])->assertRedirect();
        $this->post("$base/$id", ['title' => 'Basi', 'body' => $body, 'audience' => 'public', 'action' => 'publish', 'confirmed' => 1, 'version' => 1, 'reason' => 'Versi lama dikirim ulang'])->assertStatus(409);
        $this->post($base, ['title' => 'Rapat aslab — Data contoh', 'body' => 'Internal', 'audience' => 'staff', 'action' => 'publish', 'confirmed' => 1, 'version' => 0])->assertRedirect();
        auth()->logout();
        // Old link without slug forwards to the practicum portal.
        $this->get("/pengumuman/$id")->assertRedirect("/$slug/pengumuman/$id");
        $page = $this->get("/$slug/pengumuman/$id")->assertOk()->assertSee('<strong>Penting</strong>', false)->assertSee('<li>satu</li>', false);
        $this->assertStringNotContainsString('<script>alert', $page->getContent());
        $this->assertStringNotContainsString('javascript:alert', $page->getContent());
        $this->assertStringNotContainsString('onerror', $page->getContent());
        $this->get("/$slug")->assertSee('Jadwal ujian — Data contoh')->assertDontSee('Rapat aslab — Data contoh');
        $this->get('/')->assertDontSee('Jadwal ujian — Data contoh');
        $this->get('/pengumuman')->assertDontSee('Rapat aslab — Data contoh');
        $this->actingAs($f['staff'])->get('/dashboard')->assertSee('Rapat aslab — Data contoh');
        $this->post("$base/$id/arsip", ['version' => 2, 'reason' => 'Sudah lewat', 'confirmed' => 1])->assertRedirect();
        auth()->logout();
        $this->get("/$slug/pengumuman/$id")->assertNotFound();
        $this->get("/pengumuman/$id")->assertNotFound();
        // Semester lock hides everything of that offering from the public.
        $this->actingAs($f['admin'])->post($base, ['title' => 'Umum — Data contoh', 'body' => 'Untuk semua', 'audience' => 'public', 'general' => 1, 'action' => 'publish', 'confirmed' => 1, 'version' => 0])->assertRedirect();
        $this->assertNull(DB::table('announcements')->where('title', 'Umum — Data contoh')->value('offering_id'));
    }

    // ---------- Reports ----------

    public function test_reports_follow_session_scope_neutralize_formulas_and_use_final_snapshot(): void
    {
        $f = $this->fixtures();
        $assignment = DB::table('assignments')->insertGetId(['offering_id' => $f['o'], 'origin_meeting_id' => $f['m'], 'type' => 'lab', 'mode' => 'direct', 'title' => 'Lab 1']);
        $component = DB::table('grading_components')->insertGetId(['offering_id' => $f['o'], 'assignment_id' => $assignment, 'label' => 'Lab 1', 'weight' => 100, 'max_score' => 100]);
        DB::table('grades')->insert(['offering_id' => $f['o'], 'component_id' => $component, 'enrollment_id' => $f['e'][1], 'score' => 55, 'status' => 'graded', 'evaluator_id' => $f['admin']->id]);
        $rule = DB::table('grading_rules')->insertGetId(['offering_id' => $f['o'], 'version' => 3, 'config_json' => '{}', 'status' => 'published', 'created_by' => $f['admin']->id]);
        // Finalized with 70 although the live grade is now 55: the report must show the archived value.
        DB::table('final_results')->insert(['offering_id' => $f['o'], 'enrollment_id' => $f['e'][1], 'rules_id' => $rule, 'score' => 70, 'letter' => 'B', 'decision' => 'Lulus', 'finalized_by' => $f['admin']->id, 'finalized_at' => now(),
            'snapshot_json' => json_encode(['identity' => ['nbi' => '006001'], 'rules_version' => 3, 'components' => [['component' => ['id' => $component], 'grade' => ['score' => '70.0000', 'status' => 'graded']]]])]);
        $url = "/praktikum/{$f['o']}/rekap";
        $this->actingAs($f['restricted']);
        $this->get("$url?type=presensi")->assertOk()->assertSee('Bunga Lestari')->assertDontSee('Andi Pratama');
        $this->get("$url?type=pengumpulan")->assertOk()->assertDontSee('Andi Pratama');
        $this->get("$url?type=presensi&session_id={$f['a']}")->assertForbidden();
        $grades = $this->get("$url?type=nilai")->assertOk()->assertSee('Final v1 · aturan v3')->getContent();
        $this->assertStringContainsString('<td>70</td>', $grades);
        $csv = $this->get("$url?type=presensi&format=csv")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString('Andi Pratama', $csv);
        $this->assertStringContainsString('Hadir', $csv);
        $xlsx = $this->get("$url?type=nilai&format=xlsx")->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->streamedContent();
        $this->assertStringStartsWith('PK', $xlsx);
        $this->assertSame(2, DB::table('activity_logs')->where('action', 'report.exported')->count());
        $this->get("$url?type=presensi&format=print")->assertOk()->assertSee('A4 landscape')->assertDontSee('Andi Pratama');
        DB::table('user_permissions')->insert(['assignment_id' => $f['rgrant'], 'permission_key' => 'reports.export', 'allowed' => false]);
        $this->get($url)->assertForbidden();
    }

    // ---------- Activity log ----------

    public function test_activity_log_is_read_only_scoped_and_redacts_secrets(): void
    {
        $f = $this->fixtures();
        DB::table('activity_logs')->insert([
            ['actor_id' => $f['admin']->id, 'offering_id' => $f['o'], 'entity_type' => 'attendance', 'entity_id' => 1, 'action' => 'attendance.corrected', 'before_json' => json_encode(['status' => 'present', 'session_id' => $f['b']]), 'after_json' => json_encode(['status' => 'absent', 'token_hash' => 'abc', 'password' => 'Rahasia123']), 'reason' => 'Koreksi TTD', 'request_id' => 'b1', 'created_at' => now()],
            ['actor_id' => $f['admin']->id, 'offering_id' => null, 'entity_type' => 'user', 'entity_id' => $f['staff']->id, 'action' => 'aslab.updated', 'before_json' => null, 'after_json' => json_encode(['name' => 'Akun']), 'reason' => 'Ubah akun', 'request_id' => 'b2', 'created_at' => now()],
        ]);
        $id = DB::table('activity_logs')->where('action', 'attendance.corrected')->value('id');
        $this->actingAs($f['admin'])->get('/pengaturan/log-aktivitas')->assertOk()->assertSee('attendance.corrected')->assertSee('aslab.updated');
        $this->get("/pengaturan/log-aktivitas/$id")->assertOk()->assertSee('Koreksi TTD')->assertSee('disamarkan')->assertDontSee('Rahasia123')->assertDontSee('>abc<', false);
        $this->get("/pengaturan/log-aktivitas?session_id={$f['b']}&offering_id={$f['o']}")->assertOk()->assertSee('attendance.corrected');
        $this->actingAs($f['staff']);
        $this->get('/pengaturan/log-aktivitas')->assertForbidden();
        $this->get("/praktikum/{$f['o']}/log-aktivitas")->assertForbidden();
        DB::table('user_permissions')->insert(['assignment_id' => $f['grant'], 'permission_key' => 'logs.view', 'allowed' => true]);
        $this->get("/praktikum/{$f['o']}/log-aktivitas")->assertOk()->assertSee('attendance.corrected')->assertDontSee('aslab.updated');
        $this->get("/praktikum/{$f['o']}/log-aktivitas/$id")->assertOk()->assertDontSee('Rahasia123');
        $other = DB::table('activity_logs')->where('action', 'aslab.updated')->value('id');
        $this->get("/praktikum/{$f['o']}/log-aktivitas/$other")->assertNotFound();
        // A session-restricted aslab cannot hold an offering-wide log permission.
        DB::table('user_permissions')->insert(['assignment_id' => $f['rgrant'], 'permission_key' => 'logs.view', 'allowed' => true]);
        $this->actingAs($f['restricted'])->get("/praktikum/{$f['o']}/log-aktivitas")->assertForbidden();
        $writes = collect(Route::getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'log-aktivitas') && array_diff($r->methods(), ['GET', 'HEAD']))->count();
        $this->assertSame(0, $writes, 'The log has no write routes.');
    }

    // ---------- Backup ----------

    private function backupDir(): string
    {
        $this->backupDir = sys_get_temp_dir().'/portal-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->backupDir);
        config(['backup.destination' => $this->backupDir]);

        return $this->backupDir;
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->backupDir) && is_dir($this->backupDir)) {
                chmod($this->backupDir, 0700);
                foreach (array_diff(scandir($this->backupDir), ['.', '..']) as $item) {
                    $path = $this->backupDir.'/'.$item;
                    is_dir($path) ? @rmdir($path) : @unlink($path);
                }
                @rmdir($this->backupDir);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_backup_succeeds_only_with_database_and_files_and_is_verifiable_and_encrypted(): void
    {
        $f = $this->fixtures();
        $dir = $this->backupDir();
        config(['backup.password' => 'kunci-uji-rahasia', 'database.connections.mysql.password' => config('database.connections.mysql.password')]);
        Storage::disk('local')->put('academic/sample-1', 'isi berkas privat');
        $backup = app(Backup::class);
        $run = $backup->run($backup->queue('manual', $f['admin']->id));
        $this->assertSame('success', $run->status, (string) $run->error_summary);
        $archive = "$dir/{$run->artifact_ref}";
        $this->assertFileExists($archive);
        $this->assertSame(hash_file('sha256', $archive), $run->checksum);
        $this->assertStringContainsString($run->checksum, file_get_contents("$archive.sha256"));
        $manifest = json_decode($run->manifest_json, true);
        $this->assertSame(1, $manifest['files']);
        $this->assertTrue($manifest['encrypted']);
        $zip = new \ZipArchive;
        $zip->open($archive);
        $this->assertFalse($zip->getFromName('database.sql'), 'Encrypted entries must not open without the password.');
        $zip->setPassword('kunci-uji-rahasia');
        $sql = $zip->getFromName('database.sql');
        $zip->close();
        $this->assertStringContainsString('INSERT INTO `students`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `sessions`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `cache`', $sql);
        $this->assertSame([], glob("$dir/.partial-*") ?: []);
        $this->assertFalse(DB::table('activity_logs')->where('after_json', 'like', '%kunci-uji-rahasia%')->exists());
    }

    public function test_failed_backup_leaves_no_archive_and_no_secret_in_error(): void
    {
        $f = $this->fixtures();
        $dir = $this->backupDir();
        config(['backup.password' => 'kunci-uji-rahasia']);
        chmod($dir, 0500);
        $backup = app(Backup::class);
        $run = $backup->run($backup->queue('manual', null));
        chmod($dir, 0700);
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->artifact_ref);
        $this->assertStringNotContainsString('kunci-uji-rahasia', $run->error_summary);
        $this->assertStringNotContainsString($dir, $run->error_summary);
        $this->assertSame([], array_values(array_diff(scandir($dir), ['.', '..'])));
        // A file that disappears between listing and archiving fails the whole run.
        Storage::disk('local')->put('academic/hilang', 'x');
        Storage::disk('local')->put('academic/ada', 'y');
        $partial = $backup->queue('manual', null);
        $original = Storage::disk('local')->path('academic/hilang');
        chmod($original, 0000);
        $run = $backup->run($partial);
        chmod($original, 0600);
        $this->assertSame('failed', $run->status);
        $this->assertSame([], glob("$dir/*.zip") ?: []);
    }

    public function test_retention_prunes_only_old_application_archives(): void
    {
        $f = $this->fixtures();
        $dir = $this->backupDir();
        file_put_contents("$dir/catatan-operator.txt", 'jangan dihapus');
        DB::table('settings')->insert(['key' => 'backup.retention', 'value_json' => '2', 'created_at' => now(), 'updated_at' => now()]);
        $backup = app(Backup::class);
        $names = [];
        for ($i = 0; $i < 3; $i++) {
            $run = $backup->run($backup->queue('manual', null));
            $this->assertSame('success', $run->status);
            DB::table('backup_runs')->where('id', $run->id)->update(['finished_at' => now()->addMinutes($i)]);
            $names[] = $run->artifact_ref;
            sleep(1);
        }
        $backup->prune();
        $this->assertFileDoesNotExist("$dir/{$names[0]}");
        $this->assertFileExists("$dir/{$names[1]}");
        $this->assertFileExists("$dir/{$names[2]}");
        $this->assertFileExists("$dir/catatan-operator.txt");
        $this->assertNotNull(DB::table('backup_runs')->where('artifact_ref', $names[0])->value('pruned_at'));
    }

    public function test_restore_verification_uses_an_isolated_database_and_refuses_the_live_one(): void
    {
        $f = $this->fixtures();
        $dir = $this->backupDir();
        $root = env('MYSQL_TEST_ROOT_PASSWORD');
        $this->assertNotEmpty($root, 'MYSQL_TEST_ROOT_PASSWORD is required to create the isolated restore database.');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');
        (new \PDO("mysql:host=$host;port=$port", 'root', $root))->exec('CREATE DATABASE IF NOT EXISTS portal_restore_test');
        Storage::disk('local')->put('academic/sample-2', 'berkas uji');
        $backup = app(Backup::class);
        $run = $backup->run($backup->queue('manual', null));
        $this->assertSame('success', $run->status, (string) $run->error_summary);
        config(['database.connections.restore' => ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => config('database.connections.mysql.database'), 'username' => 'root', 'password' => $root, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']]);
        DB::purge('restore');
        try {
            $backup->restoreInto("$dir/{$run->artifact_ref}", 'restore');
            $this->fail('Restore into the live database must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('terisolasi', $e->getMessage());
        }
        config(['database.connections.restore.database' => 'portal_restore_test']);
        DB::purge('restore');
        $result = $backup->restoreInto("$dir/{$run->artifact_ref}", 'restore');
        $this->assertSame([], $result['mismatch']);
        $this->assertSame(3, DB::connection('restore')->table('enrollments')->count());
        $this->assertSame('Andi Pratama', DB::connection('restore')->table('students')->where('nbi', '006000')->value('name'));
        // A tampered archive fails verification.
        $copy = "$dir/rusak.zip";
        copy("$dir/{$run->artifact_ref}", $copy);
        $zip = new \ZipArchive;
        $zip->open($copy);
        $zip->addFromString('files/academic/sample-2', 'diubah');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        $backup->verify($copy);
    }

    public function test_backup_page_is_admin_only_unless_granted_and_manual_runs_do_not_pile_up(): void
    {
        $f = $this->fixtures();
        $this->backupDir();
        $this->actingAs($f['staff'])->get('/pengaturan/backup')->assertForbidden();
        $this->post('/pengaturan/backup', ['confirmed' => 1])->assertForbidden();
        DB::table('user_permissions')->insert(['assignment_id' => $f['grant'], 'permission_key' => 'backup.run', 'allowed' => true]);
        $this->get('/pengaturan/backup')->assertOk()->assertSee('Jalankan Backup')->assertDontSee($this->backupDir)->assertDontSee('Jadwal & retensi');
        $this->post('/pengaturan/backup', ['confirmed' => 1])->assertRedirect();
        $this->post('/pengaturan/backup', ['confirmed' => 1])->assertRedirect();
        $this->assertSame(1, DB::table('backup_runs')->where('status', 'queued')->count());
        $this->post('/pengaturan/backup/jadwal', ['time' => '03:00', 'retention' => 5, 'version' => 0, 'reason' => 'Ubah jadwal', 'confirmed' => 1])->assertForbidden();
        $this->actingAs($f['admin'])->post('/pengaturan/backup/jadwal', ['time' => '03:00', 'retention' => 5, 'version' => 0, 'reason' => 'Ubah jadwal', 'confirmed' => 1])->assertRedirect();
        $this->assertSame(5, app(Backup::class)->retention());
        $this->assertSame('03:00', app(Backup::class)->time());
        $this->artisan('portal:backup --queued')->assertSuccessful();
        $this->assertSame('success', DB::table('backup_runs')->value('status'));
        config(['backup.destination' => '/nonexistent-portal-backup']);
        $this->get('/pengaturan/backup')->assertOk()->assertSee('Folder tujuan backup belum tersedia');
    }

    public function test_daily_schedule_queues_once_after_configured_time_only_when_enabled(): void
    {
        $this->fixtures();
        $backup = app(Backup::class);
        config(['backup.enabled' => false, 'backup.time' => '00:00']);
        $this->assertFalse($backup->scheduleDue());
        config(['backup.enabled' => true]);
        $this->assertTrue($backup->scheduleDue());
        $backup->queue('scheduled', null);
        $this->assertFalse($backup->scheduleDue());
        config(['backup.time' => '23:59']);
        DB::table('backup_runs')->delete();
        $this->assertSame(now('Asia/Jakarta')->format('H:i') >= '23:59', $backup->scheduleDue());
    }

    public function test_change_triggered_backup_needs_threshold_and_minimum_interval(): void
    {
        $this->fixtures();
        $this->backupDir();
        $backup = app(Backup::class);
        DB::table('activity_logs')->delete();
        $change = fn (int $n, string $type = 'attendance') => collect(range(1, $n))->each(fn ($i) => Audit::record($type, $i, 'test.change', null, ['i' => $i]));
        config(['backup.enabled' => true, 'backup.change_threshold' => 10, 'backup.change_min_interval' => 60]);
        $change(9);
        $change(5, 'backup_run');
        $this->assertSame(9, $backup->pendingChanges());
        $this->assertFalse($backup->changesDue());
        $change(1);
        $this->assertTrue($backup->changesDue());
        config(['backup.change_threshold' => 0]);
        $this->assertFalse($backup->changesDue());
        config(['backup.change_threshold' => 10, 'backup.enabled' => false]);
        $this->assertFalse($backup->changesDue());
        config(['backup.enabled' => true, 'backup.time' => '23:59']);

        // The scheduler queues and runs it; changes before that run no longer count.
        $this->travel(1)->minutes();
        $this->artisan('portal:backup --scheduled --queued')->assertSuccessful();
        $run = DB::table('backup_runs')->orderByDesc('id')->first();
        $this->assertSame(['changes', 'success'], [$run->trigger, $run->status]);
        $this->travel(1)->minutes();
        $this->assertSame(0, $backup->pendingChanges());

        // Ten new changes soon after are held until the minimum interval has passed.
        $change(10);
        $this->assertFalse($backup->changesDue());
        $this->travel(61)->minutes();
        $this->assertTrue($backup->changesDue());
        $backup->queue('manual', null);
        $this->assertFalse($backup->changesDue());
    }
}
