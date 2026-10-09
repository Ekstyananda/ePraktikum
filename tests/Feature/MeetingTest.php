<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MeetingRoster;
use App\Services\Roster;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesPortalPublic;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use MakesPortalPublic;
    use RefreshDatabase;

    private function fixtures(int $count = 3): array
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $staff = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $outsider = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'M3', 'label' => 'Semester — Data contoh']);
        $p = DB::table('practicums')->insertGetId(['code' => 'M3', 'name' => 'SBD — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p]);
        $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi A', 'capacity' => 200]);
        $other = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi B']);
        $assignment = DB::table('staff_assignments')->insertGetId(['user_id' => $staff->id, 'offering_id' => $o, 'all_sessions' => true]);
        $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan — Data contoh']);
        $sm = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $s, 'meeting_id' => $m, 'starts_at' => '2026-10-03 01:00:00', 'ends_at' => '2026-10-03 03:00:00', 'room' => 'Lab — Data contoh']);
        $this->actingAs($staff);
        $enrollments = [];
        for ($i = 0; $i < $count; $i++) {
            $enrollments[] = DB::transaction(fn () => app(Roster::class)->add(['nbi' => '0012'.$i, 'name' => 'Praktikan '.$i.' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-10-01'], $o));
        }

        return compact('admin', 'staff', 'outsider', 'sem', 'p', 'o', 's', 'other', 'assignment', 'm', 'sm', 'enrollments');
    }

    private function base(array $f): string
    {
        return '/praktikum/'.$f['o'].'/presensi/'.$f['sm'];
    }

    private function snapshot(array $f): void
    {
        $this->post($this->base($f).'/snapshot', ['version' => 1, 'confirmed' => 1])->assertRedirect();
    }

    private function payload(array $participants, string $status = 'present'): array
    {
        $rows = [];
        foreach ($participants as $p) {
            $rows[$p->id] = ['version' => $p->attendance_version, 'status' => $status, 'note' => null];
        }

        return ['selected' => array_column($participants, 'id'), 'rows' => $rows, 'action' => 'save', 'confirmed' => 1];
    }

    private function rows(array $f): array
    {
        return app(MeetingRoster::class)->participants($f['sm'])->get()->all();
    }

    private function pdf(string $name = 'scan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    public function test_master_defaults_are_explicit_editable_and_have_no_modules_or_dates(): void
    {
        $f = $this->fixtures();
        DB::table('session_meetings')->where('id', $f['sm'])->delete();
        DB::table('meetings')->where('id', $f['m'])->delete();
        $url = '/praktikum/'.$f['o'].'/pertemuan/awal';
        // First input: just Save, no checkbox or reason.
        $this->post($url, ['count' => 5])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meetings', 5);
        $this->assertDatabaseCount('materials', 0);
        $this->assertDatabaseCount('session_meetings', 0);
        $this->post($url, ['count' => 5, 'confirmed' => 1])->assertStatus(409);
        $m = DB::table('meetings')->first();
        $this->put('/praktikum/'.$f['o'].'/pertemuan/'.$m->id, ['number' => 1, 'title' => 'Judul resmi diisi operator', 'version' => 1, 'confirmed' => 1, 'reason' => 'Judul sesuai arahan praktikum'])->assertRedirect();
        $this->assertDatabaseHas('meetings', ['id' => $m->id, 'version' => 2]);
    }

    public function test_scheduling_persists_utc_and_rejects_overlap_and_cross_scope(): void
    {
        $f = $this->fixtures();
        $m = DB::table('meetings')->insertGetId(['offering_id' => $f['o'], 'number' => 2, 'title' => 'Pertemuan 2']);
        $url = '/praktikum/'.$f['o'].'/pelaksanaan';
        $d = ['meeting_id' => $m, 'session_id' => $f['s'], 'starts_at' => '2026-10-10T08:00', 'ends_at' => '2026-10-10T10:00', 'room' => 'Lab uji'];
        $this->post($url, $d)->assertRedirect();
        $this->assertDatabaseHas('session_meetings', ['meeting_id' => $m, 'starts_at' => '2026-10-10 01:00:00']);
        $this->post($url, $d)->assertSessionHasErrors('starts_at');
        $m3 = DB::table('meetings')->insertGetId(['offering_id' => $f['o'], 'number' => 3, 'title' => 'Pertemuan 3']);
        $this->post($url, array_replace($d, ['meeting_id' => $m3, 'starts_at' => '2026-10-10T09:00']))->assertSessionHasErrors('starts_at');
        $this->actingAs($f['outsider'])->post($url, $d)->assertForbidden();
    }

    public function test_restricted_staff_can_schedule_own_session_but_not_shared_meetings_or_modules(): void
    {
        $f = $this->fixtures();
        DB::table('staff_assignments')->where('id', $f['assignment'])->update(['all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $f['assignment'], 'session_id' => $f['s'], 'offering_id' => $f['o']]);
        $this->get('/praktikum/'.$f['o'].'/pertemuan')->assertOk()->assertSee('Sesi A');
        $this->post('/praktikum/'.$f['o'].'/pertemuan', ['number' => 2, 'title' => 'Forbidden'])->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/pertemuan/'.$f['m'].'/modul', ['title' => 'Forbidden', 'file' => $this->pdf()])->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/pelaksanaan', ['meeting_id' => $f['m'], 'session_id' => $f['other'], 'starts_at' => '2026-10-05T08:00', 'ends_at' => '2026-10-05T10:00', 'room' => 'Lab'])->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/pelaksanaan', ['meeting_id' => $f['m'], 'session_id' => $f['s'], 'starts_at' => '2026-10-05T08:00', 'ends_at' => '2026-10-05T10:00', 'room' => 'Lab'])->assertSessionHasErrors('starts_at');
    }

    public function test_snapshot_is_explicit_initial_unrecorded_and_replay_never_adds_rows(): void
    {
        $f = $this->fixtures();
        $this->get($this->base($f))->assertOk()->assertSee('Bekukan peserta');
        $this->get($this->base($f).'/cetak')->assertRedirect();
        $this->assertDatabaseCount('meeting_participants', 0);
        $this->snapshot($f);
        $this->assertDatabaseCount('meeting_participants', 3);
        $this->assertDatabaseCount('attendances', 3);
        $this->assertDatabaseMissing('attendances', ['status' => 'absent']);
        foreach ($this->rows($f) as $p) {
            $this->assertSame('unrecorded', $p->status);
            $this->assertNull($p->recorded_at);
        }$this->post($this->base($f).'/snapshot', ['version' => 1, 'confirmed' => 1])->assertStatus(409);
        $this->post($this->base($f).'/snapshot', ['version' => 2, 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('meeting_participants', 3);
    }

    public function test_snapshot_uses_effective_membership_and_active_roster_on_meeting_date(): void
    {
        $f = $this->fixtures();
        DB::table('enrollments')->where('id', $f['enrollments'][0])->update(['active' => false]);
        DB::table('session_memberships')->where('enrollment_id', $f['enrollments'][1])->update(['valid_until' => '2026-10-03']);
        DB::table('session_memberships')->where('enrollment_id', $f['enrollments'][2])->update(['valid_from' => '2026-10-04']);
        $this->post($this->base($f).'/snapshot', ['version' => 1, 'confirmed' => 1])->assertSessionHasErrors('snapshot');
        $this->assertDatabaseCount('meeting_participants', 0);
        $this->assertDatabaseHas('session_meetings', ['id' => $f['sm'], 'version' => 1]);
    }

    public function test_archive_names_order_metadata_and_membership_do_not_follow_live_master(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $before = $this->get($this->base($f).'/cetak')->assertOk()->getContent();
        DB::table('students')->update(['name' => 'Nama berubah']);
        DB::table('practicums')->where('id', $f['p'])->update(['name' => 'Praktikum berubah']);
        DB::table('semesters')->where('id', $f['sem'])->update(['label' => 'Semester berubah']);
        DB::table('practicum_sessions')->where('id', $f['s'])->update(['label' => 'Sesi berubah']);
        DB::table('meetings')->where('id', $f['m'])->update(['title' => 'Judul berubah', 'number' => 9]);
        DB::table('session_memberships')->update(['session_id' => $f['other']]);
        $after = $this->get($this->base($f).'/cetak')->assertOk()->getContent();
        $this->assertSame($before, $after);
        $this->get($this->base($f))->assertOk()->assertSee('Praktikan 0')->assertDontSee('Nama berubah');
    }

    public function test_bulk_present_only_changes_selected_and_initial_input_needs_no_reason(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $rows = $this->rows($f);
        $d = $this->payload([$rows[0], $rows[1]]);
        $d['action'] = 'present';
        $this->post($this->base($f), $d)->assertRedirect();
        $this->assertDatabaseCount('attendances', 3);
        $this->assertSame(2, DB::table('attendances')->where('status', 'present')->count());
        $this->assertDatabaseHas('attendances', ['participant_id' => $rows[2]->id, 'status' => 'unrecorded', 'version' => 1]);
        $logs = DB::table('activity_logs')->where('action', 'attendance.recorded')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($logs[0]->request_id, $logs[1]->request_id);
        $this->assertSame($f['staff']->id, $logs[0]->actor_id);
    }

    public function test_correction_needs_reason_and_retains_old_new_actor_atomically(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $this->post($this->base($f), $this->payload([$this->rows($f)[0]]))->assertRedirect();
        $d = $this->payload([$this->rows($f)[0]], 'sick');
        $this->post($this->base($f), $d)->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('attendances', ['participant_id' => $d['selected'][0], 'status' => 'present', 'version' => 2]);
        $d['reason'] = 'Koreksi sesuai bukti sakit';
        $this->actingAs($f['admin'])->post($this->base($f), $d)->assertRedirect();
        $log = DB::table('activity_logs')->where('action', 'attendance.corrected')->first();
        $this->assertSame('present', json_decode($log->before_json, true)['status']);
        $this->assertSame('sick', json_decode($log->after_json, true)['status']);
        $this->assertSame($f['admin']->id, $log->actor_id);
        $this->assertSame($d['reason'], $log->reason);
    }

    public function test_stale_second_row_rolls_back_first_row_and_its_audit(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $rows = $this->rows($f);
        DB::table('attendances')->where('participant_id', $rows[1]->id)->update(['version' => 2]);
        $d = $this->payload([$rows[0], $rows[1]]);
        $this->post($this->base($f), $d)->assertStatus(409);
        $this->assertDatabaseHas('attendances', ['participant_id' => $rows[0]->id, 'status' => 'unrecorded', 'version' => 1]);
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'attendance.recorded')->count());
    }

    public function test_foreign_participant_forgery_and_duplicate_ids_reject_entire_batch(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $d = $this->payload([$this->rows($f)[0]]);
        $d['selected'][] = 99999;
        $d['rows'][99999] = ['version' => 1, 'status' => 'present', 'note' => null];
        $this->post($this->base($f), $d)->assertForbidden();
        $this->assertSame(0, DB::table('attendances')->where('status', 'present')->count());
        $d = $this->payload([$this->rows($f)[0]]);
        $d['selected'][] = $d['selected'][0];
        $this->post($this->base($f), $d)->assertSessionHasErrors();
    }

    public function test_snapshot_schedule_cannot_be_changed_and_stale_meeting_edit_conflicts(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $url = '/praktikum/'.$f['o'].'/pelaksanaan/'.$f['sm'];
        $this->put($url, ['meeting_id' => $f['m'], 'session_id' => $f['s'], 'starts_at' => '2026-10-03T08:00', 'ends_at' => '2026-10-03T10:00', 'room' => 'Lab changed', 'version' => 2, 'confirmed' => 1, 'reason' => 'Perubahan jadwal setelah cetak'])->assertStatus(423);
        $this->assertDatabaseHas('session_meetings', ['id' => $f['sm'], 'room' => 'Lab — Data contoh']);
        $d = ['number' => 1, 'title' => 'New title', 'version' => 1, 'confirmed' => 1, 'reason' => 'Koreksi judul pertemuan'];
        $this->put('/praktikum/'.$f['o'].'/pertemuan/'.$f['m'], $d)->assertRedirect();
        $this->put('/praktikum/'.$f['o'].'/pertemuan/'.$f['m'], $d)->assertStatus(409);
    }

    public function test_denied_revoked_and_restricted_attendance_cannot_read_write_print_or_download(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $d = $this->payload([$this->rows($f)[0]]);
        DB::table('staff_assignments')->where('id', $f['assignment'])->update(['all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $f['assignment'], 'session_id' => $f['other'], 'offering_id' => $f['o']]);
        foreach (['', '/cetak', '/scan/1'] as $path) {
            $this->get($this->base($f).$path)->assertForbidden();
        }$this->post($this->base($f), $d)->assertForbidden();
        DB::table('staff_assignments')->where('id', $f['assignment'])->update(['all_sessions' => true]);
        DB::table('user_permissions')->insert(['assignment_id' => $f['assignment'], 'permission_key' => 'attendance.manage', 'allowed' => false]);
        $this->post($this->base($f).'/snapshot', ['version' => 2, 'confirmed' => 1])->assertForbidden();
        $this->post($this->base($f).'/scan', ['file' => $this->pdf(), 'confirmed' => 1])->assertForbidden();
    }

    public function test_scan_is_private_validated_and_previous_scan_is_retained(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $url = $this->base($f).'/scan';
        $this->post($url, ['file' => $this->pdf(), 'confirmed' => 1, 'note' => 'Scan pertama'])->assertRedirect();
        $this->post($url, ['file' => $this->pdf('scan-2.pdf'), 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('attendance_documents', 2);
        $file = DB::table('files')->first();
        Storage::disk('local')->assertExists($file->storage_path);
        $this->assertSame('private', $file->visibility);
        $this->assertSame(64, strlen($file->checksum));
        $doc = DB::table('attendance_documents')->first();
        $this->get($url.'/'.$doc->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/storage/'.$file->storage_path)->assertNotFound();
        $this->actingAs($f['outsider'])->get($url.'/'.$doc->id)->assertForbidden();
        auth()->logout();
        $this->get($url.'/'.$doc->id)->assertRedirect('/login');
    }

    public function test_scan_invalid_executable_extension_and_oversize_are_rejected(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $url = $this->base($f).'/scan';
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'), 'confirmed' => 1])->assertSessionHasErrors('file');
        $this->post($url, ['file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'), 'confirmed' => 1])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('files', 0);
    }

    public function test_locked_semester_blocks_snapshot_attendance_upload_and_shared_edits(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        DB::table('semesters')->where('id', $f['sem'])->update(['status' => 'locked']);
        $this->post($this->base($f), $this->payload([$this->rows($f)[0]]))->assertStatus(423);
        $this->post($this->base($f).'/scan', ['file' => $this->pdf(), 'confirmed' => 1])->assertStatus(423);
        $this->post('/praktikum/'.$f['o'].'/pertemuan/'.$f['m'].'/modul', ['title' => 'Module', 'file' => $this->pdf()])->assertStatus(423);
        $this->assertDatabaseCount('files', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->get($this->base($f).'/cetak')->assertOk();
    }

    public function test_module_draft_publish_unpublish_and_public_no_roster(): void
    {
        $f = $this->fixtures();
        $slug = $this->makePublic($f['o']);
        $url = '/praktikum/'.$f['o'].'/pertemuan/'.$f['m'].'/modul';
        $this->post($url, ['title' => 'Materi — Data contoh', 'file' => $this->pdf('modul.pdf')])->assertRedirect();
        $m = DB::table('materials')->first();
        $this->get('/'.$slug.'/modul/'.$m->id.'/unduh')->assertNotFound();
        $this->get($url.'/'.$m->id.'/unduh')->assertOk();
        $d = ['version' => 1, 'published' => 1, 'confirmed' => 1, 'reason' => 'Materi siap untuk diterbitkan'];
        $this->post($url.'/'.$m->id.'/publikasi', $d)->assertRedirect();
        $this->get('/'.$slug.'/modul')->assertOk()->assertSee('Materi — Data contoh')->assertDontSee('00120')->assertDontSee('class="sidebar"', false);
        auth()->logout();
        $this->get('/'.$slug.'/modul/'.$m->id.'/unduh')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($f['staff'])->post($url.'/'.$m->id.'/publikasi', array_replace($d, ['published' => 0, 'version' => 2]))->assertRedirect();
        $this->get('/'.$slug.'/modul/'.$m->id.'/unduh')->assertNotFound();
        $this->assertDatabaseCount('files', 1);
    }

    public function test_module_invalid_file_missing_confirmation_stale_and_revoked_publish_rejected(): void
    {
        $f = $this->fixtures();
        $url = '/praktikum/'.$f['o'].'/pertemuan/'.$f['m'].'/modul';
        $this->post($url, ['title' => 'Danger', 'file' => UploadedFile::fake()->createWithContent('evil.svg', '<svg/>')])->assertSessionHasErrors('file');
        $this->post($url, ['title' => 'Valid', 'file' => $this->pdf()])->assertRedirect();
        $m = DB::table('materials')->first();
        $d = ['version' => 1, 'published' => 1, 'reason' => 'Materi siap terbit'];
        $this->post($url.'/'.$m->id.'/publikasi', $d)->assertSessionHasErrors('confirmed');
        $d['confirmed'] = 1;
        $this->post($url.'/'.$m->id.'/publikasi', $d)->assertRedirect();
        $this->post($url.'/'.$m->id.'/publikasi', $d)->assertStatus(409);
        DB::table('user_permissions')->insert(['assignment_id' => $f['assignment'], 'permission_key' => 'materials.manage', 'allowed' => false]);
        $this->post($url.'/'.$m->id.'/publikasi', array_replace($d, ['version' => 2, 'published' => 0]))->assertForbidden();
        $this->get($url.'/'.$m->id.'/unduh')->assertForbidden();
    }

    public function test_database_rejects_cross_offering_execution_and_double_presence(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $p = $this->rows($f)[0];
        try {
            DB::table('meeting_participants')->insert(['session_meeting_id' => $f['sm'], 'meeting_id' => $f['m'], 'offering_id' => $f['o'], 'enrollment_id' => $p->enrollment_id, 'nbi' => 'x', 'name' => 'x', 'sim_class' => 'x', 'class_category' => 'x', 'session_label' => 'x', 'print_order' => 10]);
            $this->fail('Duplicate must fail');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        }$otherOffering = DB::table('practicum_offerings')->insertGetId(['semester_id' => $f['sem'], 'practicum_id' => DB::table('practicums')->insertGetId(['code' => 'OTHER', 'name' => 'Other'])]);
        try {
            DB::table('session_meetings')->insert(['offering_id' => $otherOffering, 'session_id' => $f['other'], 'meeting_id' => $f['m'], 'starts_at' => '2026-10-10 01:00:00', 'ends_at' => '2026-10-10 03:00:00', 'room' => 'x']);
            $this->fail('Cross offering must fail');
        } catch (QueryException $e) {
            $this->assertSame(1452, $e->errorInfo[1]);
        }
    }

    public function test_no_participant_can_be_snapshotted_twice_in_same_logical_meeting(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        DB::table('session_memberships')->update(['session_id' => $f['other']]);
        $sm = DB::table('session_meetings')->insertGetId(['offering_id' => $f['o'], 'session_id' => $f['other'], 'meeting_id' => $f['m'], 'starts_at' => '2026-10-03 05:00:00', 'ends_at' => '2026-10-03 07:00:00', 'room' => 'Lab B']);
        $this->post('/praktikum/'.$f['o'].'/presensi/'.$sm.'/snapshot', ['version' => 1, 'confirmed' => 1])->assertSessionHasErrors('snapshot');
        $this->assertDatabaseCount('meeting_participants', 3);
    }

    public function test_audit_failure_rolls_back_presensi_in_same_transaction(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        DB::connection()->beforeExecuting(function ($query) {
            if (str_starts_with($query, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Test injected audit failure');
            }
        });
        $this->post($this->base($f), $this->payload([$this->rows($f)[0]]))->assertStatus(500);
        $this->assertSame(0, DB::table('attendances')->where('status', 'present')->count());
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'attendance.recorded')->count());
    }

    public function test_single_participant_form_uses_same_scoped_versioned_save_and_leaves_others_untouched(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $rows = $this->rows($f);
        $url = $this->base($f).'/peserta/'.$rows[0]->id.'/edit';
        $this->get($url)->assertOk()->assertSee('Praktikan 0')->assertSee('Simpan presensi');
        $this->post($this->base($f), $this->payload([$rows[0]], 'excused'))->assertRedirect();
        $this->assertDatabaseHas('attendances', ['participant_id' => $rows[0]->id, 'status' => 'excused', 'version' => 2]);
        $this->assertDatabaseHas('attendances', ['participant_id' => $rows[1]->id, 'status' => 'unrecorded', 'version' => 1]);
        $this->get($this->base($f).'/peserta/99999/edit')->assertForbidden();
        $this->actingAs($f['outsider'])->get($url)->assertForbidden();
    }

    public function test_print_has_five_columns_wider_name_and_alternating_number_not_nbi(): void
    {
        $f = $this->fixtures();
        $this->snapshot($f);
        $html = $this->get($this->base($f).'/cetak')->assertOk()->getContent();
        $this->assertStringContainsString('style="width:39%"', $html);
        $this->assertStringContainsString('style="width:25%"', $html);
        $this->assertStringContainsString('signature-number odd">1.', $html);
        $this->assertStringContainsString('signature-number even">2.', $html);
        $this->assertStringContainsString('00120', $html);
        $this->assertStringNotContainsString('class="sidebar"', $html);
    }
}
