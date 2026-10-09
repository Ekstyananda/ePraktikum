<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesPortalPublic;
use Tests\TestCase;

/** Cek Status details, device list batch, student notes and token reissue. */
class StatusDetailTest extends TestCase
{
    use MakesPortalPublic;
    use RefreshDatabase;

    private const NOT_FOUND = ['found' => false, 'message' => 'Bukti tidak ditemukan. Periksa token atau hubungi pengelola.'];

    /** SBD with one student (request), PCD with one student (digital delivery). */
    private function fixtures(): array
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $other = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'G26', 'label' => 'Gasal 2026', 'status' => 'active', 'starts_at' => '2026-09-01']);
        $f = ['admin' => $admin, 'other' => $other];
        foreach (['sbd' => ['SBD', 'Sistem Basis Data', '1462300125', 'Naufal Rahman'], 'pcd' => ['PCD', 'Pengolahan Citra Digital', '1462300777', 'Sinta Dewi']] as $key => [$code, $name, $nbi, $student]) {
            $p = DB::table('practicums')->insertGetId(['code' => $code, 'name' => $name]);
            $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p, 'status' => 'active']);
            $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi '.$code, 'capacity' => 10, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Lab '.$code]);
            $s2 = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi Lain '.$code, 'capacity' => 10]);
            $this->actingAs($admin);
            $e = DB::transaction(fn () => app(Roster::class)->add(['nbi' => $nbi, 'name' => $student, 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-01-01'], $o));
            $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan '.$code]);
            $sm = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $s, 'meeting_id' => $m, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2), 'room' => 'Ruang '.$code]);
            DB::table('request_windows')->insert(['offering_id' => $o, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(7), 'instructions' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            $a = DB::table('assignments')->insertGetId(['offering_id' => $o, 'origin_meeting_id' => $m, 'type' => 'aktivitas', 'mode' => 'digital', 'title' => 'Tugas '.$code, 'active' => 1, 'mandatory' => 1]);
            $sc = DB::table('assignment_schedules')->insertGetId(['offering_id' => $o, 'assignment_id' => $a, 'session_id' => $s, 'opens_at' => now()->subDay(), 'due_at' => now()->addDays(3), 'closes_at' => now()->addDays(5), 'allow_late' => true]);
            // Restricted aslab: other session only.
            $g = DB::table('staff_assignments')->insertGetId(['user_id' => $other->id, 'offering_id' => $o, 'all_sessions' => false]);
            DB::table('staff_session_scopes')->insert(['assignment_id' => $g, 'offering_id' => $o, 'session_id' => $s2]);
            auth()->logout();
            $f[$key] = compact('p', 'o', 's', 'e', 'sm', 'a', 'sc', 'nbi', 'student') + ['slug' => $this->makePublic($o)];
        }

        return $f;
    }

    private function token($response): string
    {
        preg_match('/id="receipt-token"[^>]*value="([a-f0-9]{64})"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'receipt shows the token');

        return $m[1];
    }

    private function izin(array $f): string
    {
        $x = $f['sbd'];

        return $this->token($this->post("/{$x['slug']}/pengajuan", ['offering_id' => $x['o'], 'session_id' => $x['s'], 'nbi' => $x['nbi'], 'name' => $x['student'], 'type' => 'izin', 'source_execution_id' => $x['sm'], 'reason' => 'Sakit demam', 'confirmed' => 1])->assertOk());
    }

    private function delivery(array $f): string
    {
        $x = $f['pcd'];
        $file = UploadedFile::fake()->createWithContent($x['nbi'].'_Naufal_Laporan.pdf', "%PDF-1.7\n%%EOF\n");

        return $this->token($this->post("/{$x['slug']}/pengumpulan", ['offering_id' => $x['o'], 'session_id' => $x['s'], 'nbi' => $x['nbi'], 'name' => $x['student'], 'schedule_id' => $x['sc'], 'file' => $file, 'confirmed' => 1])->assertOk());
    }

    private function detail(string $token)
    {
        return $this->postJson('/cek-status/rincian', ['token' => $token])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_each_token_shows_only_its_own_entry_without_identity_score_or_file_name(): void
    {
        $f = $this->fixtures();
        $req = $this->izin($f);
        $del = $this->delivery($f);
        $a = $this->detail($req)->assertJson(['found' => true, 'kind' => 'Pengajuan Izin', 'practicum' => 'Sistem Basis Data', 'state' => 'pending'])->json();
        $b = $this->detail($del)->assertJson(['found' => true, 'kind' => 'Pengumpulan tugas digital', 'practicum' => 'Pengolahan Citra Digital'])->json();
        $this->assertContains(['Berkas', 'Berkas kiriman PDF'], $b['facts']);
        $this->assertContains(['Tugas', 'Tugas PCD'], $b['facts']);
        foreach ([[$a, 'pcd'], [$b, 'sbd']] as [$json, $otherKey]) {
            $text = json_encode($json, JSON_UNESCAPED_UNICODE);
            foreach (['1462300125', '1462300777', 'Naufal', 'Sinta', '_Laporan', 'score', 'Sakit demam'] as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
            $this->assertStringNotContainsString($f[$otherKey]['slug'] === 'sbd' ? 'Sistem Basis Data' : 'Pengolahan Citra', $text);
        }
        // Non-JS page renders the same details.
        $this->post('/cek-status', ['token' => $del])->assertOk()->assertSee('Berkas kiriman PDF')->assertDontSee('_Laporan')->assertDontSee('1462300777');
    }

    public function test_malformed_unknown_and_revoked_tokens_get_the_same_generic_answer_and_batch_is_limited(): void
    {
        $f = $this->fixtures();
        $req = $this->izin($f);
        $this->actingAs($f['admin'])->post("/praktikum/{$f['sbd']['o']}/pengajuan/".DB::table('academic_requests')->value('id').'/token-baru', ['reason' => 'HP hilang, dicocokkan di lab', 'verified' => 1, 'method' => 'in_person'])->assertOk();
        auth()->logout();
        foreach (['bukan-token', str_repeat('a', 64), $req, strtoupper(str_repeat('f', 64))] as $bad) {
            $this->assertSame(self::NOT_FOUND, $this->detail($bad)->json());
        }
        $del = $this->delivery($f);
        $res = $this->postJson('/cek-status/semua', ['tokens' => [$del, 'x', $req, $del]])->assertOk()->json('results');
        $this->assertTrue($res[0]['found']);
        $this->assertSame(self::NOT_FOUND, $res[1]);
        $this->assertSame(self::NOT_FOUND, $res[2]);
        $this->assertSame($res[0], $res[3]);
        $this->assertSame(['found', 'kind', 'practicum', 'label', 'state'], array_keys($res[0]));
        $this->postJson('/cek-status/semua', ['tokens' => array_fill(0, 31, $del)])->assertStatus(422);
        $this->get('/cek-status/rincian')->assertStatus(405);
        $this->get('/cek-status/semua')->assertStatus(405);
    }

    public function test_only_the_note_for_students_is_shown_never_internal_reasons_or_notes(): void
    {
        $f = $this->fixtures();
        $req = $this->izin($f);
        $id = DB::table('academic_requests')->value('id');
        // Rejecting needs an internal reason; approving does not.
        $this->actingAs($f['admin'])->post("/praktikum/{$f['sbd']['o']}/pengajuan/$id/keputusan", ['version' => 1, 'decision' => 'rejected', 'confirmed' => 1])->assertSessionHasErrors('reason');
        $this->post("/praktikum/{$f['sbd']['o']}/pengajuan/$id/keputusan", ['version' => 1, 'decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('approved', DB::table('academic_requests')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('academic_requests')->where('id', $id)->value('decision_note'));
        DB::table('academic_requests')->where('id', $id)->update(['decision_note' => 'INTERNAL alasan audit']);
        auth()->logout();
        $json = json_encode($this->detail($req)->json());
        $this->assertStringNotContainsString('INTERNAL', $json);
        $this->assertSame([], $this->detail($req)->json('notes'));
        DB::table('academic_requests')->where('id', $id)->update(['student_note' => 'Bawa surat dokter ke Lab B']);
        $this->assertSame(['Bawa surat dokter ke Lab B'], $this->detail($req)->json('notes'));

        $del = $this->delivery($f);
        $d = DB::table('public_deliveries')->first();
        $this->actingAs($f['admin'])->post("/praktikum/{$f['pcd']['o']}/kiriman-digital/{$d->id}", ['version' => $d->version, 'decision' => 'accepted', 'reason' => 'INTERNAL cek format', 'student_note' => 'Format sudah sesuai'])->assertSessionHasNoErrors();
        DB::table('submissions')->update(['note' => 'INTERNAL catatan penerimaan lama']);
        auth()->logout();
        $x = $this->detail($del)->json();
        $this->assertSame(['Format sudah sesuai'], $x['notes']);
        $this->assertStringNotContainsString('INTERNAL', json_encode($x));
        $this->post('/cek-status', ['token' => $del])->assertSee('Format sudah sesuai')->assertDontSee('INTERNAL');
    }

    public function test_steps_only_show_times_that_were_recorded(): void
    {
        $f = $this->fixtures();
        $req = $this->izin($f);
        $steps = $this->detail($req)->json('steps');
        $this->assertTrue($steps[0]['done']);
        $this->assertNotNull($steps[0]['at']);
        $this->assertSame(['label' => 'Keputusan aslab', 'at' => null, 'done' => false], $steps[1]);
        // Delivery reviewed before decided_at existed: the step is done but has no invented date.
        $del = $this->delivery($f);
        DB::table('public_deliveries')->update(['status' => 'rejected', 'decided_at' => null]);
        $steps = $this->detail($del)->json('steps');
        $this->assertSame(['label' => 'Ditolak aslab', 'at' => null, 'done' => true], $steps[1]);
        $this->post('/cek-status', ['token' => $del])->assertSee('Waktu tidak tercatat');
        // New decisions record the time.
        DB::table('public_deliveries')->update(['status' => 'pending']);
        $d = DB::table('public_deliveries')->first();
        $this->actingAs($f['admin'])->post("/praktikum/{$f['pcd']['o']}/kiriman-digital/{$d->id}", ['version' => $d->version, 'decision' => 'rejected', 'reason' => 'Berkas kosong'])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('public_deliveries')->value('decided_at'));
    }

    public function test_tokens_are_never_flashed_audited_or_logged(): void
    {
        $f = $this->fixtures();
        $req = $this->izin($f);
        $del = $this->delivery($f);
        // Validation errors on the classic form and on the device batch: nothing kept in the session.
        $this->from('/cek-status')->post('/cek-status', ['token' => $req.str_repeat('0', 200)])->assertSessionHasErrors('token');
        $this->assertNull(session()->getOldInput('token'));
        $this->from('/cek-status')->post('/cek-status/semua', ['tokens' => array_fill(0, 31, $del)])->assertSessionHasErrors('tokens');
        $this->assertNull(session()->getOldInput('tokens'));
        $this->assertStringNotContainsString($del, json_encode(session()->all()));
        // Reissue and decisions: audit never contains a token.
        $this->actingAs($f['admin'])->post("/praktikum/{$f['sbd']['o']}/pengajuan/".DB::table('academic_requests')->value('id').'/token-baru', ['reason' => 'HP hilang, dicocokkan di lab', 'verified' => 1, 'method' => 'in_person']);
        $new = $this->token($this->post("/praktikum/{$f['pcd']['o']}/kiriman-digital/".DB::table('public_deliveries')->value('id').'/token-baru', ['reason' => 'Ganti HP, dicocokkan di lab', 'verified' => 1, 'method' => 'private_channel'])->assertOk()->assertHeader('Cache-Control', 'no-store, private'));
        $this->assertStringNotContainsString($new, json_encode(session()->all()));
        $logs = DB::table('activity_logs')->get()->toJson();
        foreach ([$req, $del, $new] as $t) {
            $this->assertStringNotContainsString($t, $logs);
            foreach (File::glob(storage_path('logs/*.log')) as $file) {
                $this->assertStringNotContainsString($t, File::get($file));
            }
        }
    }

    public function test_reissue_requires_verification_reason_and_scope_and_replaces_the_token(): void
    {
        $f = $this->fixtures();
        $del = $this->delivery($f);
        $d = DB::table('public_deliveries')->first();
        $url = "/praktikum/{$f['pcd']['o']}/kiriman-digital/{$d->id}/token-baru";
        $ok = ['reason' => 'Ganti HP, dicocokkan di lab', 'verified' => 1, 'method' => 'in_person'];
        $this->actingAs($f['other'])->get($url)->assertForbidden();
        $this->post($url, $ok)->assertForbidden();
        $this->actingAs($f['admin'])->get($url)->assertOk()->assertSee('Cocokkan dulu identitasnya')->assertSee('1462300777');
        $this->post($url, array_diff_key($ok, ['verified' => 1]))->assertSessionHasErrors('verified');
        $this->post($url, ['reason' => 'pendek'] + $ok)->assertSessionHasErrors('reason');
        $this->post($url, array_diff_key($ok, ['method' => 1]))->assertSessionHasErrors('method');
        $this->assertSame($d->token_hash, DB::table('public_deliveries')->value('token_hash'));
        // Accept, request a revision, then reissue: the old token dies, the new one works for status and revision.
        $this->post("/praktikum/{$f['pcd']['o']}/kiriman-digital/{$d->id}", ['version' => $d->version, 'decision' => 'accepted'])->assertSessionHasNoErrors()->assertRedirect();
        DB::table('submissions')->update(['status' => 'revision_requested', 'student_note' => 'Lengkapi bab 3']);
        $new = $this->token($this->post($url, $ok)->assertOk());
        $this->assertDatabaseHas('activity_logs', ['action' => 'token.reissued', 'reason' => 'Ganti HP, dicocokkan di lab']);
        auth()->logout();
        $this->assertSame(self::NOT_FOUND, $this->detail($del)->json());
        $this->detail($new)->assertJson(['found' => true, 'state' => 'revision_requested', 'revision' => true, 'notes' => ['Lengkapi bab 3']]);
        $this->post('/cek-status/revisi', ['token' => $del, 'file' => UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.7\n%%EOF\n"), 'confirmed' => 1])->assertSessionHasErrors('token');
        $this->post('/cek-status/revisi', ['token' => $new, 'file' => UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.7\n%%EOF\n"), 'confirmed' => 1])->assertOk()->assertSee('Revisi dikirim');
        $this->assertSame('revised', DB::table('submissions')->value('status'));
        // Requests: restricted aslab cannot reissue; admin can.
        $this->izin($f);
        $rid = DB::table('academic_requests')->value('id');
        $this->actingAs($f['other'])->post("/praktikum/{$f['sbd']['o']}/pengajuan/$rid/token-baru", $ok)->assertForbidden();
        $this->actingAs($f['admin'])->get("/praktikum/{$f['sbd']['o']}/pengajuan/$rid")->assertSee('Praktikan kehilangan token?');
    }
}
