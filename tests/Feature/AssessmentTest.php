<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment;
use App\Services\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-04 06:00:00', 'UTC'));
        CarbonImmutable::setTestNow('2026-10-04 06:00:00');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $staff = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $outsider = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'M4', 'label' => 'Data contoh']);
        $p = DB::table('practicums')->insertGetId(['code' => 'M4', 'name' => 'M4 — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p]);
        $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A']);
        $other = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi B']);
        $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan 1']);
        $m2 = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 2, 'title' => 'Pertemuan 2']);
        $m5 = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 5, 'title' => 'Pertemuan 5']);
        $sm = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $s, 'meeting_id' => $m2, 'starts_at' => '2026-10-02 01:00:00', 'ends_at' => '2026-10-02 03:00:00', 'room' => 'Lab']);
        $grant = DB::table('staff_assignments')->insertGetId(['user_id' => $staff->id, 'offering_id' => $o, 'all_sessions' => true]);
        $this->actingAs($admin);
        $enroll = [];
        foreach ([1, 2] as $n) {
            $enroll[] = DB::transaction(fn () => app(Roster::class)->add(['nbi' => '00004'.$n, 'name' => 'Praktikan '.$n.' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-10-01'], $o));
        }

        return compact('admin', 'staff', 'outsider', 'sem', 'o', 's', 'other', 'm', 'm2', 'm5', 'sm', 'grant', 'enroll');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function assignment(array $f, string $mode = 'print', string $type = 'aktivitas', ?int $origin = null): int
    {
        $this->post('/praktikum/'.$f['o'].'/tugas', ['title' => $type.' — Data contoh', 'type' => $type, 'mode' => $mode, 'origin_meeting_id' => $type === 'final' ? null : ($origin ?? $f['m']), 'active' => 1, 'mandatory' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('assignments')->max('id');
    }

    private function schedule(array $f, int $a): int
    {
        $this->post('/praktikum/'.$f['o'].'/tugas/'.$a.'/jadwal', ['session_id' => $f['s'], 'collection_session_meeting_id' => $f['sm'], 'opens_at' => '2026-10-01T07:00', 'due_at' => '2026-10-02T12:00', 'closes_at' => '2026-10-05T23:00', 'allow_late' => 1, 'version' => 0, 'reason' => 'Jadwal pengumpulan — Data contoh', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('assignment_schedules')->where('assignment_id', $a)->value('id');
    }

    private function receipt(array $f, int $a, int $e = 0): string
    {
        return '/praktikum/'.$f['o'].'/tugas/'.$a.'/penerimaan/'.($f['enroll'][$e]);
    }

    private function receive(array $f, int $a, array $extra = [], int $e = 0): void
    {
        $this->post($this->receipt($f, $a, $e), array_replace(['version' => 0, 'status' => 'received', 'received_at' => '2026-10-02T10:00', 'late_decision' => 'pending'], $extra))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function makeComponent(array $f, int $a, $weight = 100): int
    {
        $this->post('/praktikum/'.$f['o'].'/komponen-nilai', ['assignment_id' => $a, 'label' => 'Nilai '.$a, 'weight' => $weight, 'max_score' => 100, 'mandatory' => 1, 'active' => 1, 'version' => 0, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('grading_components')->where('assignment_id', $a)->value('id');
    }

    private function grade(array $f, int $cid, $score = 80, int $version = 0): array
    {
        return ['selected' => [$cid], 'rows' => [$cid => ['version' => $version, 'status' => 'graded', 'score' => $score]]];
    }

    private function rules(array $f, array $extra = [], bool $publish = true): int
    {
        $this->post('/praktikum/'.$f['o'].'/aturan-nilai', array_replace(['pass_score' => 60, 'rounding' => 'half_up', 'precision' => 2, 'bands' => "A:80\nB:60\nC:0", 'late_policy' => 'review', 'authority_note' => 'Aturan pengujian — Data contoh, bukan resmi'], $extra))->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table('grading_rules')->max('id');
        if ($publish) {
            $this->post('/praktikum/'.$f['o'].'/aturan-nilai/'.$id.'/terbit', ['confirmed' => 1, 'reason' => 'Terbit pengujian data contoh'])->assertRedirect()->assertSessionHasNoErrors();
        }

        return $id;
    }

    private function finalPayload(array $f): array
    {
        $response = $this->get('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0].'/final')->assertOk();

        return ['digest' => $response->viewData('digest'), 'confirmed' => 1];
    }

    public function test_assignment_origin_stays_one_while_collection_is_meeting_two_and_schedule_used_is_frozen(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $sc = $this->schedule($f, $a);
        $this->assertDatabaseHas('assignments', ['id' => $a, 'origin_meeting_id' => $f['m']]);
        $this->assertDatabaseHas('assignment_schedules', ['id' => $sc, 'collection_session_meeting_id' => $f['sm']]);
        $this->receive($f, $a);
        $this->post('/praktikum/'.$f['o'].'/tugas/'.$a.'/jadwal', ['session_id' => $f['s'], 'opens_at' => '2026-10-01T07:00', 'due_at' => '2026-10-02T13:00', 'closes_at' => '2026-10-05T23:00', 'allow_late' => 1, 'version' => 1, 'confirmed' => 1, 'reason' => 'Tidak boleh ubah arsip'])->assertSessionHasErrors('version');
    }

    public function test_print_without_attachment_actual_time_and_recorded_time_are_distinct_and_late_uses_actual(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $this->schedule($f, $a);
        $this->receive($f, $a);
        $sub = DB::table('submissions')->first();
        $this->assertSame('2026-10-02 03:00:00', $sub->received_at);
        $this->assertSame('2026-10-04 06:00:00', $sub->recorded_at);
        $this->assertFalse((bool) $sub->is_late);
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('grades', 0);
        $this->receive($f, $a, ['received_at' => '2026-10-03T10:00'], 1);
        $this->assertSame(1, DB::table('submissions')->where('is_late', true)->count());
    }

    public function test_receipt_period_and_late_policy_reject_invalid_and_future_times(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $sc = $this->schedule($f, $a);
        $d = ['version' => 0, 'status' => 'received', 'late_decision' => 'pending', 'confirmed' => 1];
        foreach (['2026-09-30T10:00', '2026-10-06T10:00'] as $at) {
            $this->post($this->receipt($f, $a), $d + ['received_at' => $at])->assertSessionHasErrors('received_at');
        }DB::table('assignment_schedules')->where('id', $sc)->update(['allow_late' => false]);
        $this->post($this->receipt($f, $a), $d + ['received_at' => '2026-10-03T10:00'])->assertSessionHasErrors('received_at');
        $this->assertDatabaseCount('submissions', 0);
    }

    /** (a) first input: Save only; (b) student-facing change: checkbox, note optional; (c) crucial correction: reason. */
    public function test_confirmation_tiers_first_input_checkbox_and_crucial_reason(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $url = '/praktikum/'.$f['o'].'/tugas/'.$a.'/jadwal';
        $schedule = ['session_id' => $f['s'], 'collection_session_meeting_id' => $f['sm'], 'opens_at' => '2026-10-01T07:00', 'due_at' => '2026-10-02T12:00', 'closes_at' => '2026-10-05T23:00', 'allow_late' => 1, 'version' => 0];
        // (b) Collection schedule is seen by students: checkbox required, note optional.
        $this->post($url, $schedule)->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('assignment_schedules', 0);
        $this->post($url, $schedule + ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        // (a) First receipt: no checkbox, no reason.
        $this->post($this->receipt($f, $a), ['version' => 0, 'status' => 'received', 'received_at' => '2026-10-02T10:00', 'late_decision' => 'pending'])->assertRedirect()->assertSessionHasNoErrors();
        // (c) Correcting the saved receipt: reason required, a checkbox does not replace it.
        $fix = ['version' => 1, 'status' => 'received', 'received_at' => '2026-10-02T09:00', 'late_decision' => 'pending', 'confirmed' => 1];
        $this->post($this->receipt($f, $a), $fix)->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('submissions', ['version' => 1]);
        $this->post($this->receipt($f, $a), $fix + ['reason' => 'Jam terima salah ketik'])->assertRedirect()->assertSessionHasNoErrors();
        // (a) First grade without checkbox or reason; (c) correcting it needs a reason.
        $c = $this->makeComponent($f, $a);
        $g = '/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0];
        $this->post($g, $this->grade($f, $c, 70))->assertRedirect()->assertSessionHasNoErrors();
        $this->post($g, $this->grade($f, $c, 75, 1))->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('grades', ['component_id' => $c, 'score' => 70]);
        $this->post($g, $this->grade($f, $c, 75, 1) + ['reason' => 'Salah input nilai'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'grade.saved', 'reason' => 'Salah input nilai']);
    }

    public function test_receipt_changes_need_reason_and_stale_version_does_not_overwrite(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $this->schedule($f, $a);
        $this->receive($f, $a);
        $d = ['version' => 1, 'status' => 'revision_requested', 'received_at' => '2026-10-02T10:00', 'late_decision' => 'pending', 'confirmed' => 1];
        $this->post($this->receipt($f, $a), $d)->assertSessionHasErrors('reason');
        $d['reason'] = 'Perlu revisi isi tugas';
        $this->post($this->receipt($f, $a), $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->receipt($f, $a), $d)->assertStatus(409);
        $this->assertDatabaseHas('submissions', ['status' => 'revision_requested', 'version' => 2]);
    }

    public function test_digital_versions_require_revision_permission_and_keep_old_private_files(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'digital');
        $this->schedule($f, $a);
        $this->receive($f, $a, ['status' => 'submitted', 'file' => UploadedFile::fake()->createWithContent('v1.sql', 'SELECT 1;')]);
        $d = ['version' => 1, 'status' => 'revised', 'received_at' => '2026-10-02T11:00', 'late_decision' => 'pending', 'confirmed' => 1, 'reason' => 'Revisi dari praktikan', 'file' => UploadedFile::fake()->createWithContent('v2.sql', 'SELECT 2;')];
        $this->post($this->receipt($f, $a), $d)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('files', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->receive($f, $a, ['version' => 1, 'status' => 'revision_requested', 'reason' => 'Minta revisi SQL']);
        $d['version'] = 2;
        $d['file'] = UploadedFile::fake()->createWithContent('v2.sql', 'SELECT 2;');
        $this->post($this->receipt($f, $a), $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('submission_versions', 2);
        $this->assertDatabaseCount('files', 2);
        $v = DB::table('submission_versions')->first();
        $url = $this->receipt($f, $a).'/versi/'.$v->id;
        $this->get($url)->assertOk();
        $this->actingAs($f['outsider'])->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_digital_upload_required_and_executable_rejected(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'digital');
        $this->schedule($f, $a);
        $d = ['version' => 0, 'status' => 'submitted', 'received_at' => '2026-10-02T10:00', 'late_decision' => 'pending', 'confirmed' => 1];
        $this->post($this->receipt($f, $a), $d)->assertSessionHasErrors('file');
        $this->post($this->receipt($f, $a), $d + ['file' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_report_checklist_is_individual_and_creates_no_grades_or_double_components(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'print', 'final');
        $this->schedule($f, $a);
        $this->assertDatabaseCount('report_checklist_items', 16);
        $this->assertDatabaseCount('grading_components', 0);
        $this->receive($f, $a);
        $this->receive($f, $a, [], 1);
        $items = DB::table('report_checklist_items')->where('assignment_id', $a)->pluck('id')->all();
        $this->post($this->receipt($f, $a).'/checklist', ['version' => 1, 'completed' => $items, 'reason' => 'Semua bagian lengkap', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $sub2 = DB::table('submissions')->where('enrollment_id', $f['enroll'][1])->first();
        $this->assertSame(0, DB::table('report_checklist_results')->where('submission_id', $sub2->id)->count());
        $this->receive($f, $a, ['version' => 2, 'status' => 'complete', 'reason' => 'Checklist lengkap diverifikasi']);
        $this->post($this->receipt($f, $a, 1), ['version' => 1, 'status' => 'complete', 'received_at' => '2026-10-02T10:00', 'late_decision' => 'pending', 'confirmed' => 1, 'reason' => 'Belum memenuhi checklist'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_direct_lab_grade_has_no_submission_and_blank_is_not_zero(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        $url = '/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0];
        $this->actingAs($f['staff'])->post($url, $this->grade($f, $c, null))->assertSessionHasErrors();
        $this->post($url, $this->grade($f, $c, 0))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grades', ['component_id' => $c, 'status' => 'graded', 'score' => 0]);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_grade_selected_only_and_correction_reason_and_stale_batch_atomic(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $b = $this->assignment($f, 'direct', 'lab', $f['m2']);
        $c = $this->makeComponent($f, $a, 50);
        $c2 = $this->makeComponent($f, $b, 50);
        $url = '/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0];
        $d = $this->grade($f, $c, 80);
        $d['rows'][$c2] = ['version' => 0, 'status' => 'graded', 'score' => 20];
        $this->post($url, $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('grades', 1);
        $d = $this->grade($f, $c, 81, 1);
        $this->post($url, $d)->assertSessionHasErrors('reason');
        $d['reason'] = 'Koreksi hasil pemeriksaan';
        $this->post($url, $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $d)->assertStatus(409);
        $bad = $this->grade($f, $c2, 50);
        $bad['selected'][] = $c;
        $bad['rows'][$c] = ['version' => 1, 'status' => 'graded', 'score' => 90];
        $bad['reason'] = 'Koreksi bersamaan';
        $this->post($url, $bad)->assertStatus(409);
        $this->assertDatabaseCount('grades', 1);
        $this->assertDatabaseHas('grades', ['component_id' => $c, 'score' => 81, 'version' => 2]);
    }

    public function test_missing_zero_is_explicit_requires_reason_and_preserves_null_for_ungraded(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        $d = ['selected' => [$c], 'rows' => [$c => ['status' => 'missing_zero', 'score' => null, 'version' => 0]], 'confirmed' => 1];
        $url = '/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0];
        $this->post($url, $d)->assertSessionHasErrors('reason');
        $d['reason'] = 'Tidak memenuhi kewajiban diputuskan nol';
        $this->post($url, $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grades', ['status' => 'missing_zero', 'score' => 0]);
    }

    public function test_default_aslab_cannot_change_components_rules_but_can_grade_and_scope_denied(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        $this->actingAs($f['staff']);
        foreach (['/komponen-nilai', '/aturan-nilai'] as $path) {
            $this->get('/praktikum/'.$f['o'].$path)->assertForbidden();
        }$this->post('/praktikum/'.$f['o'].'/komponen-nilai', [])->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/aturan-nilai', [])->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c))->assertRedirect()->assertSessionHasNoErrors();
        DB::table('staff_assignments')->where('id', $f['grant'])->update(['all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $f['grant'], 'offering_id' => $f['o'], 'session_id' => $f['other']]);
        $this->get('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0])->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c))->assertForbidden();
    }

    public function test_revoked_submission_permission_and_cross_session_scope_reject_direct_requests(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $this->schedule($f, $a);
        $this->actingAs($f['staff']);
        DB::table('user_permissions')->insert(['assignment_id' => $f['grant'], 'permission_key' => 'submissions.manage', 'allowed' => false]);
        $this->get($this->receipt($f, $a))->assertForbidden();
        $this->post($this->receipt($f, $a), [])->assertForbidden();
        $this->get('/praktikum/'.$f['o'].'/tugas/'.$a.'/jadwal')->assertForbidden();
    }

    public function test_rules_draft_allows_unknown_weights_but_publication_and_finalization_blocked(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $this->makeComponent($f, $a, null);
        $rid = $this->rules($f, ['pass_score' => null, 'rounding' => null, 'precision' => null, 'bands' => '', 'late_policy' => null], false);
        $this->post('/praktikum/'.$f['o'].'/aturan-nilai/'.$rid.'/terbit', ['confirmed' => 1, 'reason' => 'Tidak boleh terbit'])->assertSessionHasErrors('assessment');
        $url = '/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0].'/final';
        $d = $this->finalPayload($f);
        $this->post($url, $d)->assertSessionHasErrors('assessment');
        $this->assertDatabaseCount('final_results', 0);
    }

    public function test_valid_rules_finalization_calculation_and_immutable_snapshot_and_writes_locked(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c, 85.555))->assertRedirect()->assertSessionHasNoErrors();
        $this->rules($f);
        $d = $this->finalPayload($f);
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0].'/final', $d)->assertRedirect()->assertSessionHasNoErrors();
        $final = DB::table('final_results')->first();
        $this->assertSame('85.5600', $final->score);
        $this->assertSame('A', $final->letter);
        $this->assertSame('Lulus', $final->decision);
        $this->assertSame('Praktikan 1 — Data contoh', json_decode($final->snapshot_json, true)['identity']['name']);
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c, 60, 1) + ['reason' => 'Koreksi setelah final'])->assertStatus(423);
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0].'/final', $d)->assertStatus(423);
        $this->post('/praktikum/'.$f['o'].'/aturan-nilai', ['authority_note' => 'Aturan baru setelah final'])->assertStatus(423);
    }

    public function test_finalization_preview_digest_rejects_changed_grade(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c))->assertRedirect()->assertSessionHasNoErrors();
        $this->rules($f);
        $d = $this->finalPayload($f);
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c, 90, 1) + ['reason' => 'Koreksi setelah preview'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0].'/final', $d)->assertStatus(409);
        $this->assertDatabaseCount('final_results', 0);
    }

    public function test_mandatory_unresolved_receipt_and_late_decision_block_finalization(): void
    {
        $f = $this->fixtures();
        $lab = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $lab);
        $a = $this->assignment($f);
        $this->schedule($f, $a);
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c))->assertRedirect()->assertSessionHasNoErrors();
        $this->rules($f);
        $service = app(Assessment::class);
        $this->assertNotEmpty($service->finalPreview($f['admin'], $f['o'], $f['enroll'][0])['errors']);
        $this->receive($f, $a, ['received_at' => '2026-10-03T10:00']);
        $this->receive($f, $a, ['version' => 1, 'status' => 'complete', 'received_at' => '2026-10-03T10:00', 'reason' => 'Pemeriksaan tugas selesai']);
        $this->assertStringContainsString('Keterlambatan', implode(' ', $service->finalPreview($f['admin'], $f['o'], $f['enroll'][0])['errors']));
        $this->receive($f, $a, ['version' => 2, 'status' => 'complete', 'received_at' => '2026-10-03T10:00', 'reason' => 'Terlambat diterima sesuai keputusan', 'late_decision' => 'accepted']);
        $this->assertSame([], $service->finalPreview($f['admin'], $f['o'], $f['enroll'][0])['errors']);
    }

    public function test_activity_five_and_final_weight_need_explicit_separate_policy(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'print', 'aktivitas', $f['m5']);
        $b = $this->assignment($f, 'print', 'final');
        $this->makeComponent($f, $a, 50);
        $this->makeComponent($f, $b, 50);
        $rid = $this->rules($f, [], false);
        $this->post('/praktikum/'.$f['o'].'/aturan-nilai/'.$rid.'/terbit', ['confirmed' => 1, 'reason' => 'Tanpa konfirmasi terpisah'])->assertSessionHasErrors('assessment');
        $this->rules($f, ['separate_activity5' => 1]);
        $this->assertSame(1, DB::table('grading_rules')->where('status', 'published')->count());
    }

    public function test_changed_component_invalidates_existing_rule_fingerprint(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        $this->rules($f);
        $this->post('/praktikum/'.$f['o'].'/komponen-nilai', ['id' => $c, 'assignment_id' => $a, 'label' => 'Nama koreksi', 'weight' => 100, 'max_score' => 100, 'mandatory' => 1, 'active' => 1, 'version' => 1, 'confirmed' => 1, 'reason' => 'Koreksi komponen'])->assertRedirect()->assertSessionHasNoErrors();
        $errors = app(Assessment::class)->finalPreview($f['admin'], $f['o'], $f['enroll'][0])['errors'];
        $this->assertStringContainsString('Komponen berubah', implode(' ', $errors));
    }

    public function test_locked_semester_rolls_back_file_and_no_assessment_mutation(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'digital');
        $this->schedule($f, $a);
        DB::table('semesters')->where('id', $f['sem'])->update(['status' => 'locked']);
        $this->post($this->receipt($f, $a), ['version' => 0, 'status' => 'submitted', 'received_at' => '2026-10-02T10:00', 'late_decision' => 'pending', 'confirmed' => 1, 'file' => UploadedFile::fake()->createWithContent('x.sql', 'SELECT 1;')])->assertStatus(423);
        $this->assertDatabaseCount('files', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_audit_failure_rolls_back_grade(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_starts_with($sql, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Injected audit failure');
            }
        });
        $this->post('/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0], $this->grade($f, $c))->assertStatus(500);
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_grade_range_and_forged_component_roll_back_selected_batch(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a);
        $url = '/praktikum/'.$f['o'].'/nilai/'.$f['enroll'][0];
        $this->post($url, $this->grade($f, $c, 101))->assertSessionHasErrors('rows.'.$c.'.score');
        $d = $this->grade($f, $c);
        $d['selected'][] = 99999;
        $d['rows'][99999] = ['version' => 0, 'status' => 'graded', 'score' => 20];
        $this->post($url, $d)->assertForbidden();
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_missing_receipt_decision_requires_reason_and_resolves_obligation_without_file(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $this->schedule($f, $a);
        $d = ['version' => 0, 'status' => 'missing_decided', 'late_decision' => 'pending', 'confirmed' => 1];
        $this->post($this->receipt($f, $a), $d)->assertSessionHasErrors('reason');
        $d['reason'] = 'Tidak dikumpulkan telah ditinjau';
        $this->post($this->receipt($f, $a), $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('submissions', ['status' => 'missing_decided', 'received_at' => null]);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_rule_rejects_wrong_total_and_missing_zero_band_and_publication_replay(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'direct', 'lab');
        $c = $this->makeComponent($f, $a, 99);
        $id = $this->rules($f, ['bands' => "A:80\nB:60"], false);
        $this->post('/praktikum/'.$f['o'].'/aturan-nilai/'.$id.'/terbit', ['confirmed' => 1, 'reason' => 'Total dan rentang salah'])->assertSessionHasErrors('assessment');
        DB::table('grading_components')->where('id', $c)->update(['weight' => 100, 'version' => 2]);
        $published = $this->rules($f);
        $this->post('/praktikum/'.$f['o'].'/aturan-nilai/'.$published.'/terbit', ['confirmed' => 1, 'reason' => 'Replay tidak diizinkan'])->assertStatus(409);
    }

    public function test_checklist_foreign_item_and_stale_version_are_rejected(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f, 'print', 'final');
        $this->schedule($f, $a);
        $this->receive($f, $a);
        $item = DB::table('report_checklist_items')->where('assignment_id', $a)->value('id');
        $d = ['version' => 1, 'completed' => [$item, 99999], 'reason' => 'Periksa checklist terpilih', 'confirmed' => 1];
        $this->post($this->receipt($f, $a).'/checklist', $d)->assertStatus(422);
        $this->assertDatabaseCount('report_checklist_results', 0);
        $d['completed'] = [$item];
        $this->post($this->receipt($f, $a).'/checklist', $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->receipt($f, $a).'/checklist', $d)->assertStatus(409);
    }

    public function test_database_rejects_collection_execution_from_different_session(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        try {
            DB::table('assignment_schedules')->insert(['offering_id' => $f['o'], 'assignment_id' => $a, 'session_id' => $f['other'], 'collection_session_meeting_id' => $f['sm'], 'opens_at' => '2026-10-01', 'due_at' => '2026-10-02', 'closes_at' => '2026-10-05']);
            $this->fail('Cross session must fail');
        } catch (QueryException $e) {
            $this->assertSame(1452, $e->errorInfo[1]);
        }
    }

    public function test_transferred_student_receipt_requires_both_current_and_archived_session_scope(): void
    {
        $f = $this->fixtures();
        $a = $this->assignment($f);
        $this->schedule($f, $a);
        $this->receive($f, $a);
        DB::table('session_memberships')->where('enrollment_id', $f['enroll'][0])->update(['session_id' => $f['other']]);
        DB::table('staff_assignments')->where('id', $f['grant'])->update(['all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $f['grant'], 'session_id' => $f['other'], 'offering_id' => $f['o']]);
        $this->actingAs($f['staff']);
        $url = '/praktikum/'.$f['o'].'/tugas/'.$a.'/penerimaan';
        $this->get($url)->assertOk()->assertDontSee('000041');
        $this->get($this->receipt($f, $a))->assertForbidden();
        DB::table('staff_assignments')->where('id', $f['grant'])->update(['all_sessions' => true]);
        $this->get($url)->assertOk()->assertSee('000041');
        $this->get($this->receipt($f, $a))->assertOk();
    }
}
