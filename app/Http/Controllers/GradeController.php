<?php

namespace App\Http\Controllers;

use App\Services\Assessment;
use App\Services\Audit;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GradeController
{
    public function index(Request $r, int $offering, Assessment $s)
    {
        $s->roster->require($r->user(), $offering, 'grades.manage');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'session_id' => 'nullable|integer']);
        $rows = $s->roster->filters($s->roster->query($r->user(), $offering, 'grades.manage'), $f)->orderBy('st.nbi')->paginate(PerPage::from($r))->withQueryString();

        return view('grades.index', ['o' => $s->roster->offering($offering), 'rows' => $rows, 'components' => DB::table('grading_components')->where('offering_id', $offering)->where('active', true)->orderBy('id')->get(), 'grades' => DB::table('grades')->whereIn('enrollment_id', $rows->pluck('id'))->get()->groupBy('enrollment_id'), 'finals' => DB::table('final_results')->whereIn('enrollment_id', $rows->pluck('id'))->whereNull('superseded_at')->get()->keyBy('enrollment_id'), 'canRules' => $s->roster->access->allowed($r->user(), $offering, 'grading_rules.manage')]);
    }

    public function form(Request $r, int $offering, int $enrollment, Assessment $s)
    {
        $e = $s->enrollment($r->user(), $offering, $enrollment, 'grades.manage');

        return view('grades.form', ['o' => $s->roster->offering($offering), 'e' => $e, 'components' => DB::table('grading_components')->where('offering_id', $offering)->where('active', true)->orderBy('id')->get(), 'grades' => DB::table('grades')->where('enrollment_id', $enrollment)->get()->keyBy('component_id'), 'finalized' => DB::table('final_results')->where('enrollment_id', $enrollment)->whereNull('superseded_at')->exists()]);
    }

    public function save(Request $r, int $offering, int $enrollment, Assessment $s)
    {
        $s->enrollment($r->user(), $offering, $enrollment, 'grades.manage');
        $d = $r->validate(['selected' => 'required|array|min:1|max:200', 'selected.*' => 'integer|distinct', 'rows' => 'required|array|max:200', 'rows.*.version' => 'required|integer|min:0', 'rows.*.status' => 'required|in:ungraded,graded,missing_zero', 'rows.*.score' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,6}(\.\d{1,4})?$/'], 'rows.*.note' => 'nullable|string|max:1000', 'reason' => 'nullable|string|min:5|max:1000']);
        DB::transaction(function () use ($r, $offering, $enrollment, $s, $d) {
            $s->writable($offering, $enrollment);
            $s->enrollment($r->user(), $offering, $enrollment, 'grades.manage');
            $batch = (string) Str::uuid();
            foreach ($d['selected'] as $cid) {
                $c = DB::table('grading_components')->where('offering_id', $offering)->where('id', $cid)->where('active', true)->first();
                abort_unless($c, 403);
                $row = $d['rows'][$cid] ?? null;
                if (! $row) {
                    throw ValidationException::withMessages(['rows' => 'Data komponen terpilih wajib lengkap.']);
                }$old = DB::table('grades')->where('component_id', $cid)->where('enrollment_id', $enrollment)->lockForUpdate()->first();
                abort_if(($old?->version ?? 0) != (int) $row['version'], 409);
                $score = $row['score'] ?? null;
                if ($row['status'] === 'graded' && $score === null) {
                    throw ValidationException::withMessages(['rows.'.$cid.'.score' => 'Nilai kosong bukan nol. Isi nilai atau pilih Belum diperiksa.']);
                }if ($row['status'] === 'ungraded' && $score !== null) {
                    throw ValidationException::withMessages(['rows.'.$cid.'.score' => 'Belum diperiksa harus bernilai kosong.']);
                }if ($row['status'] === 'missing_zero') {
                    $score = 0;
                }if ($score !== null && (float) $score > (float) $c->max_score) {
                    throw ValidationException::withMessages(['rows.'.$cid.'.score' => 'Nilai melebihi maksimum komponen.']);
                }$note = $row['note'] ?? null;
                if ($old && $old->status === $row['status'] && ($old->score === null ? $score === null : (float) $old->score === (float) $score) && $old->note === $note) {
                    continue;
                }if (($old && ($old->status !== 'ungraded' || $old->note !== null)) || $row['status'] === 'missing_zero') {
                    if (! filled($d['reason'] ?? null)) {
                        throw ValidationException::withMessages(['reason' => 'Koreksi nilai atau keputusan nilai nol wajib alasan.']);
                    }
                }$values = ['status' => $row['status'], 'score' => $score, 'note' => $note, 'evaluator_id' => $r->user()->id, 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
                if ($old) {
                    $id = $old->id;
                    DB::table('grades')->where('id', $id)->update($values);
                } else {
                    $id = DB::table('grades')->insertGetId($values + ['offering_id' => $offering, 'component_id' => $cid, 'enrollment_id' => $enrollment, 'created_at' => now()]);
                }Audit::record('grade', $id, 'grade.saved', $old ? (array) $old : null, $values, $d['reason'] ?? null, $offering, $batch);
            }
        }, 3);

        return redirect()->route('grades.form', [$offering, $enrollment])->with('success', 'Komponen terpilih disimpan. Pengumpulan dan checklist tidak diubah.');
    }

    public function components(Request $r, int $offering, Assessment $s)
    {
        $s->shared($r->user(), $offering, 'grading_rules.manage');

        return view('grades.components', ['o' => $s->roster->offering($offering), 'rows' => DB::table('grading_components')->where('offering_id', $offering)->orderBy('id')->get(), 'assignments' => DB::table('assignments')->where('offering_id', $offering)->get()]);
    }

    public function componentSave(Request $r, int $offering, Assessment $s)
    {
        $s->shared($r->user(), $offering, 'grading_rules.manage');
        $d = $r->validate(['id' => 'nullable|integer', 'assignment_id' => ['required', 'integer', Rule::exists('assignments', 'id')->where('offering_id', $offering)], 'label' => 'required|string|max:180', 'weight' => 'nullable|numeric|between:0,100|decimal:0,4', 'max_score' => 'required|numeric|min:0.0001|max:999999|decimal:0,4', 'mandatory' => 'required|boolean', 'active' => 'required|boolean', 'version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($offering, $s, $d) {
            $s->writable($offering);
            $old = empty($d['id']) ? null : DB::table('grading_components')->where('id', $d['id'])->where('offering_id', $offering)->lockForUpdate()->firstOrFail();
            abort_if(($old?->version ?? 0) != (int) $d['version'], 409);
            if ($old && ($old->assignment_id != (int) $d['assignment_id'] || ((float) $old->max_score != (float) $d['max_score'] && DB::table('grades')->where('component_id', $old->id)->exists()))) {
                throw ValidationException::withMessages(['max_score' => 'Tugas tetap; maksimum nilai yang sudah digunakan tidak dapat diubah.']);
            }$values = array_intersect_key($d, array_flip(['assignment_id', 'label', 'weight', 'max_score', 'mandatory', 'active']));
            $values += ['version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                $id = $old->id;
                DB::table('grading_components')->where('id', $id)->update($values);
            } else {
                $id = DB::table('grading_components')->insertGetId($values + ['offering_id' => $offering, 'created_at' => now()]);
            }Audit::record('grading_component', $id, 'component.saved', $old ? (array) $old : null, $values, ($d['reason'] ?? null), $offering);
        });

        return back()->with('success', 'Komponen disimpan. Buat versi aturan baru bila konfigurasi berubah.');
    }

    public function rules(Request $r, int $offering, Assessment $s)
    {
        $s->shared($r->user(), $offering, 'grading_rules.manage');

        return view('grades.rules', ['o' => $s->roster->offering($offering), 'rules' => DB::table('grading_rules')->where('offering_id', $offering)->orderByDesc('version')->paginate(10), 'service' => $s]);
    }

    public function ruleStore(Request $r, int $offering, Assessment $s)
    {
        $s->shared($r->user(), $offering, 'grading_rules.manage');
        $d = $r->validate(['pass_score' => 'nullable|numeric|between:0,100', 'rounding' => 'nullable|in:half_up,floor,ceil', 'precision' => 'nullable|integer|between:0,4', 'bands' => 'nullable|string|max:2000', 'late_policy' => 'nullable|in:review,no_penalty', 'separate_activity5' => 'nullable|boolean', 'authority_note' => 'required|string|min:5|max:2000']);
        $bands = [];
        foreach (preg_split('/\R/', trim($d['bands'] ?? '')) as $line) {
            if (trim($line) === '') {
                continue;
            }if (! preg_match('/^([\pL0-9+_-]{1,20})\s*:\s*(\d+(?:\.\d{1,4})?)$/u', trim($line), $m) || (float) $m[2] > 100) {
                throw ValidationException::withMessages(['bands' => 'Tiap baris: label:batas bawah (0–100), misalnya sesuai aturan resmi Anda.']);
            }$bands[] = ['letter' => $m[1], 'minimum' => (float) $m[2]];
        }usort($bands, fn ($a, $b) => $b['minimum'] <=> $a['minimum']);
        if (count(array_unique(array_column($bands, 'letter'))) !== count($bands) || count(array_unique(array_column($bands, 'minimum'))) !== count($bands)) {
            throw ValidationException::withMessages(['bands' => 'Label dan batas bawah harus unik.']);
        }DB::transaction(function () use ($r, $offering, $s, $d, $bands) {
            $s->writable($offering);
            $config = ['pass_score' => $d['pass_score'] ?? null, 'rounding' => $d['rounding'] ?? null, 'precision' => $d['precision'] ?? null, 'bands' => $bands, 'late_policy' => $d['late_policy'] ?? null, 'separate_activity5' => (bool) ($d['separate_activity5'] ?? false), 'authority_note' => $d['authority_note'], 'components' => $s->fingerprint($offering)];
            $version = 1 + (int) DB::table('grading_rules')->where('offering_id', $offering)->max('version');
            $id = DB::table('grading_rules')->insertGetId(['offering_id' => $offering, 'version' => $version, 'config_json' => json_encode($config), 'status' => 'draft', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('grading_rule', $id, 'rules.drafted', null, $config, $d['authority_note'], $offering);
        });

        return back()->with('success', 'Versi draf disimpan. Belum digunakan untuk finalisasi.');
    }

    public function rulePublish(Request $r, int $offering, int $rule, Assessment $s)
    {
        $s->shared($r->user(), $offering, 'grading_rules.manage');
        $d = $r->validate(['confirmed' => 'required|accepted', 'reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($offering, $rule, $s, $d) {
            $s->writable($offering);
            $row = DB::table('grading_rules')->where('offering_id', $offering)->where('id', $rule)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'draft', 409);
            $s->fail($s->validateRules($offering, json_decode($row->config_json, true)));
            DB::table('grading_rules')->where('id', $rule)->update(['status' => 'published', 'published_at' => now(), 'updated_at' => now()]);
            Audit::record('grading_rule', $rule, 'rules.published', (array) $row, ['status' => 'published'], ($d['reason'] ?? null), $offering);
        });

        return back()->with('success', 'Aturan diterbitkan. Versi terbit terbaru digunakan untuk pratinjau finalisasi.');
    }

    private function digest(array $preview): string
    {
        return hash_hmac('sha256', json_encode($preview), config('app.key'));
    }

    public function finalForm(Request $r, int $offering, int $enrollment, Assessment $s)
    {
        $preview = $s->finalPreview($r->user(), $offering, $enrollment);

        return view('grades.final', ['o' => $s->roster->offering($offering), 'p' => $preview, 'digest' => $this->digest($preview), 'final' => DB::table('final_results')->where('enrollment_id', $enrollment)->whereNull('superseded_at')->first(), 'history' => DB::table('final_results as f')->leftJoin('users as u', 'u.id', '=', 'f.superseded_by')->where('f.enrollment_id', $enrollment)->whereNotNull('f.superseded_at')->orderByDesc('f.version')->select('f.*', 'u.name as reopened_by')->get()]);
    }

    public function finalize(Request $r, int $offering, int $enrollment, Assessment $s)
    {
        $s->enrollment($r->user(), $offering, $enrollment, 'grades.manage');
        $d = $r->validate(['digest' => 'required|string|size:64', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $offering, $enrollment, $s, $d) {
            $s->writable($offering, $enrollment);
            $p = $s->finalPreview($r->user(), $offering, $enrollment);
            $s->fail($p['errors']);
            abort_unless(hash_equals($this->digest($p), $d['digest']), 409);
            $snapshot = ['identity' => (array) $p['enrollment'], 'rules_version' => $p['rule']->version, 'rules' => $p['config'], 'components' => $p['details'], 'obligations' => $p['obligations']];
            $id = DB::table('final_results')->insertGetId(['offering_id' => $offering, 'enrollment_id' => $enrollment, 'version' => 1 + (int) DB::table('final_results')->where('enrollment_id', $enrollment)->max('version'), 'rules_id' => $p['rule']->id, 'score' => $p['score'], 'letter' => $p['letter'], 'decision' => $p['decision'], 'snapshot_json' => json_encode($snapshot), 'finalized_by' => $r->user()->id, 'finalized_at' => now()]);
            Audit::record('final_result', $id, 'grade.finalized', null, ['score' => $p['score'], 'letter' => $p['letter'], 'decision' => $p['decision'], 'rules_id' => $p['rule']->id], null, $offering);
        });

        return back()->with('success', 'Hasil final diarsipkan. Nilai dan pengumpulan praktikan ini menjadi read-only.');
    }

    /** Admin reopens a final archive for correction; the old version stays as read-only history. */
    public function reopen(Request $r, int $offering, int $enrollment, Assessment $s)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['final_id' => 'required|integer', 'reason' => 'required|string|min:10|max:1000', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $offering, $enrollment, $s, $d) {
            $s->roster->writable($offering);
            $final = DB::table('final_results')->where('offering_id', $offering)->where('enrollment_id', $enrollment)->whereNull('superseded_at')->lockForUpdate()->first();
            abort_unless($final && $final->id === (int) $d['final_id'], 409);
            $v = ['superseded_at' => now(), 'superseded_by' => $r->user()->id, 'supersede_reason' => $d['reason']];
            DB::table('final_results')->where('id', $final->id)->update($v);
            Audit::record('final_result', $final->id, 'grade.final_reopened', ['version' => $final->version, 'score' => $final->score, 'letter' => $final->letter, 'decision' => $final->decision], $v, ($d['reason'] ?? null), $offering);
        });

        return back()->with('success', 'Arsip final dibuka untuk koreksi. Versi lama tetap tersimpan; finalisasi ulang membuat versi baru.');
    }
}
