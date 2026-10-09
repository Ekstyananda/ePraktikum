<?php

namespace App\Http\Controllers;

use App\Services\Assessment;
use App\Services\Audit;
use App\Services\PrivateFiles;
use App\Services\PublicWorkflow;
use App\Services\RequestApproval;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Staff review of izin, pindah sesi, susulan and remidi requests. */
class RequestController
{
    public function index(Request $r, int $offering, PublicWorkflow $s)
    {
        $s->roster->require($r->user(), $offering, 'requests.manage');
        $d = $r->validate(['type' => ['nullable', Rule::in(array_keys(PublicWorkflow::TYPES))], 'status' => ['nullable', Rule::in(array_keys(PublicWorkflow::STATES))], 'q' => 'nullable|string|max:100']);
        $base = $s->scoped($r->user(), $offering);
        $counts = (clone $base)->when(! empty($d['type']), fn ($q) => $q->where('academic_requests.type', $d['type']))
            ->groupBy('academic_requests.status')->selectRaw('academic_requests.status, count(*) as n')->pluck('n', 'status');
        $q = (clone $base)->join('enrollments as e', 'e.id', '=', 'academic_requests.enrollment_id')->join('students as st', 'st.id', '=', 'e.student_id')
            ->leftJoin('practicum_sessions as src', 'src.id', '=', 'academic_requests.source_session_id')->leftJoin('practicum_sessions as dst', 'dst.id', '=', 'academic_requests.target_session_id');
        foreach (['type', 'status'] as $key) {
            if (! empty($d[$key])) {
                $q->where('academic_requests.'.$key, $d[$key]);
            }
        }
        if (! empty($d['q'])) {
            $q->where(fn ($q) => $q->where('st.nbi', 'like', '%'.$d['q'].'%')->orWhere('st.name', 'like', '%'.$d['q'].'%'));
        }
        $rows = $q->select('academic_requests.id', 'academic_requests.type', 'academic_requests.status', 'academic_requests.created_at', 'academic_requests.effective_date', 'st.nbi', 'st.name', 'src.label as source_label', 'dst.label as target_label')
            ->orderByRaw("academic_requests.status = 'pending' desc")->orderByDesc('academic_requests.id')->paginate(PerPage::from($r))->withQueryString();

        return view('requests.index', ['o' => $s->roster->offering($offering), 'rows' => $rows, 'counts' => $counts, 'canSettings' => $s->roster->access->allowed($r->user(), $offering, 'requests.manage')]);
    }

    private function row(Request $r, int $o, int $id, PublicWorkflow $s, bool $lock = false): object
    {
        $q = $s->scoped($r->user(), $o)->where('academic_requests.id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $row = $q->first();
        abort_unless($row, 403);

        return $row;
    }

    public function show(Request $r, int $offering, int $id, PublicWorkflow $s)
    {
        $row = $this->row($r, $offering, $id, $s);
        $e = DB::table('enrollments as e')->join('students as st', 'st.id', '=', 'e.student_id')->where('e.id', $row->enrollment_id)->select('st.nbi', 'st.name', 'e.sim_class', 'e.class_category')->firstOrFail();
        $executions = DB::table('session_meetings as sm')->join('meetings as m', 'm.id', '=', 'sm.meeting_id')->join('practicum_sessions as ps', 'ps.id', '=', 'sm.session_id')
            ->whereIn('sm.id', array_filter([$row->source_execution_id, $row->target_execution_id]))->select('sm.*', 'm.number', 'm.title', 'ps.label')->get()->keyBy('id');
        $target = $row->target_session_id ? DB::table('practicum_sessions')->where('id', $row->target_session_id)->first() : null;
        $capacity = null;
        if ($target && $row->status === 'pending') {
            $date = $row->type === 'temporary' ? $s->date($executions[$row->target_execution_id]->starts_at) : $row->effective_date;
            $capacity = ['used' => $s->capacityAt($target->id, $date, $row->type === 'temporary' ? $executions[$row->target_execution_id]->meeting_id : null), 'max' => $target->capacity];
        }
        $program = $row->program_id ? DB::table('remedial_programs as p')->join('grading_components as c', 'c.id', '=', 'p.component_id')->where('p.id', $row->program_id)->select('p.*', 'c.label as component', 'c.max_score')->first() : null;
        $currentGrade = $program ? DB::table('grades')->where('enrollment_id', $row->enrollment_id)->where('component_id', $program->component_id)->first() : null;

        return view('requests.show', [
            'o' => $s->roster->offering($offering), 'row' => $row, 'e' => $e, 'executions' => $executions, 'target' => $target, 'capacity' => $capacity, 'program' => $program, 'currentGrade' => $currentGrade,
            'source' => DB::table('practicum_sessions')->where('id', $row->source_session_id)->first(),
            'results' => DB::table('request_results as rr')->join('users as u', 'u.id', '=', 'rr.evaluator_id')->where('rr.request_id', $id)->orderByDesc('rr.version')->select('rr.*', 'u.name as evaluator')->get(),
            'decider' => $row->decision_by ? DB::table('users')->where('id', $row->decision_by)->value('name') : null,
        ]);
    }

    public function decide(Request $r, int $offering, int $id, PublicWorkflow $s, RequestApproval $approval)
    {
        $this->row($r, $offering, $id, $s);
        $d = $r->validate(['version' => 'required|integer', 'decision' => 'required|in:approved,rejected', 'reason' => 'required_if:decision,rejected|nullable|string|min:5|max:2000', 'confirmed' => 'required|accepted', 'scheduled_at' => 'nullable|date_format:Y-m-d\TH:i', 'room' => 'nullable|string|max:150', 'student_note' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($r, $offering, $id, $s, $approval, $d) {
            $s->roster->writable($offering);
            $row = $this->row($r, $offering, $id, $s, true);
            abort_unless($row->version === (int) $d['version'] && $row->status === 'pending', 409);
            $s->writable($offering, $row->enrollment_id);
            $v = ['status' => $d['decision'], 'decision_note' => ($d['reason'] ?? null) ?: null, 'student_note' => ($d['student_note'] ?? null) ?: null, 'decision_at' => now(), 'decision_by' => $r->user()->id, 'version' => $row->version + 1, 'updated_at' => now()];
            if ($d['decision'] === 'approved') {
                $approval->approve($row, $v);
                if ($row->type === 'susulan') {
                    if (empty($d['scheduled_at']) || empty($d['room'])) {
                        throw ValidationException::withMessages(['scheduled_at' => 'Susulan membutuhkan jadwal dan ruangan.']);
                    }
                    $v['scheduled_at'] = $s->utc($d['scheduled_at']);
                    if (now()->gt($v['scheduled_at'])) {
                        throw ValidationException::withMessages(['scheduled_at' => 'Jadwal susulan harus di masa depan.']);
                    }
                    $v['room'] = $d['room'];
                }
            }
            DB::table('academic_requests')->where('id', $id)->update($v);
            $before = (array) $row;
            unset($before['token_hash']);
            Audit::record('academic_request', $id, 'request.decided', $before, $v, ($d['reason'] ?? null), $offering);
        }, 3);

        return back()->with('success', $d['decision'] === 'approved' ? 'Pengajuan disetujui. Persetujuan tidak otomatis mengubah presensi atau nilai awal.' : 'Pengajuan tidak disetujui. Keputusan tercatat.');
    }

    public function result(Request $r, int $offering, int $id, PublicWorkflow $s)
    {
        $this->row($r, $offering, $id, $s);
        $d = $r->validate(['version' => 'required|integer', 'performed_at' => 'required|date_format:Y-m-d\TH:i', 'attendance' => 'nullable|in:present,absent,excused,sick', 'score' => 'nullable|numeric|min:0|decimal:0,4', 'reason' => 'required|string|min:5|max:2000', 'confirmed' => 'required|accepted', 'student_note' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($r, $offering, $id, $s, $d) {
            $s->roster->writable($offering);
            $row = $this->row($r, $offering, $id, $s, true);
            $s->writable($offering, $row->enrollment_id);
            if (! in_array($row->type, ['susulan', 'remidi'], true) || ! in_array($row->status, ['approved', 'completed'], true)) {
                throw ValidationException::withMessages(['performed_at' => 'Hasil hanya untuk susulan/remidi yang disetujui.']);
            }
            abort_unless($row->version === (int) $d['version'], 409);
            $at = $s->utc($d['performed_at']);
            if (now()->lt($at)) {
                throw ValidationException::withMessages(['performed_at' => 'Hasil hanya dapat dicatat setelah pelaksanaan sebenarnya.']);
            }
            $v = ['request_id' => $id, 'version' => 1 + (int) DB::table('request_results')->where('request_id', $id)->max('version'), 'performed_at' => $at, 'note' => $d['reason'], 'evaluator_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()];
            if ($row->type === 'susulan') {
                $s->roster->require($r->user(), $offering, 'attendance.manage', $row->source_session_id);
                if (empty($d['attendance'])) {
                    throw ValidationException::withMessages(['attendance' => 'Status pelaksanaan susulan wajib.']);
                }
                $v['attendance'] = $d['attendance'];
            } else {
                $s->roster->require($r->user(), $offering, 'grades.manage', $row->source_session_id);
                $c = DB::table('remedial_programs as p')->join('grading_components as c', 'c.id', '=', 'p.component_id')->where('p.id', $row->program_id)->select('c.max_score')->firstOrFail();
                if (! isset($d['score']) || (float) $d['score'] > (float) $c->max_score) {
                    throw ValidationException::withMessages(['score' => 'Nilai remidi wajib dalam rentang maksimum komponen.']);
                }
                $v['score'] = $d['score'];
            }
            // Results are versioned rows; the original attendance/grade is never rewritten here.
            $rid = DB::table('request_results')->insertGetId($v);
            $update = ['status' => 'completed', 'version' => $row->version + 1, 'updated_at' => now()];
            if (filled($d['student_note'] ?? null)) {
                $update['student_note'] = $d['student_note'];
            }
            DB::table('academic_requests')->where('id', $id)->update($update);
            Audit::record('request_result', $rid, 'request.result', null, $v + array_intersect_key($update, ['student_note' => true]), ($d['reason'] ?? null), $offering);
        }, 3);

        return back()->with('success', 'Hasil aktual disimpan sebagai versi baru. Data asli tetap utuh.');
    }

    /**
     * Lost token: after the aslab has matched the student in person or through a known private channel,
     * replace the token. The old one stops working at once; the new one is shown only in this response.
     */
    public function reissue(Request $r, int $offering, int $id, PublicWorkflow $s)
    {
        $this->row($r, $offering, $id, $s);
        $d = $r->validate(['reason' => 'required|string|min:10|max:1000', 'verified' => 'required|accepted', 'method' => 'required|in:in_person,private_channel']);
        $token = $s->token();
        DB::transaction(function () use ($r, $offering, $id, $s, $d, $token) {
            $s->roster->writable($offering);
            $row = $this->row($r, $offering, $id, $s, true);
            DB::table('academic_requests')->where('id', $id)->update(['token_hash' => $s->hash($token), 'updated_at' => now()]);
            // The token itself is never audited.
            Audit::record('academic_request', $id, 'token.reissued', null, ['verification' => $d['method'], 'request_status' => $row->status], $d['reason'], $offering);
        }, 3);

        return $s->issuedToken($token, 'Pengajuan #'.$id, route('requests.show', [$offering, $id]), $s->roster->offering($offering));
    }

    public function download(Request $r, int $offering, int $id, PublicWorkflow $s, PrivateFiles $files)
    {
        $row = $this->row($r, $offering, $id, $s);
        abort_unless($row->evidence_file_id, 404);

        return $files->download($row->evidence_file_id);
    }

    public function settings(Request $r, int $offering, PublicWorkflow $s)
    {
        app(Assessment::class)->shared($r->user(), $offering, 'requests.manage');

        return view('requests.settings', [
            'o' => $s->roster->offering($offering), 'window' => DB::table('request_windows')->where('offering_id', $offering)->first(),
            'programs' => DB::table('remedial_programs as p')->join('grading_components as c', 'c.id', '=', 'p.component_id')->where('p.offering_id', $offering)->select('p.*', 'c.label as component')->orderByDesc('p.id')->get(),
            'components' => DB::table('grading_components')->where('offering_id', $offering)->where('active', true)->orderBy('label')->get(),
            'used' => DB::table('academic_requests')->where('offering_id', $offering)->whereNotNull('program_id')->distinct()->pluck('program_id')->all(),
        ]);
    }

    public function windowSave(Request $r, int $offering, PublicWorkflow $s)
    {
        app(Assessment::class)->shared($r->user(), $offering, 'requests.manage');
        $d = $r->validate(['opens_at' => 'required|date_format:Y-m-d\TH:i', 'closes_at' => 'required|date_format:Y-m-d\TH:i|after:opens_at', 'instructions' => 'required|string|max:5000', 'version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $offering, $s, $d) {
            $s->roster->writable($offering);
            app(Assessment::class)->shared($r->user(), $offering, 'requests.manage');
            $old = DB::table('request_windows')->where('offering_id', $offering)->lockForUpdate()->first();
            abort_unless(($old?->version ?? 0) === (int) $d['version'], 409);
            $v = ['opens_at' => $s->utc($d['opens_at']), 'closes_at' => $s->utc($d['closes_at']), 'instructions' => $d['instructions'], 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
            DB::table('request_windows')->updateOrInsert(['offering_id' => $offering], $old ? $v : $v + ['created_at' => now()]);
            Audit::record('request_window', $offering, 'window.saved', $old ? (array) $old : null, $v, ($d['reason'] ?? null), $offering);
        });

        return back()->with('success', 'Periode pengajuan disimpan.');
    }

    public function programSave(Request $r, int $offering, PublicWorkflow $s)
    {
        app(Assessment::class)->shared($r->user(), $offering, 'requests.manage');
        $d = $r->validate([
            'id' => 'nullable|integer', 'version' => 'required|integer|min:0', 'component_id' => 'required|integer', 'title' => 'required|string|max:180', 'instructions' => 'required|string|max:5000',
            'eligibility_rule' => 'required|string|min:5|max:2000', 'opens_at' => 'required|date_format:Y-m-d\TH:i', 'closes_at' => 'required|date_format:Y-m-d\TH:i|after:opens_at',
            'scheduled_at' => 'required|date_format:Y-m-d\TH:i', 'room' => 'required|string|max:150', 'requires_file' => 'required|boolean', 'active' => 'required|boolean',
            'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted',
        ]);
        DB::transaction(function () use ($r, $offering, $s, $d) {
            $s->roster->writable($offering);
            app(Assessment::class)->shared($r->user(), $offering, 'requests.manage');
            if (! DB::table('grading_components')->where('id', $d['component_id'])->where('offering_id', $offering)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['component_id' => 'Pilih komponen nilai aktif pada praktikum ini.']);
            }
            $old = empty($d['id']) ? null : DB::table('remedial_programs')->where('id', $d['id'])->where('offering_id', $offering)->lockForUpdate()->firstOrFail();
            abort_unless(($old?->version ?? 0) === (int) $d['version'], 409);
            $used = $old && DB::table('academic_requests')->where('program_id', $old->id)->exists();
            $v = array_diff_key($d, array_flip(['id', 'version', 'reason', 'confirmed']));
            foreach (['opens_at', 'closes_at', 'scheduled_at'] as $key) {
                $v[$key] = $s->utc($d[$key]);
            }
            // A program with requests is frozen except for closing it, so earlier requests keep their terms.
            if ($used && array_diff_key(array_diff_assoc(array_map('strval', $v), array_map('strval', (array) $old)), ['active' => 1])) {
                throw ValidationException::withMessages(['title' => 'Program sudah dipakai pengajuan; hanya status aktif yang dapat diubah. Buat program baru untuk ketentuan berbeda.']);
            }
            $v += ['offering_id' => $offering, 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                $id = $old->id;
                DB::table('remedial_programs')->where('id', $id)->update($v);
            } else {
                $id = DB::table('remedial_programs')->insertGetId($v + ['created_at' => now()]);
            }
            Audit::record('remedial_program', $id, 'program.saved', $old ? (array) $old : null, $v, ($d['reason'] ?? null), $offering);
        });

        return back()->with('success', 'Program remidi disimpan; kelayakan diperiksa per pengajuan.');
    }
}
