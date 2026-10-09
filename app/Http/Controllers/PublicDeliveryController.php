<?php

namespace App\Http\Controllers;

use App\Services\Assessment;
use App\Services\Audit;
use App\Services\PrivateFiles;
use App\Services\PublicWorkflow;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Staff review of digital files sent through the public portal; acceptance creates the M4 submission. */
class PublicDeliveryController
{
    private function query(Request $r, int $o, PublicWorkflow $s)
    {
        $s->roster->require($r->user(), $o, 'submissions.manage');
        $ids = $s->roster->access->sessions($r->user(), $o)->filter(fn ($x) => $s->roster->access->allowed($r->user(), $o, 'submissions.manage', $x->id))->pluck('id');

        return DB::table('public_deliveries as d')->join('assignment_schedules as sc', 'sc.id', '=', 'd.schedule_id')->where('d.offering_id', $o)->whereIn('sc.session_id', $ids)->select('d.*');
    }

    public function index(Request $r, int $offering, PublicWorkflow $s)
    {
        $s->roster->require($r->user(), $offering, 'submissions.manage');
        $d = $r->validate(['status' => 'nullable|in:pending,accepted,rejected']);
        $q = $this->query($r, $offering, $s)->join('enrollments as e', 'e.id', '=', 'd.enrollment_id')->join('students as st', 'st.id', '=', 'e.student_id')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')->join('files as f', 'f.id', '=', 'd.file_id')->join('practicum_sessions as ps', 'ps.id', '=', 'sc.session_id')
            ->addSelect('st.nbi', 'st.name', 'a.title', 'f.original_name', 'f.size', 'ps.label as session_label', 'sc.due_at');
        if (! empty($d['status'])) {
            $q->where('d.status', $d['status']);
        }

        return view('requests.deliveries', ['o' => $s->roster->offering($offering), 'rows' => $q->orderByRaw("d.status = 'pending' desc")->orderByDesc('d.id')->paginate(PerPage::from($r))->withQueryString()]);
    }

    public function download(Request $r, int $offering, int $id, PublicWorkflow $s, PrivateFiles $files)
    {
        $row = $this->query($r, $offering, $s)->where('d.id', $id)->first();
        abort_unless($row, 403);

        return $files->download($row->file_id);
    }

    public function decide(Request $r, int $offering, int $id, PublicWorkflow $s)
    {
        abort_unless($this->query($r, $offering, $s)->where('d.id', $id)->exists(), 403);
        $d = $r->validate(['version' => 'required|integer', 'decision' => 'required|in:accepted,rejected', 'reason' => 'required_if:decision,rejected|nullable|string|min:5|max:1000', 'student_note' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($r, $offering, $id, $s, $d) {
            $s->roster->writable($offering);
            $row = $this->query($r, $offering, $s)->where('d.id', $id)->lockForUpdate()->first();
            abort_unless($row, 403);
            $s->writable($offering, $row->enrollment_id);
            app(Assessment::class)->enrollment($r->user(), $offering, $row->enrollment_id, 'submissions.manage');
            abort_unless($row->status === 'pending' && $row->version === (int) $d['version'], 409);
            $sid = null;
            if ($d['decision'] === 'accepted') {
                if (DB::table('submissions')->where('assignment_id', $row->assignment_id)->where('enrollment_id', $row->enrollment_id)->exists()) {
                    throw ValidationException::withMessages(['decision' => 'Penerimaan sudah ada. Tolak kiriman duplikat; revisi wajib memakai token kiriman yang diterima.']);
                }
                $sc = DB::table('assignment_schedules')->where('id', $row->schedule_id)->firstOrFail();
                $sid = DB::table('submissions')->insertGetId(['offering_id' => $offering, 'assignment_id' => $row->assignment_id, 'enrollment_id' => $row->enrollment_id, 'schedule_id' => $row->schedule_id, 'mode' => 'digital', 'status' => 'submitted', 'received_at' => $row->received_at, 'recorded_at' => now(), 'receiver_id' => $r->user()->id, 'is_late' => $row->received_at > $sc->due_at, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('submission_versions')->insert(['submission_id' => $sid, 'version_number' => 1, 'file_id' => $row->file_id, 'submitted_at' => $row->received_at, 'recorded_at' => now(), 'recorded_by' => $r->user()->id, 'revision_note' => $d['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $v = ['status' => $d['decision'], 'submission_id' => $sid, 'student_note' => ($d['student_note'] ?? null) ?: null, 'decided_at' => now(), 'version' => $row->version + 1, 'updated_at' => now()];
            DB::table('public_deliveries')->where('id', $id)->update($v);
            Audit::record('public_delivery', $id, 'delivery.reviewed', ['status' => $row->status], $v, ($d['reason'] ?? null), $offering);
        }, 3);

        return back()->with('success', $d['decision'] === 'accepted' ? 'Kiriman diterima dan tercatat sebagai penerimaan digital versi 1.' : 'Kiriman ditolak. Keputusan tercatat.');
    }

    public function reissueForm(Request $r, int $offering, int $id, PublicWorkflow $s)
    {
        $row = $this->query($r, $offering, $s)->where('d.id', $id)->join('enrollments as e', 'e.id', '=', 'd.enrollment_id')->join('students as st', 'st.id', '=', 'e.student_id')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')->join('practicum_sessions as ps', 'ps.id', '=', 'sc.session_id')->addSelect('st.nbi', 'st.name', 'a.title', 'ps.label as session_label')->first();
        abort_unless($row, 403);

        return view('portal-token.delivery', ['o' => $s->roster->offering($offering), 'row' => $row]);
    }

    /** Lost token for a digital delivery (also used for revisions): same rules as requests. */
    public function reissue(Request $r, int $offering, int $id, PublicWorkflow $s)
    {
        abort_unless($this->query($r, $offering, $s)->where('d.id', $id)->exists(), 403);
        $d = $r->validate(['reason' => 'required|string|min:10|max:1000', 'verified' => 'required|accepted', 'method' => 'required|in:in_person,private_channel']);
        $token = $s->token();
        DB::transaction(function () use ($r, $offering, $id, $s, $d, $token) {
            $s->roster->writable($offering);
            $row = $this->query($r, $offering, $s)->where('d.id', $id)->lockForUpdate()->first();
            abort_unless($row, 403);
            DB::table('public_deliveries')->where('id', $id)->update(['token_hash' => $s->hash($token), 'updated_at' => now()]);
            Audit::record('public_delivery', $id, 'token.reissued', null, ['verification' => $d['method'], 'delivery_status' => $row->status], $d['reason'], $offering);
        }, 3);

        return $s->issuedToken($token, 'Kiriman digital #'.$id, route('deliveries.index', $offering), $s->roster->offering($offering));
    }
}
