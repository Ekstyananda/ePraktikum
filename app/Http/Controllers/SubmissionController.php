<?php

namespace App\Http\Controllers;

use App\Services\Assessment;
use App\Services\Audit;
use App\Services\PrivateFiles;
use App\Support\PerPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubmissionController
{
    public function index(Request $r, int $offering, int $assignment, Assessment $s)
    {
        $a = $s->assignment($offering, $assignment);
        abort_if($a->mode === 'direct', 422);
        $f = $r->validate(['q' => 'nullable|string|max:100', 'session_id' => 'nullable|integer']);
        $q = $s->roster->filters($s->roster->query($r->user(), $offering, 'submissions.manage'), $f);
        $q->leftJoin('submissions as sub', fn ($j) => $j->on('sub.enrollment_id', '=', 'e.id')->where('sub.assignment_id', $assignment))->addSelect('sub.status as receipt_status', 'sub.received_at', 'sub.recorded_at', 'sub.is_late');

        $sessions = $s->roster->access->sessions($r->user(), $offering)->filter(fn ($session) => $s->roster->access->allowed($r->user(), $offering, 'submissions.manage', $session->id))->pluck('id');
        $q->where(fn ($query) => $query->whereNull('sub.id')->orWhereIn('sub.schedule_id', DB::table('assignment_schedules')->where('offering_id', $offering)->whereIn('session_id', $sessions)->select('id')));

        return view('submissions.index', ['o' => $s->roster->offering($offering), 'a' => $a, 'rows' => $q->orderBy('st.nbi')->paginate(PerPage::from($r))->withQueryString()]);
    }

    public function form(Request $r, int $offering, int $assignment, int $enrollment, Assessment $s)
    {
        $e = $s->enrollment($r->user(), $offering, $enrollment, 'submissions.manage');
        $a = $s->assignment($offering, $assignment);
        abort_if($a->mode === 'direct', 422);
        $sub = DB::table('submissions')->where('assignment_id', $assignment)->where('enrollment_id', $enrollment)->first();
        $schedule = $sub ? DB::table('assignment_schedules')->where('id', $sub->schedule_id)->first() : DB::table('assignment_schedules')->where('assignment_id', $assignment)->where('session_id', $e->session_id)->first();
        if ($schedule) {
            $s->roster->require($r->user(), $offering, 'submissions.manage', $schedule->session_id);
        }

        return view('submissions.form', ['o' => $s->roster->offering($offering), 'a' => $a, 'e' => $e, 'sub' => $sub, 'schedule' => $schedule, 'history' => $sub ? DB::table('activity_logs')->where('entity_type', 'submission')->where('entity_id', $sub->id)->where('action', 'submission.saved')->orderByDesc('id')->get() : collect(), 'states' => $a->mode === 'print' ? Assessment::PRINT_STATES : Assessment::DIGITAL_STATES, 'versions' => $sub ? DB::table('submission_versions as v')->join('files as f', 'f.id', '=', 'v.file_id')->where('v.submission_id', $sub->id)->select('v.*', 'f.original_name')->orderByDesc('v.version_number')->get() : collect(), 'items' => DB::table('report_checklist_items')->where('assignment_id', $assignment)->orderBy('sort_order')->get(), 'checked' => $sub ? DB::table('report_checklist_results')->where('submission_id', $sub->id)->where('completed', true)->pluck('item_id')->all() : [], 'finalized' => DB::table('final_results')->where('enrollment_id', $enrollment)->whereNull('superseded_at')->exists()]);
    }

    public function save(Request $r, int $offering, int $assignment, int $enrollment, Assessment $s, PrivateFiles $files)
    {
        $s->enrollment($r->user(), $offering, $enrollment, 'submissions.manage');
        $a = $s->assignment($offering, $assignment);
        abort_if($a->mode === 'direct', 422);
        $states = $a->mode === 'print' ? Assessment::PRINT_STATES : Assessment::DIGITAL_STATES;
        $d = $r->validate(['version' => 'required|integer|min:0', 'status' => ['required', Rule::in(array_keys($states))], 'received_at' => 'nullable|date_format:Y-m-d\TH:i', 'note' => 'nullable|string|max:2000', 'student_note' => 'nullable|string|max:1000', 'reason' => 'nullable|string|min:5|max:1000', 'late_decision' => 'required|in:pending,accepted', 'file' => 'nullable|file|extensions:pdf,docx,zip,txt,sql|mimes:pdf,docx,zip,txt,sql|max:'.config('academic.upload_max_kb')]);
        $file = $r->hasFile('file') ? $files->stage($r->file('file')) : null;
        try {
            DB::transaction(function () use ($r, $offering, $assignment, $enrollment, $s, $a, $d, $file) {
                $s->writable($offering, $enrollment);
                $e = $s->enrollment($r->user(), $offering, $enrollment, 'submissions.manage');
                abort_unless($a->active && $e->active, 422);
                $old = DB::table('submissions')->where('assignment_id', $assignment)->where('enrollment_id', $enrollment)->lockForUpdate()->first();
                abort_if(($old?->version ?? 0) != (int) $d['version'], 409);
                if (($old || $d['status'] === 'missing_decided' || $d['late_decision'] === 'accepted') && ! filled($d['reason'] ?? null)) {
                    throw ValidationException::withMessages(['reason' => 'Perubahan atau keputusan eksplisit wajib alasan.']);
                }$schedule = $old ? DB::table('assignment_schedules')->where('id', $old->schedule_id)->first() : DB::table('assignment_schedules')->where('assignment_id', $assignment)->where('session_id', $e->session_id)->first();
                if (! $schedule) {
                    throw ValidationException::withMessages(['received_at' => 'Jadwal pengumpulan belum tersedia untuk sesi praktikan.']);
                }$s->roster->require($r->user(), $offering, 'submissions.manage', $schedule->session_id);
                $missing = $d['status'] === 'missing_decided';
                $at = empty($d['received_at']) ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $d['received_at'], 'Asia/Jakarta')->utc()->startOfMinute();
                if (! $missing && ! $at) {
                    throw ValidationException::withMessages(['received_at' => 'Waktu terima sebenarnya wajib diisi.']);
                }if ($at && $at->isFuture()) {
                    throw ValidationException::withMessages(['received_at' => 'Waktu terima tidak boleh di masa depan.']);
                }$late = $at && $at->greaterThan($schedule->due_at);
                if (! $missing && ($at->lessThan($schedule->opens_at) || $at->greaterThan($schedule->closes_at) || ($late && ! $schedule->allow_late))) {
                    throw ValidationException::withMessages(['received_at' => 'Penerimaan berada di luar periode atau izin terlambat.']);
                }if ($missing && ($file || $at)) {
                    throw ValidationException::withMessages(['received_at' => 'Keputusan tidak dikumpulkan tidak menerima waktu terima atau file.']);
                }
                if ($a->mode === 'print' && $file) {
                    throw ValidationException::withMessages(['file' => 'Penerimaan cetak tidak memakai attachment digital.']);
                }if ($a->mode === 'digital' && ! $missing) {
                    if (! $old && ! $file) {
                        throw ValidationException::withMessages(['file' => 'Pengumpulan digital pertama memerlukan file.']);
                    }if ($file && ! in_array($d['status'], ['submitted', 'revised'], true)) {
                        throw ValidationException::withMessages(['status' => 'File baru memakai status Dikirim/Revisi dikirim.']);
                    }if ($file && $old && ! in_array($old->status, ['revision_requested', 'missing_decided'], true)) {
                        throw ValidationException::withMessages(['file' => 'Versi ulang hanya setelah pengelola meminta revisi.']);
                    }if ($old && $old->status === 'revision_requested' && $d['status'] === 'revised' && ! $file) {
                        throw ValidationException::withMessages(['file' => 'Revisi digital memerlukan file baru.']);
                    }if (! $file && $old && $old->received_at !== $at->format('Y-m-d H:i:s')) {
                        throw ValidationException::withMessages(['received_at' => 'Tanpa versi file baru, waktu penerimaan digital tetap.']);
                    }
                }
                if ($old && ! $missing) {
                    $allowed = match ($old->status) {
                        'received','submitted','revised' => [$old->status, 'revision_requested', 'complete'],'revision_requested' => ['revision_requested', 'revised'],'complete' => ['complete', 'revision_requested'],'missing_decided' => [$a->mode === 'print' ? 'received' : 'submitted'],default => []
                    };
                    if (! in_array($d['status'], $allowed, true)) {
                        throw ValidationException::withMessages(['status' => 'Transisi status tidak sesuai. Minta revisi sebelum menerima revisi.']);
                    }
                } elseif (! $old && ! $missing && ! in_array($d['status'], [$a->mode === 'print' ? 'received' : 'submitted'], true)) {
                    throw ValidationException::withMessages(['status' => 'Mulai dari penerimaan/pengiriman pertama.']);
                }
                if ($a->type === 'final' && $d['status'] === 'complete') {
                    $items = DB::table('report_checklist_items')->where('assignment_id', $assignment)->count();
                    $done = $old ? DB::table('report_checklist_results')->where('submission_id', $old->id)->where('completed', true)->count() : 0;
                    if (! $items || $items !== $done) {
                        throw ValidationException::withMessages(['status' => 'Checklist laporan ini belum lengkap.']);
                    }
                }
                $values = ['status' => $d['status'], 'received_at' => $at?->format('Y-m-d H:i:s'), 'recorded_at' => now(), 'receiver_id' => $r->user()->id, 'is_late' => (bool) $late, 'late_decision' => $d['late_decision'], 'note' => $d['note'] ?? null, 'student_note' => ($d['student_note'] ?? null) ?: null, 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
                if ($old) {
                    $sid = $old->id;
                    DB::table('submissions')->where('id', $sid)->update($values);
                } else {
                    $sid = DB::table('submissions')->insertGetId($values + ['offering_id' => $offering, 'assignment_id' => $assignment, 'enrollment_id' => $enrollment, 'schedule_id' => $schedule->id, 'mode' => $a->mode, 'created_at' => now()]);
                }if ($file) {
                    DB::table('files')->insert($file);
                    $num = 1 + (int) DB::table('submission_versions')->where('submission_id', $sid)->max('version_number');
                    DB::table('submission_versions')->insert(['submission_id' => $sid, 'version_number' => $num, 'file_id' => $file['id'], 'submitted_at' => $at, 'recorded_at' => now(), 'recorded_by' => $r->user()->id, 'revision_note' => $d['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                }$values['file_id'] = $file['id'] ?? null;
                Audit::record('submission', $sid, 'submission.saved', $old ? (array) $old : null, $values, $d['reason'] ?? null, $offering);
            });
        } catch (\Throwable $error) {
            if ($file) {
                $files->discard($file);
            }throw $error;
        }

        return redirect()->route('submissions.form', [$offering, $assignment, $enrollment])->with('success', 'Penerimaan disimpan. Nilai dan status pemeriksaan tetap terpisah.');
    }

    public function checklist(Request $r, int $offering, int $assignment, int $enrollment, Assessment $s)
    {
        $s->enrollment($r->user(), $offering, $enrollment, 'submissions.manage');
        $d = $r->validate(['version' => 'required|integer', 'completed' => 'nullable|array', 'completed.*' => 'integer|distinct', 'reason' => 'nullable|string|min:5|max:1000']);
        DB::transaction(function () use ($r, $offering, $assignment, $enrollment, $s, $d) {
            $s->writable($offering, $enrollment);
            $a = $s->assignment($offering, $assignment);
            abort_unless($a->type === 'final', 422);
            $sub = DB::table('submissions')->where('assignment_id', $assignment)->where('enrollment_id', $enrollment)->lockForUpdate()->firstOrFail();
            $schedule = DB::table('assignment_schedules')->where('id', $sub->schedule_id)->firstOrFail();
            $s->roster->require($r->user(), $offering, 'submissions.manage', $schedule->session_id);
            abort_if($sub->version != (int) $d['version'], 409);
            abort_if(in_array($sub->status, ['complete', 'missing_decided']), 423);
            $items = DB::table('report_checklist_items')->where('assignment_id', $assignment)->pluck('id')->all();
            $selected = $d['completed'] ?? [];
            abort_if(count(array_diff($selected, $items)) > 0, 422);
            $old = DB::table('report_checklist_results')->where('submission_id', $sub->id)->get()->toArray();
            if ($old && ! filled($d['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'Koreksi checklist yang sudah tersimpan wajib alasan.']);
            }
            foreach ($items as $item) {
                DB::table('report_checklist_results')->updateOrInsert(['submission_id' => $sub->id, 'item_id' => $item], ['assignment_id' => $assignment, 'completed' => in_array($item, $selected), 'checked_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }DB::table('submissions')->where('id', $sub->id)->update(['version' => $sub->version + 1, 'updated_at' => now()]);
            Audit::record('submission', $sub->id, 'report.checklist', $old, ['completed' => $selected], ($d['reason'] ?? null), $offering);
        });

        return back()->with('success', 'Checklist laporan praktikan ini disimpan.');
    }

    public function download(Request $r, int $offering, int $assignment, int $enrollment, int $version, Assessment $s, PrivateFiles $files)
    {
        $s->enrollment($r->user(), $offering, $enrollment, 'submissions.manage');
        $sub = DB::table('submissions')->where('offering_id', $offering)->where('assignment_id', $assignment)->where('enrollment_id', $enrollment)->firstOrFail();
        $schedule = DB::table('assignment_schedules')->where('id', $sub->schedule_id)->firstOrFail();
        $s->roster->require($r->user(), $offering, 'submissions.manage', $schedule->session_id);
        $v = DB::table('submission_versions')->where('submission_id', $sub->id)->where('id', $version)->firstOrFail();

        return $files->download($v->file_id);
    }
}
