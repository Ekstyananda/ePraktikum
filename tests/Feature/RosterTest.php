<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Roster;
use App\Services\StudentImport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class RosterTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true])->fresh();
        $staff = User::factory()->create(['role' => 'aslab', 'active' => true])->fresh();
        $sem = DB::table('semesters')->insertGetId(['code' => 'TEST', 'label' => 'Semester — Data contoh', 'status' => 'active']);
        $p = DB::table('practicums')->insertGetId(['code' => 'SBD', 'name' => 'Praktikum — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p]);
        $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi 1', 'capacity' => 10]);
        $s2 = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi 2', 'capacity' => 10]);
        $a = DB::table('staff_assignments')->insertGetId(['user_id' => $staff->id, 'offering_id' => $o, 'all_sessions' => true]);

        return compact('admin', 'staff', 'sem', 'p', 'o', 's', 's2', 'a');
    }

    private function data(int $s, string $nbi = '001234'): array
    {
        return ['nbi' => $nbi, 'name' => 'Praktikan '.$nbi.' — Data contoh', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s];
    }

    private function add(int $o, int $s, string $nbi = '001234', ?int $supervisor = null): int
    {
        return DB::transaction(fn () => app(Roster::class)->add([...$this->data($s, $nbi), 'supervisor_id' => $supervisor], $o));
    }

    private function supervisor(string $name = 'Dosen — Data contoh'): int
    {
        return DB::table('supervisors')->insertGetId(['name' => $name, 'active' => true]);
    }

    private function restrict(array $f): void
    {
        DB::table('staff_assignments')->where('id', $f['a'])->update(['all_sessions' => false]);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $f['a'], 'session_id' => $f['s'], 'offering_id' => $f['o']]);
    }

    private function upload(array $f, string $csv): array
    {
        $response = $this->post('/praktikum/'.$f['o'].'/impor', ['file' => UploadedFile::fake()->createWithContent('roster.csv', $csv)]);
        $response->assertRedirect();
        $p = DB::table('import_previews')->latest('created_at')->first();

        return [$p, '/praktikum/'.$f['o'].'/impor/'.$p->id];
    }

    private function map(object $p, string $url): void
    {
        $this->post($url.'/map', ['version' => $p->version, 'mapping' => ['nbi' => 0, 'name' => 1, 'sim_class' => 2, 'class_category' => 3, 'session_label' => 4, 'supervisor_name' => null]])->assertRedirect();
    }

    public function test_admin_can_crud_masters_and_duplicate_offering_is_rejected(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['admin'])->get('/pengaturan/master/semester')->assertOk();
        $this->post('/pengaturan/master/semester', ['code' => 'NEW', 'label' => 'Semester Baru', 'starts_at' => '2026-10-01', 'ends_at' => '2027-02-01', 'status' => 'draft'])->assertRedirect();
        $sem = DB::table('semesters')->where('code', 'NEW')->first();
        $this->put('/pengaturan/master/semester/'.$sem->id, ['code' => 'NEW', 'label' => 'Semester Direvisi', 'starts_at' => '2026-10-01', 'ends_at' => '2027-02-02', 'status' => 'active', 'version' => 1, 'confirmed' => 1, 'reason' => 'Koreksi label semester'])->assertRedirect();
        $this->assertDatabaseHas('semesters', ['label' => 'Semester Direvisi', 'version' => 2]);
        $this->post('/pengaturan/master/offering', ['semester_id' => $f['sem'], 'practicum_id' => $f['p'], 'status' => 'draft'])->assertSessionHasErrors('practicum_id');
        $this->delete('/pengaturan/master/semester/'.$sem->id, ['version' => 2, 'confirmed' => 1, 'reason' => 'Data belum digunakan'])->assertRedirect();
        $this->assertDatabaseMissing('semesters', ['id' => $sem->id]);
    }

    public function test_aslab_cannot_change_semester_or_practicum(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['staff'])->get('/pengaturan/master/semester')->assertForbidden();
        $this->post('/pengaturan/master/praktikum', ['code' => 'BAD', 'name' => 'Illegal'])->assertForbidden();
    }

    public function test_assigned_staff_can_create_session_and_restricted_staff_cannot_create_or_edit_other_scope(): void
    {
        $f = $this->fixtures();
        $d = ['label' => 'Sesi Baru', 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Lab uji', 'capacity' => 10, 'responsible_user_id' => $f['staff']->id];
        $this->actingAs($f['staff'])->post('/praktikum/'.$f['o'].'/sesi', $d)->assertRedirect();
        $this->assertDatabaseHas('practicum_sessions', ['label' => 'Sesi Baru']);
        $this->restrict($f);
        $this->get('/praktikum/'.$f['o'].'/sesi')->assertOk()->assertSee('Sesi 1')->assertDontSee('Sesi 2');
        $this->post('/praktikum/'.$f['o'].'/sesi', [...$d, 'label' => 'Forbidden'])->assertForbidden();
        $this->put('/praktikum/'.$f['o'].'/sesi/'.$f['s2'], [...$d, 'version' => 1, 'confirmed' => 1, 'reason' => 'Attempt outside scope'])->assertForbidden();
    }

    public function test_create_optional_supervisor_preserves_leading_zero_and_duplicate_enrollment_rejected(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['staff'])->post('/praktikum/'.$f['o'].'/praktikan', $this->data($f['s']))->assertRedirect();
        $this->assertDatabaseHas('students', ['nbi' => '001234']);
        $this->assertDatabaseHas('enrollments', ['supervisor_id' => null]);
        $this->get('/praktikum/'.$f['o'].'/praktikan')->assertOk()->assertSee('Belum ditentukan');
        $this->post('/praktikum/'.$f['o'].'/praktikan', $this->data($f['s']))->assertSessionHasErrors('nbi');
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_list_edit_export_and_create_enforce_session_and_offering_scope(): void
    {
        $f = $this->fixtures();
        $one = $this->add($f['o'], $f['s'], '001');
        $two = $this->add($f['o'], $f['s2'], '002');
        $this->restrict($f);
        $this->actingAs($f['staff'])->get('/praktikum/'.$f['o'].'/praktikan')->assertOk()->assertSee('Praktikan 001')->assertDontSee('Praktikan 002');
        $this->get('/praktikum/'.$f['o'].'/praktikan/'.$two.'/edit')->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/praktikan', $this->data($f['s2'], '003'))->assertForbidden();
        $this->get('/praktikum/'.$f['o'].'/ekspor')->assertOk();
        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_bulk_three_selected_leaves_other_and_default_preserves_existing_supervisor(): void
    {
        $f = $this->fixtures();
        $old = $this->supervisor();
        $new = $this->supervisor('Dosen Baru — Data contoh');
        $ids = [];
        foreach (['001', '002', '003', '004'] as $i => $nbi) {
            $ids[] = $this->add($f['o'], $f['s'], $nbi, $i === 1 ? $old : null);
        }
        $this->actingAs($f['staff'])->post('/praktikum/'.$f['o'].'/dosen/preview', ['selection_mode' => 'selected', 'selected' => array_slice($ids, 0, 3), 'supervisor_id' => $new, 'mode' => 'empty'])->assertOk()->assertSee('3 praktikan dipilih');
        $this->assertDatabaseHas('enrollments', ['id' => $ids[0], 'supervisor_id' => null]);
        $p = DB::table('import_previews')->first();
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseHas('enrollments', ['id' => $ids[0], 'supervisor_id' => $new]);
        $this->assertDatabaseHas('enrollments', ['id' => $ids[1], 'supervisor_id' => $old]);
        $this->assertDatabaseHas('enrollments', ['id' => $ids[2], 'supervisor_id' => $new]);
        $this->assertDatabaseHas('enrollments', ['id' => $ids[3], 'supervisor_id' => null]);
        $logs = DB::table('activity_logs')->where('action', 'supervisor.assigned')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($logs[0]->request_id, $logs[1]->request_id);
        $this->assertEquals(['supervisor_id' => null], json_decode($logs[0]->before_json, true));
    }

    public function test_bulk_replace_needs_reason_confirmation_and_rejects_replay(): void
    {
        $f = $this->fixtures();
        $sp = $this->supervisor();
        $id = $this->add($f['o'], $f['s']);
        $d = ['selection_mode' => 'selected', 'selected' => [$id], 'supervisor_id' => $sp, 'mode' => 'replace'];
        $this->actingAs($f['admin'])->post('/praktikum/'.$f['o'].'/dosen/preview', $d)->assertSessionHasErrors('reason');
        $this->post('/praktikum/'.$f['o'].'/dosen/preview', [...$d, 'reason' => 'Penetapan pembimbing resmi'])->assertOk();
        $p = DB::table('import_previews')->first();
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id])->assertSessionHasErrors('confirmed');
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1])->assertRedirect();
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1])->assertStatus(409);
    }

    public function test_bulk_unauthorized_member_blocks_entire_batch(): void
    {
        $f = $this->fixtures();
        $sp = $this->supervisor();
        $a = $this->add($f['o'], $f['s'], '001');
        $b = $this->add($f['o'], $f['s2'], '002');
        $this->restrict($f);
        $this->actingAs($f['staff'])->post('/praktikum/'.$f['o'].'/dosen/preview', ['selection_mode' => 'selected', 'selected' => [$a, $b], 'supervisor_id' => $sp, 'mode' => 'empty'])->assertForbidden();
        $this->assertDatabaseCount('import_previews', 0);
        $this->assertSame(0, DB::table('enrollments')->whereNotNull('supervisor_id')->count());
    }

    public function test_bulk_rechecks_revoked_scope_before_commit_all_or_nothing(): void
    {
        $f = $this->fixtures();
        $sp = $this->supervisor();
        $a = $this->add($f['o'], $f['s'], '001');
        $b = $this->add($f['o'], $f['s2'], '002');
        $this->actingAs($f['staff'])->post('/praktikum/'.$f['o'].'/dosen/preview', ['selection_mode' => 'selected', 'selected' => [$a, $b], 'supervisor_id' => $sp, 'mode' => 'empty'])->assertOk();
        $p = DB::table('import_previews')->first();
        $this->restrict($f);
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1])->assertForbidden();
        $this->assertSame(0, DB::table('enrollments')->whereNotNull('supervisor_id')->count());
    }

    public function test_filtered_selection_is_frozen_and_stale_preview_conflicts(): void
    {
        $f = $this->fixtures();
        $sp = $this->supervisor();
        $id = $this->add($f['o'], $f['s']);
        $this->actingAs($f['admin'])->post('/praktikum/'.$f['o'].'/dosen/preview', ['selection_mode' => 'filtered', 'filters' => ['class_category' => 'Pagi'], 'supervisor_id' => $sp, 'mode' => 'empty'])->assertOk();
        $p = DB::table('import_previews')->first();
        $new = $this->add($f['o'], $f['s'], '005');
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1, 'filters' => ['class_category' => 'anything']])->assertRedirect();
        $this->assertDatabaseHas('enrollments', ['id' => $id, 'supervisor_id' => $sp]);
        $this->assertDatabaseHas('enrollments', ['id' => $new, 'supervisor_id' => null]);
        $this->post('/praktikum/'.$f['o'].'/dosen/preview', ['selection_mode' => 'selected', 'selected' => [$new], 'supervisor_id' => $sp, 'mode' => 'empty'])->assertOk();
        $p = DB::table('import_previews')->whereNull('committed_at')->first();
        DB::table('enrollments')->where('id', $new)->increment('version');
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1])->assertStatus(409);
        $this->assertDatabaseHas('enrollments', ['id' => $new, 'supervisor_id' => null]);
    }

    public function test_csv_preview_has_no_roster_mutation_skips_blank_and_repeated_headers_and_imports_without_supervisor(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['staff']);
        [$p,$url] = $this->upload($f, "NBI,Nama,Simpraktikum,Kelas,Sesi\n00123,Praktikan Uji,A,Pagi,Sesi 1\n,,,,\nNBI,Nama,Simpraktikum,Kelas,Sesi\n00124,Praktikan Dua,A,Pagi,Sesi 1\n");
        $this->map($p, $url);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $p = DB::table('import_previews')->find($p->id);
        $payload = json_decode(Crypt::decryptString($p->payload), true);
        $this->assertCount(2, $payload['result']);
        $this->get($url)->assertOk()->assertSee('Semua baris valid');
        $this->post($url.'/commit', ['version' => $p->version, 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('enrollments', 2);
        $this->assertDatabaseHas('students', ['nbi' => '00123']);
        $this->assertSame(2, DB::table('enrollments')->whereNull('supervisor_id')->count());
        $this->post($url.'/commit', ['version' => $p->version, 'confirmed' => 1])->assertStatus(409);
    }

    public function test_invalid_duplicate_import_blocks_all_rows_with_visible_row_errors(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['staff']);
        [$p,$url] = $this->upload($f, "NBI,Nama,Simpraktikum,Kelas,Sesi\n00123,Praktikan,A,Pagi,Sesi 1\n00123,Duplikat,A,Pagi,Sesi 1\n");
        $this->map($p, $url);
        $p = DB::table('import_previews')->find($p->id);
        $this->get($url)->assertOk()->assertSee('NBI duplikat dalam berkas.');
        $this->post($url.'/commit', ['version' => $p->version, 'confirmed' => 1])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_import_preview_is_bound_to_owner_and_revalidates_revoked_permission(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['staff']);
        [$p,$url] = $this->upload($f, "NBI,Nama,Simpraktikum,Kelas,Sesi\n00123,Praktikan,A,Pagi,Sesi 1\n");
        $this->map($p, $url);
        $p = DB::table('import_previews')->find($p->id);
        $this->actingAs($f['admin'])->get($url)->assertForbidden();
        DB::table('user_permissions')->insert(['assignment_id' => $f['a'], 'permission_key' => 'students.manage', 'allowed' => false]);
        $this->actingAs($f['staff'])->post($url.'/commit', ['version' => $p->version, 'confirmed' => 1])->assertForbidden();
        $this->assertDatabaseCount('students', 0);
    }

    public function test_xlsx_reader_preserves_text_nbi_and_rejects_numeric_nbi_and_formula(): void
    {
        $f = $this->fixtures();
        $file = tempnam(sys_get_temp_dir(), 'portal-xlsx-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($file);
        $writer->addRow(Row::fromValues(['NBI', 'Nama', 'Simpraktikum', 'Kelas', 'Sesi']));
        $writer->addRow(Row::fromValues(['00123', 'Nama Uji', 'A', 'Pagi', 'Sesi 1']));
        $writer->addRow(Row::fromValues([1234, 'Nama Angka', 'A', 'Pagi', 'Sesi 1']));
        $writer->addRow(new Row([new FormulaCell('=1+1'), Cell::fromValue('Nama Formula'), Cell::fromValue('A'), Cell::fromValue('Pagi'), Cell::fromValue('Sesi 1')]));
        $writer->close();
        try {
            $payload = app(StudentImport::class)->read(new UploadedFile($file, 'uji.xlsx', null, null, true));
            $payload['mapping'] = ['nbi' => 0, 'name' => 1, 'sim_class' => 2, 'class_category' => 3, 'session_label' => 4, 'supervisor_name' => null];
            $result = app(StudentImport::class)->validate($payload, $f['o'], $f['staff'], app(Roster::class));
            $this->assertSame('00123', $result[0]['data']['nbi']);
            $this->assertSame([], $result[0]['errors']);
            $this->assertStringContainsString('NBI Excel harus', implode(' ', $result[1]['errors']));
            $this->assertNotEmpty($result[2]['errors']);
        } finally {
            unlink($file);
        }
    }

    public function test_capacity_and_locked_semester_prevent_write_without_partial_students(): void
    {
        $f = $this->fixtures();
        DB::table('practicum_sessions')->where('id', $f['s'])->update(['capacity' => 1]);
        $this->add($f['o'], $f['s'], '001');
        $this->actingAs($f['admin'])->post('/praktikum/'.$f['o'].'/praktikan', $this->data($f['s'], '002'))->assertSessionHasErrors('session_id');
        $this->assertDatabaseCount('students', 1);
        DB::table('semesters')->where('id', $f['sem'])->update(['status' => 'locked']);
        $this->post('/praktikum/'.$f['o'].'/praktikan', $this->data($f['s2'], '002'))->assertStatus(423);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_edit_keeps_supervisor_when_empty_and_preserves_session_membership_history(): void
    {
        $f = $this->fixtures();
        $sp = $this->supervisor();
        $id = $this->add($f['o'], $f['s'], '001234', $sp);
        $d = [...$this->data($f['s2']), 'confirmed' => 1, 'supervisor_id' => '', 'version' => 1, 'student_version' => 1, 'reason' => 'Perubahan sesi praktikan', 'effective_date' => now('Asia/Jakarta')->toDateString(), 'active' => 1];
        $this->actingAs($f['staff'])->put('/praktikum/'.$f['o'].'/praktikan/'.$id, $d)->assertRedirect();
        $this->assertDatabaseHas('enrollments', ['id' => $id, 'supervisor_id' => $sp, 'version' => 2]);
        $this->assertDatabaseCount('session_memberships', 2);
        $this->assertSame(1, DB::table('session_memberships')->where('enrollment_id', $id)->whereNull('valid_until')->count());
        $this->put('/praktikum/'.$f['o'].'/praktikan/'.$id, $d)->assertStatus(409);
    }

    public function test_duplicate_supervisor_requires_distinct_identity_confirmation(): void
    {
        $f = $this->fixtures();
        $this->supervisor();
        $this->actingAs($f['staff'])->post('/praktikum/'.$f['o'].'/dosen', ['name' => 'Dosen — Data contoh'])->assertSessionHasErrors('name');
        $this->post('/praktikum/'.$f['o'].'/dosen', ['name' => 'Dosen — Data contoh', 'identity_code' => 'NIDN-002', 'distinct_person' => 1])->assertRedirect();
        $this->assertDatabaseCount('supervisors', 2);
    }

    public function test_export_neutralizes_formula_injection_and_scopes_rows(): void
    {
        $f = $this->fixtures();
        $id = $this->add($f['o'], $f['s'], '001');
        $other = $this->add($f['o'], $f['s2'], '002');
        DB::table('students')->where('nbi', '001')->update(['name' => '=HYPERLINK("bad")']);
        $this->restrict($f);
        $response = $this->actingAs($f['staff'])->get('/praktikum/'.$f['o'].'/ekspor');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Praktikan 002', $csv);
        DB::table('user_permissions')->insert(['assignment_id' => $f['a'], 'permission_key' => 'reports.export', 'allowed' => false]);
        $this->get('/praktikum/'.$f['o'].'/ekspor')->assertForbidden();
    }

    public function test_membership_database_constraints_reject_two_open_sessions(): void
    {
        $f = $this->fixtures();
        $id = $this->add($f['o'], $f['s']);
        $this->expectException(QueryException::class);
        DB::table('session_memberships')->insert(['enrollment_id' => $id, 'offering_id' => $f['o'], 'session_id' => $f['s2'], 'valid_from' => now()->toDateString()]);
    }

    public function test_xlsx_export_stores_nbi_and_formula_like_names_as_text(): void
    {
        $f = $this->fixtures();
        $this->add($f['o'], $f['s'], '00123');
        DB::table('students')->where('nbi', '00123')->update(['name' => '=HYPERLINK("example")']);
        $response = $this->actingAs($f['admin'])->get('/praktikum/'.$f['o'].'/ekspor?format=xlsx');
        $response->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'portal-export-test-');
        file_put_contents($file, $response->streamedContent());
        $reader = new Reader;
        try {
            $reader->open($file);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row;
                }break;
            }$this->assertSame('00123', $rows[1]->cells[1]->getValue());
            $this->assertInstanceOf(StringCell::class, $rows[1]->cells[2]);
            $this->assertSame("'=HYPERLINK(\"example\")", $rows[1]->cells[2]->getValue());
        } finally {
            $reader->close();
            unlink($file);
        }
    }

    public function test_real_xlsx_upload_mapping_and_commit_work_without_supervisor(): void
    {
        $f = $this->fixtures();
        $file = tempnam(sys_get_temp_dir(), 'portal-upload-test-');
        $w = new Writer;
        $w->openToFile($file);
        $w->addRow(Row::fromValues(['NBI', 'Nama', 'Simpraktikum', 'Kelas', 'Sesi']));
        $w->addRow(Row::fromValues(['000001', 'Nama XLSX', 'A', 'Pagi', 'Sesi 1']));
        $w->close();
        try {
            $this->actingAs($f['staff'])->post('/praktikum/'.$f['o'].'/impor', ['file' => new UploadedFile($file, 'roster.xlsx', null, null, true)])->assertRedirect();
            $p = DB::table('import_previews')->first();
            $url = '/praktikum/'.$f['o'].'/impor/'.$p->id;
            $this->map($p, $url);
            $p = DB::table('import_previews')->find($p->id);
            $this->post($url.'/commit', ['version' => $p->version, 'confirmed' => 1])->assertRedirect();
            $this->assertDatabaseHas('students', ['nbi' => '000001']);
            $this->assertDatabaseHas('enrollments', ['supervisor_id' => null]);
        } finally {
            unlink($file);
        }
    }

    public function test_import_rechecks_new_enrollment_before_commit_without_overwrite(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['admin']);
        [$p,$url] = $this->upload($f, "NBI,Nama,Simpraktikum,Kelas,Sesi\n001234,Praktikan 001234 — Data contoh,A,Pagi,Sesi 1\n002,Praktikan Lain,A,Pagi,Sesi 1\n");
        $this->map($p, $url);
        $p = DB::table('import_previews')->find($p->id);
        $sp = $this->supervisor();
        $id = $this->add($f['o'], $f['s'], '001234', $sp);
        $this->post($url.'/commit', ['version' => $p->version, 'confirmed' => 1])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseHas('enrollments', ['id' => $id, 'supervisor_id' => $sp]);
    }

    public function test_permission_deny_blocks_all_student_mutations_and_import(): void
    {
        $f = $this->fixtures();
        DB::table('user_permissions')->insert(['assignment_id' => $f['a'], 'permission_key' => 'students.manage', 'allowed' => false]);
        $this->actingAs($f['staff'])->get('/praktikum/'.$f['o'].'/praktikan')->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/praktikan', $this->data($f['s']))->assertForbidden();
        $this->post('/praktikum/'.$f['o'].'/dosen', ['name' => 'Dosen Baru'])->assertForbidden();
        $this->get('/praktikum/'.$f['o'].'/impor')->assertForbidden();
        $this->assertDatabaseCount('students', 0);
    }

    public function test_preview_expiry_and_locked_bulk_do_not_change_selected_rows(): void
    {
        $f = $this->fixtures();
        $sp = $this->supervisor();
        $id = $this->add($f['o'], $f['s']);
        $this->actingAs($f['admin'])->post('/praktikum/'.$f['o'].'/dosen/preview', ['selection_mode' => 'selected', 'selected' => [$id], 'supervisor_id' => $sp, 'mode' => 'empty'])->assertOk();
        $p = DB::table('import_previews')->first();
        DB::table('import_previews')->where('id', $p->id)->update(['expires_at' => now()->subMinute()]);
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1])->assertStatus(409);
        $this->artisan('portal:prune-previews')->assertSuccessful();
        $this->assertDatabaseCount('import_previews', 0);
        $this->assertDatabaseHas('enrollments', ['id' => $id, 'supervisor_id' => null]);
        $this->post('/praktikum/'.$f['o'].'/dosen/preview', ['selection_mode' => 'selected', 'selected' => [$id], 'supervisor_id' => $sp, 'mode' => 'empty'])->assertOk();
        $p = DB::table('import_previews')->first();
        DB::table('semesters')->where('id', $f['sem'])->update(['status' => 'locked']);
        $this->post('/praktikum/'.$f['o'].'/dosen/commit', ['token' => $p->id, 'confirmed' => 1])->assertStatus(423);
        $this->assertDatabaseHas('enrollments', ['id' => $id, 'supervisor_id' => null]);
    }

    public function test_used_master_and_session_deletion_is_rejected_without_destroying_history(): void
    {
        $f = $this->fixtures();
        $this->add($f['o'], $f['s']);
        $this->actingAs($f['admin'])->delete('/pengaturan/master/semester/'.$f['sem'], ['version' => 1, 'confirmed' => 1, 'reason' => 'Try delete used semester'])->assertSessionHasErrors('reason');
        $this->delete('/praktikum/'.$f['o'].'/sesi/'.$f['s'], ['version' => 1, 'confirmed' => 1, 'reason' => 'Try delete used session'])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('session_memberships', 1);
    }

    public function test_session_capacity_cannot_be_reduced_below_active_members(): void
    {
        $f = $this->fixtures();
        $this->add($f['o'], $f['s'], '001');
        $this->add($f['o'], $f['s'], '002');
        $this->actingAs($f['admin'])->put('/praktikum/'.$f['o'].'/sesi/'.$f['s'], ['label' => 'Sesi 1', 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Lab', 'capacity' => 1, 'version' => 1, 'confirmed' => 1, 'reason' => 'Try reduce below occupancy'])->assertSessionHasErrors('capacity');
        $this->assertDatabaseHas('practicum_sessions', ['id' => $f['s'], 'capacity' => 10, 'version' => 1]);
    }
}
