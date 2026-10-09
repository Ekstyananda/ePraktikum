<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment;
use App\Services\MeetingRoster;
use App\Services\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesPortalPublic;
use Tests\TestCase;

class PublicWorkflowTest extends TestCase
{
    use MakesPortalPublic;
    use RefreshDatabase;

    private function fixtures(): array
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $staff = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $restricted = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'M5', 'label' => 'Semester — Data contoh', 'status' => 'active']);
        $p = DB::table('practicums')->insertGetId(['code' => 'M5', 'name' => 'SBD — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p, 'status' => 'active']);
        $a = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A', 'capacity' => 10, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Lab A']);
        $b = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi B', 'capacity' => 2, 'room' => 'Lab B']);
        DB::table('staff_assignments')->insert(['user_id' => $staff->id, 'offering_id' => $o, 'all_sessions' => true]);
        $grant = DB::table('staff_assignments')->insertGetId(['user_id' => $restricted->id, 'offering_id' => $o, 'all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $grant, 'offering_id' => $o, 'session_id' => $b]);
        $this->actingAs($admin);
        $e = [];
        foreach ([[$a, 'Andi Pratama'], [$a, 'Bunga Lestari'], [$a, 'Candra Wijaya'], [$b, 'Dewi Anggraini']] as $i => [$session, $name]) {
            $e[] = DB::transaction(fn () => app(Roster::class)->add(['nbi' => '00500'.$i, 'name' => $name.' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $session, 'valid_from' => '2026-01-01'], $o));
        }
        $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan — Data contoh']);
        $start = now()->addDays(3)->startOfHour();
        $smA = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $a, 'meeting_id' => $m, 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'room' => 'Lab A']);
        $smB = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $b, 'meeting_id' => $m, 'starts_at' => $start->copy()->addDay(), 'ends_at' => $start->copy()->addDay()->addHours(2), 'room' => 'Lab B']);
        DB::table('request_windows')->insert(['offering_id' => $o, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(7), 'instructions' => 'Instruksi — Data contoh', 'created_at' => now(), 'updated_at' => now()]);
        auth()->logout();
        $slug = $this->makePublic($o);

        return compact('admin', 'staff', 'restricted', 'o', 'a', 'b', 'e', 'm', 'smA', 'smB', 'grant', 'slug');
    }

    private function identity(array $f, int $i = 0, array $extra = []): array
    {
        $names = ['Andi Pratama', 'Bunga Lestari', 'Candra Wijaya', 'Dewi Anggraini'];

        return $extra + ['offering_id' => $f['o'], 'session_id' => $i === 3 ? $f['b'] : $f['a'], 'nbi' => '00500'.$i, 'name' => $names[$i].' — Data contoh', 'reason' => 'Alasan pengajuan — Data contoh', 'confirmed' => 1];
    }

    private function request(array $f, array $data): string
    {
        $response = $this->post('/'.$f['slug'].'/'.(($data['type'] ?? '') === 'remidi' ? 'remidi' : 'pengajuan'), $data)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        preg_match('/value="([a-f0-9]{64})"/', $response->getContent(), $match);
        $this->assertNotEmpty($match, 'Receipt must show the token once.');

        return $match[1];
    }

    private function rid(): int
    {
        return (int) DB::table('academic_requests')->max('id');
    }

    private function decide(array $f, int $id, string $decision = 'approved', array $extra = [])
    {
        $version = DB::table('academic_requests')->where('id', $id)->value('version');

        return $this->post("/praktikum/{$f['o']}/pengajuan/$id/keputusan", $extra + ['version' => $version, 'decision' => $decision, 'reason' => 'Keputusan — Data contoh', 'confirmed' => 1]);
    }

    private function pdf(string $name = 'tugas.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    public function test_public_pages_need_no_login_show_no_roster_and_hide_draft_offerings(): void
    {
        $f = $this->fixtures();
        $draftP = DB::table('practicums')->insertGetId(['code' => 'DRAFT', 'name' => 'Praktikum draf — Data contoh']);
        DB::table('practicum_offerings')->insert(['semester_id' => DB::table('semesters')->value('id'), 'practicum_id' => $draftP, 'status' => 'draft']);
        foreach (['/jadwal', '/pengumpulan', '/pengajuan', '/remidi'] as $path) {
            $this->get('/'.$f['slug'].$path)->assertOk()->assertDontSee('Andi Pratama')->assertDontSee('005000')->assertDontSee('Praktikum draf — Data contoh')->assertDontSee('id="sidebar"', false);
        }
        $this->get('/'.$f['slug'].'/jadwal')->assertSee('Sesi A')->assertSee('Senin')->assertSee('08:00–10:00');
        $this->get('/'.$f['slug'].'/pengajuan')->assertSee('Instruksi — Data contoh')->assertSee('Kirim pengajuan');
    }

    public function test_identity_mismatch_is_generic_and_creates_nothing(): void
    {
        $f = $this->fixtures();
        $cases = [['name' => 'Nama lain'], ['session_id' => $f['b']], ['nbi' => '999999']];
        $messages = [];
        foreach ($cases as $case) {
            $response = $this->from('/pengajuan')->post('/'.$f['slug'].'/pengajuan', array_merge($this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA']]), $case));
            $response->assertRedirect('/pengajuan')->assertSessionHasErrors('identity');
            $messages[] = session('errors')->first('identity');
        }
        $this->assertCount(1, array_unique($messages), 'All identity failures share one message.');
        $this->assertSame(0, DB::table('academic_requests')->count());
    }

    public function test_request_token_is_shown_once_stored_hashed_and_never_logged(): void
    {
        $f = $this->fixtures();
        $token = $this->request($f, $this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA']]));
        $row = DB::table('academic_requests')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertFalse(DB::table('activity_logs')->where('before_json', 'like', "%$token%")->orWhere('after_json', 'like', "%$token%")->orWhere('after_json', 'like', "%{$row->token_hash}%")->exists());
        $this->post('/cek-status', ['token' => $token])->assertOk()->assertSee('Menunggu pemeriksaan')->assertDontSee('Andi Pratama')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/cek-status', ['token' => str_repeat('a', 64)])->assertOk()->assertSee('Bukti tidak ditemukan');
        $this->post('/cek-status', ['token' => '005000'])->assertOk()->assertSee('Bukti tidak ditemukan');
        $this->get('/cek-status?token='.$token)->assertOk()->assertDontSee('Menunggu pemeriksaan');
    }

    public function test_closed_window_duplicates_and_cross_session_executions_are_rejected(): void
    {
        $f = $this->fixtures();
        $data = $this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA']]);
        $this->request($f, $data);
        $this->post('/'.$f['slug'].'/pengajuan', $data)->assertSessionHasErrors('type');
        $this->post('/'.$f['slug'].'/pengajuan', $this->identity($f, 1, ['type' => 'izin', 'source_execution_id' => $f['smB']]))->assertSessionHasErrors('source_execution_id');
        $this->post('/'.$f['slug'].'/pengajuan', $this->identity($f, 1, ['type' => 'temporary', 'source_execution_id' => $f['smA'], 'target_session_id' => $f['a'], 'target_execution_id' => $f['smA']]))->assertSessionHasErrors('target_session_id');
        $this->post('/'.$f['slug'].'/pengajuan', $this->identity($f, 1, ['type' => 'permanent', 'target_session_id' => $f['b']]))->assertSessionHasErrors('effective_date');
        DB::table('request_windows')->update(['closes_at' => now()->subMinute()]);
        $this->post('/'.$f['slug'].'/pengajuan', $this->identity($f, 2, ['type' => 'izin', 'source_execution_id' => $f['smA']]))->assertSessionHasErrors('period');
        $this->assertSame(1, DB::table('academic_requests')->count());
    }

    public function test_izin_and_susulan_approval_never_marks_attendance_and_results_are_versioned(): void
    {
        $f = $this->fixtures();
        $this->request($f, $this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA']]));
        $izin = $this->rid();
        $this->request($f, $this->identity($f, 1, ['type' => 'susulan', 'source_execution_id' => $f['smA']]));
        $susulan = $this->rid();
        $this->actingAs($f['staff']);
        $this->decide($f, $izin)->assertRedirect();
        $this->decide($f, $susulan)->assertSessionHasErrors('scheduled_at');
        $this->decide($f, $susulan, 'approved', ['scheduled_at' => now('Asia/Jakarta')->addDays(5)->format('Y-m-d\TH:i'), 'room' => 'Lab C'])->assertRedirect();
        $this->assertSame(['approved', 'approved'], DB::table('academic_requests')->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, DB::table('attendances')->count(), 'Approval must not create attendance.');
        // Snapshot of the source meeting still starts every participant as Belum dicatat.
        app(MeetingRoster::class)->snapshot($f['staff'], $f['o'], $f['smA'], 1);
        $this->assertSame(['unrecorded'], DB::table('attendances')->distinct()->pluck('status')->all());
        $result = fn ($status, $at) => $this->post("/praktikum/{$f['o']}/pengajuan/$susulan/hasil", ['version' => DB::table('academic_requests')->where('id', $susulan)->value('version'), 'performed_at' => $at, 'attendance' => $status, 'reason' => 'Hasil susulan — Data contoh', 'confirmed' => 1]);
        $result('present', now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i'))->assertSessionHasErrors('performed_at');
        $result('present', now('Asia/Jakarta')->subHour()->format('Y-m-d\TH:i'))->assertRedirect();
        $result('sick', now('Asia/Jakarta')->subHour()->format('Y-m-d\TH:i'))->assertRedirect();
        $this->assertSame([2, 1], DB::table('request_results')->where('request_id', $susulan)->orderByDesc('version')->pluck('version')->all());
        $this->assertSame(['unrecorded'], DB::table('attendances')->distinct()->pluck('status')->all(), 'Original attendance stays untouched.');
        $this->assertSame('completed', DB::table('academic_requests')->where('id', $susulan)->value('status'));
    }

    public function test_temporary_move_respects_capacity_one_meeting_and_no_duplicate_presence(): void
    {
        $f = $this->fixtures();
        DB::table('practicum_sessions')->where('id', $f['b'])->update(['capacity' => 2]);
        $tokens = [];
        foreach ([0, 1] as $i) {
            $tokens[] = $this->request($f, $this->identity($f, $i, ['type' => 'temporary', 'source_execution_id' => $f['smA'], 'target_session_id' => $f['b'], 'target_execution_id' => $f['smB']]));
        }
        [$first, $second] = DB::table('academic_requests')->orderBy('id')->pluck('id')->all();
        $this->actingAs($f['staff']);
        $this->decide($f, $first)->assertRedirect()->assertSessionHasNoErrors();
        $this->decide($f, $second)->assertSessionHasErrors('capacity');
        $this->assertSame('pending', DB::table('academic_requests')->where('id', $second)->value('status'));
        // Membership is unchanged; the move applies to that meeting only.
        $this->assertSame($f['a'], DB::table('session_memberships')->where('enrollment_id', $f['e'][0])->whereNull('valid_until')->value('session_id'));
        app(MeetingRoster::class)->snapshot($f['staff'], $f['o'], $f['smA'], 1);
        app(MeetingRoster::class)->snapshot($f['staff'], $f['o'], $f['smB'], 1);
        $this->assertFalse(DB::table('meeting_participants')->where('session_meeting_id', $f['smA'])->where('enrollment_id', $f['e'][0])->exists());
        $this->assertSame($first, DB::table('meeting_participants')->where('session_meeting_id', $f['smB'])->where('enrollment_id', $f['e'][0])->value('approved_request_id'));
        $this->assertSame(1, DB::table('meeting_participants')->where('meeting_id', $f['m'])->where('enrollment_id', $f['e'][0])->count());
        // After the snapshot, a pending move can no longer be approved.
        DB::table('practicum_sessions')->where('id', $f['b'])->update(['capacity' => 10]);
        $this->decide($f, $second)->assertSessionHasErrors('decision');
        $this->post('/cek-status', ['token' => $tokens[0]])->assertSee('Disetujui');
    }

    public function test_permanent_move_closes_old_membership_on_effective_date_and_keeps_history(): void
    {
        $f = $this->fixtures();
        $date = now('Asia/Jakarta')->addDays(10)->toDateString();
        $this->request($f, $this->identity($f, 2, ['type' => 'permanent', 'target_session_id' => $f['b'], 'effective_date' => $date]));
        $this->actingAs($f['staff']);
        $this->decide($f, $this->rid())->assertRedirect()->assertSessionHasNoErrors();
        $history = DB::table('session_memberships')->where('enrollment_id', $f['e'][2])->orderBy('valid_from')->get();
        $this->assertCount(2, $history);
        $this->assertSame([$f['a'], $f['b']], $history->pluck('session_id')->all());
        $this->assertSame($date, (string) $history[0]->valid_until);
        $this->assertSame($date, (string) $history[1]->valid_from);
        $this->assertNull($history[1]->valid_until);
        // Session B (capacity 2) now holds Dewi plus Candra from the effective date: a third permanent move is refused.
        auth()->logout();
        $this->request($f, $this->identity($f, 1, ['type' => 'permanent', 'target_session_id' => $f['b'], 'effective_date' => $date]));
        $this->actingAs($f['staff']);
        $this->decide($f, $this->rid())->assertSessionHasErrors('capacity');
        $this->assertCount(1, DB::table('session_memberships')->where('enrollment_id', $f['e'][1])->get());
    }

    public function test_scope_permission_and_stale_decisions_are_enforced_on_the_server(): void
    {
        $f = $this->fixtures();
        $this->request($f, $this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA']]));
        $id = $this->rid();
        $this->get("/praktikum/{$f['o']}/pengajuan/$id")->assertRedirect('/login');
        $this->actingAs($f['staff'])->get('/dashboard')->assertSee('1 pengajuan menunggu keputusan');
        $this->actingAs($f['restricted'])->get('/dashboard')->assertDontSee('pengajuan menunggu keputusan');
        $this->get("/praktikum/{$f['o']}/pengajuan")->assertOk()->assertDontSee('Andi Pratama');
        $this->get("/praktikum/{$f['o']}/pengajuan/$id")->assertForbidden();
        $this->decide($f, $id)->assertForbidden();
        $this->get("/praktikum/{$f['o']}/pengajuan/pengaturan")->assertForbidden();
        DB::table('user_permissions')->insert(['assignment_id' => DB::table('staff_assignments')->where('user_id', $f['staff']->id)->value('id'), 'permission_key' => 'requests.manage', 'allowed' => false]);
        $this->actingAs($f['staff']);
        $this->get("/praktikum/{$f['o']}/pengajuan")->assertForbidden();
        $this->actingAs($f['admin']);
        $this->get("/praktikum/{$f['o']}/pengajuan/$id")->assertOk()->assertSee('Andi Pratama');
        $this->post("/praktikum/{$f['o']}/pengajuan/$id/keputusan", ['version' => 99, 'decision' => 'rejected', 'reason' => 'Basi — Data contoh', 'confirmed' => 1])->assertStatus(409);
        $this->decide($f, $id, 'rejected')->assertRedirect();
        $this->decide($f, $id, 'approved')->assertStatus(409);
        $log = DB::table('activity_logs')->where('action', 'request.decided')->first();
        $this->assertSame($f['admin']->id, $log->actor_id);
        $this->assertStringNotContainsString('token_hash', $log->before_json);
    }

    public function test_remidi_keeps_original_grade_and_blocks_finalization_until_completed(): void
    {
        $f = $this->fixtures();
        $assignment = DB::table('assignments')->insertGetId(['offering_id' => $f['o'], 'origin_meeting_id' => $f['m'], 'type' => 'lab', 'mode' => 'direct', 'title' => 'Praktik Lab — Data contoh']);
        $component = DB::table('grading_components')->insertGetId(['offering_id' => $f['o'], 'assignment_id' => $assignment, 'label' => 'Lab — Data contoh', 'weight' => 100, 'max_score' => 100]);
        DB::table('grades')->insert(['offering_id' => $f['o'], 'component_id' => $component, 'enrollment_id' => $f['e'][0], 'score' => 40, 'status' => 'graded', 'evaluator_id' => $f['admin']->id]);
        $this->actingAs($f['admin']);
        $program = ['version' => 0, 'component_id' => $component, 'title' => 'Remidi Lab — Data contoh', 'instructions' => 'Instruksi remidi', 'eligibility_rule' => 'Nilai di bawah batas — Data contoh', 'opens_at' => now('Asia/Jakarta')->subDay()->format('Y-m-d\TH:i'), 'closes_at' => now('Asia/Jakarta')->addDays(3)->format('Y-m-d\TH:i'), 'scheduled_at' => now('Asia/Jakarta')->addDays(4)->format('Y-m-d\TH:i'), 'room' => 'Lab A', 'requires_file' => 0, 'active' => 1, 'reason' => 'Program QA — Data contoh', 'confirmed' => 1];
        $this->post("/praktikum/{$f['o']}/pengajuan/remidi", $program)->assertRedirect()->assertSessionHasNoErrors();
        $pid = DB::table('remedial_programs')->value('id');
        auth()->logout();
        $this->get('/'.$f['slug'].'/remidi')->assertSee('Remidi Lab — Data contoh')->assertDontSee('40.0000')->assertDontSee('Andi Pratama');
        $this->request($f, $this->identity($f, 0, ['type' => 'remidi', 'program_id' => $pid]));
        $rid = $this->rid();
        $this->actingAs($f['admin']);
        $this->post("/praktikum/{$f['o']}/pengajuan/remidi", ['id' => $pid, 'version' => 1, 'title' => 'Diubah'] + $program)->assertSessionHasErrors('title');
        $this->decide($f, $rid)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('40.0000', json_decode(DB::table('academic_requests')->where('id', $rid)->value('original_grade'), true)['score']);
        $preview = app(Assessment::class)->finalPreview($f['admin'], $f['o'], $f['e'][0]);
        $this->assertContains('1 pengajuan susulan/remidi belum selesai diproses.', $preview['errors']);
        $this->post("/praktikum/{$f['o']}/pengajuan/$rid/hasil", ['version' => 2, 'performed_at' => now('Asia/Jakarta')->subMinute()->format('Y-m-d\TH:i'), 'score' => 120, 'reason' => 'Hasil remidi — Data contoh', 'confirmed' => 1])->assertSessionHasErrors('score');
        $this->post("/praktikum/{$f['o']}/pengajuan/$rid/hasil", ['version' => 2, 'performed_at' => now('Asia/Jakarta')->subMinute()->format('Y-m-d\TH:i'), 'score' => 75, 'reason' => 'Hasil remidi — Data contoh', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('40.0000', DB::table('grades')->where('component_id', $component)->value('score'), 'Original grade is never overwritten.');
        $this->assertSame('75.0000', DB::table('request_results')->where('request_id', $rid)->value('score'));
        $this->assertNotContains('1 pengajuan susulan/remidi belum selesai diproses.', app(Assessment::class)->finalPreview($f['admin'], $f['o'], $f['e'][0])['errors']);
    }

    public function test_remidi_without_original_grade_cannot_be_approved(): void
    {
        $f = $this->fixtures();
        $assignment = DB::table('assignments')->insertGetId(['offering_id' => $f['o'], 'origin_meeting_id' => $f['m'], 'type' => 'lab', 'mode' => 'direct', 'title' => 'Lab']);
        $component = DB::table('grading_components')->insertGetId(['offering_id' => $f['o'], 'assignment_id' => $assignment, 'label' => 'Lab', 'max_score' => 100]);
        $pid = DB::table('remedial_programs')->insertGetId(['offering_id' => $f['o'], 'component_id' => $component, 'title' => 'Remidi', 'instructions' => 'x', 'eligibility_rule' => 'x', 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'scheduled_at' => now()->addDays(2), 'room' => 'Lab', 'requires_file' => true]);
        $this->post('/'.$f['slug'].'/remidi', $this->identity($f, 0, ['type' => 'remidi', 'program_id' => $pid]))->assertSessionHasErrors('file');
        $this->request($f, $this->identity($f, 0, ['type' => 'remidi', 'program_id' => $pid, 'file' => $this->pdf('bukti.pdf')]));
        $this->actingAs($f['staff']);
        $this->decide($f, $this->rid())->assertSessionHasErrors('decision');
        $this->get("/praktikum/{$f['o']}/pengajuan/{$this->rid()}/bukti")->assertOk();
        auth()->logout();
        $this->get("/praktikum/{$f['o']}/pengajuan/{$this->rid()}/bukti")->assertRedirect('/login');
    }

    private function digital(array $f, bool $allowLate = false): int
    {
        $assignment = DB::table('assignments')->insertGetId(['offering_id' => $f['o'], 'origin_meeting_id' => $f['m'], 'type' => 'custom', 'mode' => 'digital', 'title' => 'Query digital — Data contoh']);

        return DB::table('assignment_schedules')->insertGetId(['offering_id' => $f['o'], 'assignment_id' => $assignment, 'session_id' => $f['a'], 'opens_at' => now()->subDay(), 'due_at' => now()->addDay(), 'closes_at' => now()->addDays(2), 'allow_late' => $allowLate]);
    }

    public function test_digital_delivery_receipt_review_and_token_revision_keep_versions(): void
    {
        $f = $this->fixtures();
        $schedule = $this->digital($f);
        $response = $this->post('/'.$f['slug'].'/pengumpulan', $this->identity($f, 0, ['schedule_id' => $schedule, 'file' => $this->pdf('v1.pdf')]))->assertOk();
        preg_match('/value="([a-f0-9]{64})"/', $response->getContent(), $m);
        $token = $m[1];
        $delivery = DB::table('public_deliveries')->first();
        $this->assertSame('pending', $delivery->status);
        $path = DB::table('files')->where('id', $delivery->file_id)->value('storage_path');
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsNotWith('public', $path);
        $this->get("/praktikum/{$f['o']}/kiriman-digital/{$delivery->id}/unduh")->assertRedirect('/login');
        // Revision before acceptance is refused.
        $this->post('/cek-status/revisi', ['token' => $token, 'file' => $this->pdf('v2.pdf'), 'confirmed' => 1])->assertSessionHasErrors('token');
        $this->actingAs($f['staff']);
        $this->get("/praktikum/{$f['o']}/kiriman-digital")->assertOk()->assertSee('v1.pdf');
        $this->get("/praktikum/{$f['o']}/kiriman-digital/{$delivery->id}/unduh")->assertOk();
        $this->post("/praktikum/{$f['o']}/kiriman-digital/{$delivery->id}", ['version' => 1, 'decision' => 'accepted', 'reason' => 'Diterima — Data contoh', 'confirmed' => 1])->assertRedirect();
        $sub = DB::table('submissions')->first();
        $this->assertSame('submitted', $sub->status);
        DB::table('submissions')->update(['status' => 'revision_requested']);
        auth()->logout();
        $this->post('/cek-status', ['token' => $token])->assertSee('Perlu revisi')->assertSee('Kirim revisi');
        $this->post('/cek-status/revisi', ['token' => str_repeat('b', 64), 'file' => $this->pdf('x.pdf'), 'confirmed' => 1])->assertSessionHasErrors('token');
        $this->post('/cek-status/revisi', ['token' => $token, 'file' => $this->pdf('v2.pdf'), 'confirmed' => 1])->assertOk()->assertSee('Revisi dikirim');
        $versions = DB::table('submission_versions')->where('submission_id', $sub->id)->orderBy('version_number')->get();
        $this->assertSame([1, 2], $versions->pluck('version_number')->all());
        $this->assertNotSame($versions[0]->file_id, $versions[1]->file_id);
        Storage::disk('local')->assertExists(DB::table('files')->where('id', $versions[0]->file_id)->value('storage_path'));
        $this->assertSame('revised', DB::table('submissions')->value('status'));
        // A second revision needs a new staff request, and closed periods refuse revisions.
        $this->post('/cek-status/revisi', ['token' => $token, 'file' => $this->pdf('v3.pdf'), 'confirmed' => 1])->assertSessionHasErrors('token');
        DB::table('submissions')->update(['status' => 'revision_requested']);
        DB::table('assignment_schedules')->update(['due_at' => now()->subMinute(), 'closes_at' => now()->subMinute()]);
        $this->post('/cek-status/revisi', ['token' => $token, 'file' => $this->pdf('v3.pdf'), 'confirmed' => 1])->assertSessionHasErrors('period');
        $this->assertSame(2, DB::table('submission_versions')->count());
    }

    public function test_uploads_reject_executables_wrong_session_and_closed_tasks(): void
    {
        $f = $this->fixtures();
        $schedule = $this->digital($f);
        $base = $this->identity($f, 0, ['schedule_id' => $schedule]);
        $this->post('/'.$f['slug'].'/pengumpulan', $base + ['file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')])->assertSessionHasErrors('file');
        $this->post('/'.$f['slug'].'/pengumpulan', $base + ['file' => UploadedFile::fake()->create('besar.pdf', config('academic.upload_max_kb') + 1, 'application/pdf')])->assertSessionHasErrors('file');
        $this->post('/'.$f['slug'].'/pengumpulan', $this->identity($f, 3, ['schedule_id' => $schedule, 'file' => $this->pdf()]))->assertSessionHasErrors('schedule_id');
        DB::table('assignment_schedules')->update(['due_at' => now()->subMinute()]);
        $this->post('/'.$f['slug'].'/pengumpulan', $base + ['file' => $this->pdf()])->assertSessionHasErrors('period');
        $this->assertSame(0, DB::table('public_deliveries')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rate_limits_are_strict_per_identity_and_generous_per_shared_ip(): void
    {
        $f = $this->fixtures();
        $this->assertSame(['submit_per_ip' => 300, 'submit_per_identity' => 6, 'status_per_ip' => 120, 'download_per_ip' => 300], config('portal.rate_limits'));
        config(['portal.rate_limits.status_per_ip' => 30]);
        for ($i = 0; $i < 30; $i++) {
            $this->post('/cek-status', ['token' => str_repeat('c', 64)])->assertOk();
        }
        $this->post('/cek-status', ['token' => str_repeat('c', 64)])->assertStatus(429);
        // Another visitor behind a different IP keeps its own allowance.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/cek-status', ['token' => str_repeat('c', 64)])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        // Six attempts per NBI per minute; another student behind the same IP is not blocked.
        for ($i = 0; $i < 6; $i++) {
            $this->post('/'.$f['slug'].'/pengajuan', $this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA'], 'name' => 'Salah']))->assertSessionHasErrors('identity');
        }
        $this->post('/'.$f['slug'].'/pengajuan', $this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA']]))->assertStatus(429);
        $this->request($f, $this->identity($f, 1, ['type' => 'izin', 'source_execution_id' => $f['smA']]));
    }

    public function test_locked_semester_hides_public_services_and_blocks_staff_decisions(): void
    {
        $f = $this->fixtures();
        $this->request($f, $this->identity($f, 0, ['type' => 'izin', 'source_execution_id' => $f['smA']]));
        DB::table('semesters')->update(['status' => 'locked']);
        $this->get('/')->assertOk()->assertSee('Belum ada praktikum yang dibuka');
        $this->get('/'.$f['slug'].'/pengajuan')->assertNotFound()->assertSee('sedang tidak dibuka')->assertDontSee('Kirim pengajuan');
        $this->post('/'.$f['slug'].'/pengajuan', $this->identity($f, 1, ['type' => 'izin', 'source_execution_id' => $f['smA']]))->assertNotFound();
        $this->post('/pengajuan', $this->identity($f, 1, ['type' => 'izin', 'source_execution_id' => $f['smA']]))->assertSessionHasErrors('offering_id');
        $this->actingAs($f['staff']);
        $this->decide($f, $this->rid())->assertStatus(423);
        $this->assertSame('pending', DB::table('academic_requests')->value('status'));
        $this->assertSame(1, DB::table('academic_requests')->count());
    }
}
