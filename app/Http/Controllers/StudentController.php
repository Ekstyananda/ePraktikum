<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Roster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class StudentController
{
    public const FILTERS = ['q' => 'nullable|string|max:100', 'session_id' => 'nullable|integer', 'class_category' => 'nullable|string|max:80', 'supervisor_id' => 'nullable|string|max:80', 'active' => 'nullable|in:0,1', 'per_page' => 'nullable|integer|in:10,25,50'];

    public function index(Request $r, int $offering, Roster $roster)
    {
        $f = $r->validate(self::FILTERS);
        $q = $roster->filters($roster->query($r->user(), $offering), $f);

        return view('students.index', ['o' => $roster->offering($offering), 'rows' => $q->orderBy('st.nbi')->paginate($f['per_page'] ?? 10)->withQueryString(), 'sessions' => $roster->access->sessions($r->user(), $offering)->filter(fn ($s) => $roster->access->allowed($r->user(), $offering, 'students.manage', $s->id)), 'supervisors' => DB::table('supervisors')->where('active', true)->orderBy('name')->get(), 'canExport' => $roster->any($r->user(), $offering, 'reports.export')]);
    }

    public function form(Request $r, int $offering, Roster $roster, ?int $id = null)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $row = $id ? $roster->query($r->user(), $offering)->where('e.id', $id)->first() : null;
        if ($id) {
            abort_unless($row, 403);
        }

        return view('students.form', ['o' => $roster->offering($offering), 'row' => $row, 'sessions' => $roster->access->sessions($r->user(), $offering)->filter(fn ($s) => $roster->access->allowed($r->user(), $offering, 'students.manage', $s->id)), 'supervisors' => DB::table('supervisors')->where('active', true)->orderBy('name')->get()]);
    }

    public function save(Request $r, int $offering, Roster $roster, ?int $id = null)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $rules = ['nbi' => 'required|string|max:40|regex:/^[0-9A-Za-z.-]+$/', 'name' => 'required|string|max:150', 'sim_class' => 'required|string|max:80', 'class_category' => 'required|string|max:80', 'session_id' => 'required|integer', 'supervisor_id' => ['nullable', 'integer', Rule::exists('supervisors', 'id')->where('active', true)], 'active' => 'sometimes|boolean'];
        if ($id) {
            $rules += ['version' => 'required|integer', 'student_version' => 'required|integer', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted', 'effective_date' => 'required|date|date_equals:'.now('Asia/Jakarta')->toDateString(), 'clear_supervisor' => 'nullable|boolean'];
        }
        $d = $r->validate($rules);
        $roster->require($r->user(), $offering, 'students.manage', (int) $d['session_id']);
        DB::transaction(function () use ($roster, $r, $offering, $id, $d) {
            $roster->writable($offering);
            if (! $id) {
                $roster->add($d, $offering);

                return;
            }
            $e = DB::table('enrollments')->where('id', $id)->where('offering_id', $offering)->lockForUpdate()->first();
            abort_unless($e, 403);
            if (DB::table('session_memberships')->where('enrollment_id', $id)->where('valid_from', '>', now('Asia/Jakarta')->toDateString())->exists()) {
                throw ValidationException::withMessages(['session_id' => 'Perpindahan bertanggal efektif mendatang sudah disetujui; edit roster setelah tanggal efektif.']);
            }
            $m = DB::table('session_memberships')->where('enrollment_id', $id)->whereNull('valid_until')->lockForUpdate()->firstOrFail();
            $roster->require($r->user(), $offering, 'students.manage', $m->session_id);
            abort_if($e->version != (int) $d['version'], 409);
            $st = DB::table('students')->where('id', $e->student_id)->lockForUpdate()->firstOrFail();
            abort_if($st->version != (int) $d['student_version'], 409);
            if ($st->nbi !== $d['nbi']) {
                throw ValidationException::withMessages(['nbi' => 'NBI tidak dapat diubah setelah pendaftaran.']);
            }
            if ($st->name !== $d['name'] && $r->user()->role !== 'admin' && DB::table('enrollments')->where('student_id', $st->id)->where('offering_id', '!=', $offering)->exists()) {
                abort(403, 'Perubahan nama master bersama hanya oleh admin.');
            }
            $active = (bool) ($d['active'] ?? true);
            if ($active) {
                $roster->capacity((int) $d['session_id'], 1, $id);
            }
            $before = ['name' => $st->name, 'sim_class' => $e->sim_class, 'class_category' => $e->class_category, 'session_id' => $m->session_id, 'supervisor_id' => $e->supervisor_id, 'active' => (bool) $e->active];
            $supervisor = $d['supervisor_id'] ?? $e->supervisor_id;
            if (! empty($d['clear_supervisor'])) {
                $supervisor = null;
            }
            if ($st->name !== $d['name']) {
                DB::table('students')->where('id', $st->id)->update(['name' => $d['name'], 'version' => $st->version + 1, 'updated_at' => now()]);
            }
            $save = ['sim_class' => $d['sim_class'], 'class_category' => $d['class_category'], 'supervisor_id' => $supervisor, 'active' => $active, 'version' => $e->version + 1, 'updated_at' => now()];
            DB::table('enrollments')->where('id', $id)->update($save);
            if ($m->session_id != (int) $d['session_id']) {
                abort_if($d['effective_date'] < $m->valid_from, 422);
                DB::table('session_memberships')->where('id', $m->id)->update(['valid_until' => $d['effective_date'], 'updated_at' => now()]);
                DB::table('session_memberships')->insert(['enrollment_id' => $id, 'session_id' => $d['session_id'], 'offering_id' => $offering, 'valid_from' => $d['effective_date'], 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('enrollment', $id, 'enrollment.updated', $before, [...$save, 'name' => $d['name'], 'session_id' => $d['session_id']], ($d['reason'] ?? null), $offering);
        });

        return redirect()->route('students.index', $offering)->with('success', 'Data praktikan berhasil disimpan.');
    }

    public function supervisor(Request $r, int $offering, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $d = $r->validate(['name' => 'required|string|max:150', 'identity_code' => 'nullable|string|max:80|unique:supervisors,identity_code', 'distinct_person' => 'sometimes|accepted']);
        DB::transaction(function () use ($d, $offering, $roster) {
            $roster->writable($offering);
            $matches = DB::table('supervisors')->where('name', $d['name'])->get();
            if ($matches->count() && ! (! empty($d['identity_code']) && ! empty($d['distinct_person']))) {
                throw ValidationException::withMessages(['name' => 'Nama sudah tersedia. Gunakan master yang sama, atau isi kode identitas berbeda dan konfirmasi dosen berbeda.']);
            }$id = DB::table('supervisors')->insertGetId(['name' => $d['name'], 'identity_code' => $d['identity_code'] ?? null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('supervisor', $id, 'supervisor.created', null, $d, null, $offering);
        });

        return back()->with('success', 'Dosen pembimbing ditambahkan.');
    }

    public function bulkPreview(Request $r, int $offering, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $d = $r->validate(['selection_mode' => 'required|in:selected,filtered', 'selected' => 'required_if:selection_mode,selected|array|max:2000', 'selected.*' => 'integer|distinct', 'filters' => 'nullable|array:q,session_id,class_category,supervisor_id,active,per_page', 'supervisor_id' => ['required', 'integer', Rule::exists('supervisors', 'id')->where('active', true)], 'mode' => 'required|in:empty,replace', 'reason' => 'required_if:mode,replace|nullable|string|min:5|max:1000']);
        $filters = validator($d['filters'] ?? [], self::FILTERS)->validate();
        $q = $roster->query($r->user(), $offering);
        if ($d['selection_mode'] === 'filtered') {
            $roster->filters($q, $filters);
        } else {
            $q->whereIn('e.id', $d['selected']);
        }
        $rows = $q->orderBy('e.id')->limit(2001)->get();
        if ($d['selection_mode'] === 'selected') {
            abort_unless($rows->count() === count($d['selected']), 403);
        }
        if (! $rows->count() || $rows->count() > 2000) {
            throw ValidationException::withMessages(['selected' => 'Pilih 1–2000 praktikan dalam lingkup akses.']);
        }
        $payload = ['type' => 'supervisor', 'rows' => $rows->map(fn ($e) => ['id' => $e->id, 'version' => $e->version, 'nbi' => $e->nbi, 'name' => $e->name, 'supervisor_id' => $e->supervisor_id])->all(), 'supervisor_id' => $d['supervisor_id'], 'mode' => $d['mode'], 'reason' => $d['reason'] ?? null, 'selection_mode' => $d['selection_mode']];
        $token = (string) Str::uuid();
        DB::table('import_previews')->insert(['id' => $token, 'user_id' => $r->user()->id, 'offering_id' => $offering, 'payload' => Crypt::encryptString(json_encode($payload)), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);

        return view('students.bulk', ['o' => $roster->offering($offering), 'payload' => $payload, 'token' => $token, 'supervisor' => DB::table('supervisors')->find($d['supervisor_id'])]);
    }

    public function bulkCommit(Request $r, int $offering, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $r->validate(['token' => 'required|uuid', 'confirmed' => 'required|accepted']);
        $result = DB::transaction(function () use ($r, $roster, $offering) {
            $roster->writable($offering);
            $p = DB::table('import_previews')->where('id', $r->token)->lockForUpdate()->first();
            abort_unless($p && $p->user_id === $r->user()->id && $p->offering_id === $offering, 403);
            abort_if($p->committed_at || now()->greaterThan($p->expires_at), 409, 'Pratinjau kedaluwarsa atau sudah dipakai.');
            $d = json_decode(Crypt::decryptString($p->payload), true);
            abort_unless($d['type'] === 'supervisor', 422);
            if (! DB::table('supervisors')->where('id', $d['supervisor_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['supervisor_id' => 'Dosen tidak aktif. Buat pratinjau baru.']);
            }
            $ids = array_column($d['rows'], 'id');
            DB::table('enrollments')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $rows = $roster->query($r->user(), $offering)->whereIn('e.id', $ids)->get()->keyBy('id');
            abort_unless($rows->count() === count($ids), 403);
            foreach ($d['rows'] as $e) {
                abort_if($rows[$e['id']]->version !== $e['version'], 409, 'Data praktikan berubah. Buat pratinjau baru.');
            }
            $changed = 0;
            $skipped = 0;
            $batch = (string) Str::uuid();
            foreach ($d['rows'] as $e) {
                if (($d['mode'] === 'empty' && $e['supervisor_id'] !== null) || $e['supervisor_id'] === $d['supervisor_id']) {
                    $skipped++;

                    continue;
                }$new = ['supervisor_id' => $d['supervisor_id']];
                DB::table('enrollments')->where('id', $e['id'])->update([...$new, 'version' => $e['version'] + 1, 'updated_at' => now()]);
                Audit::record('enrollment', $e['id'], 'supervisor.assigned', ['supervisor_id' => $e['supervisor_id']], $new, ($d['reason'] ?? null), $offering, $batch);
                $changed++;
            }
            DB::table('import_previews')->where('id', $p->id)->update(['committed_at' => now(), 'updated_at' => now()]);

            return compact('changed', 'skipped');
        });

        return redirect()->route('students.index', $offering)->with('success', "Dosen ditetapkan: {$result['changed']} diperbarui, {$result['skipped']} dilewati.");
    }

    public function export(Request $r, int $offering, Roster $roster)
    {
        $f = $r->validate([...self::FILTERS, 'format' => 'nullable|in:csv,xlsx']);
        $q = $roster->filters($roster->query($r->user(), $offering, 'reports.export'), $f)->orderBy('st.nbi');
        $headers = ['No', 'NBI', 'Nama', 'Simpraktikum', 'Kelas', 'Sesi', 'Dosen Pembimbing'];
        if (($f['format'] ?? 'csv') === 'xlsx') {
            return response()->streamDownload(function () use ($q, $headers) {
                $file = tempnam(sys_get_temp_dir(), 'portal-export-');
                $writer = new Writer;
                try {
                    $writer->openToFile($file);
                    $writer->addRow(new Row(array_map(fn ($v) => new StringCell($v), $headers)));
                    $i = 0;
                    foreach ($q->cursor() as $e) {
                        $values = [(string) ++$i, $e->nbi, $e->name, $e->sim_class, $e->class_category, $e->session_label, $e->identity_code ?? $e->supervisor_name ?? ''];
                        $writer->addRow(new Row(array_map(fn ($v) => new StringCell(preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', (string) $v) ? "'".$v : (string) $v), $values)));
                    }
                    $writer->close();
                    readfile($file);
                } finally {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
            }, 'praktikan.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
        }

        return response()->streamDownload(function () use ($q, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            $i = 0;
            foreach ($q->cursor() as $e) {
                $row = [++$i, $e->nbi, $e->name, $e->sim_class, $e->class_category, $e->session_label, $e->identity_code ?? $e->supervisor_name ?? ''];
                $row = array_map(fn ($v) => preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', (string) $v) ? "'".$v : $v, $row);
                fputcsv($out, $row, ',', '"', '');
            }fclose($out);
        }, 'praktikan.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
