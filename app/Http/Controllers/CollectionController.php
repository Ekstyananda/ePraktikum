<?php

namespace App\Http\Controllers;

use App\Services\Assessment;
use App\Services\Roster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pengumpulan per pertemuan (tabs Cetak/Digital) and Laporan Akhir overview.
 * Grouping follows each session's collection schedule, so Pendahuluan n and Aktivitas n-1 appear together at meeting n.
 */
class CollectionController
{
    public const PER_PAGE = [10, 25, 50, 100];

    private function scopedSessions(Request $r, int $o, Roster $roster)
    {
        $roster->require($r->user(), $o, 'submissions.manage');

        return $roster->access->sessions($r->user(), $o)->filter(fn ($s) => $roster->access->allowed($r->user(), $o, 'submissions.manage', $s->id))->values();
    }

    private function currentMembers(string $today)
    {
        return fn ($j) => $j->on('m.enrollment_id', '=', 'e.id')->where('m.valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('m.valid_until')->orWhere('m.valid_until', '>', $today));
    }

    public function index(Request $r, int $offering, Roster $roster)
    {
        $sessions = $this->scopedSessions($r, $offering, $roster);
        $d = $r->validate(['mode' => 'nullable|in:print,digital', 'meeting_id' => 'nullable|integer', 'session_id' => 'nullable|integer', 'q' => 'nullable|string|max:100', 'per_page' => ['nullable', Rule::in(self::PER_PAGE)]]);
        $mode = $d['mode'] ?? 'print';
        $ids = $sessions->pluck('id');
        if (! empty($d['session_id'])) {
            abort_unless($ids->contains((int) $d['session_id']), 403);
            $ids = collect([(int) $d['session_id']]);
        }
        $meetings = DB::table('meetings')->where('offering_id', $offering)->orderBy('number')->get(['id', 'number', 'title']);
        // Default: the most recent meeting that has started in scope, otherwise the first meeting.
        $meetingId = (int) ($d['meeting_id'] ?? DB::table('session_meetings')->where('offering_id', $offering)->whereIn('session_id', $ids)->where('starts_at', '<=', now())->orderByDesc('starts_at')->value('meeting_id') ?? $meetings->first()?->id);
        $meeting = $meetings->firstWhere('id', $meetingId);
        $today = now('Asia/Jakarta')->toDateString();

        $q = DB::table('assignment_schedules as sc')->join('session_meetings as sm', 'sm.id', '=', 'sc.collection_session_meeting_id')
            ->join('assignments as a', 'a.id', '=', 'sc.assignment_id')->join('practicum_sessions as ps', 'ps.id', '=', 'sc.session_id')
            ->join('session_memberships as m', fn ($j) => $j->on('m.session_id', '=', 'sc.session_id')->where('m.valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('m.valid_until')->orWhere('m.valid_until', '>', $today)))
            ->join('enrollments as e', fn ($j) => $j->on('e.id', '=', 'm.enrollment_id')->where('e.active', true))->join('students as st', 'st.id', '=', 'e.student_id')
            ->leftJoin('submissions as sub', fn ($j) => $j->on('sub.assignment_id', '=', 'a.id')->on('sub.enrollment_id', '=', 'e.id'))
            ->leftJoin('grading_components as gc', 'gc.assignment_id', '=', 'a.id')
            ->leftJoin('grades as g', fn ($j) => $j->on('g.component_id', '=', 'gc.id')->on('g.enrollment_id', '=', 'e.id'))
            ->where('sc.offering_id', $offering)->where('sm.meeting_id', $meetingId)->whereIn('sc.session_id', $ids)->where('a.mode', $mode)->where('a.active', true)
            ->when(! empty($d['q']), fn ($q) => $q->where(fn ($q) => $q->where('st.nbi', 'like', '%'.$d['q'].'%')->orWhere('st.name', 'like', '%'.$d['q'].'%')));
        $summary = (clone $q)->selectRaw('count(*) as total, sum(sub.id is not null) as received, count(distinct a.id) as tasks')->first();
        $rows = $q->select('e.id as enrollment_id', 'st.nbi', 'st.name', 'ps.label as session_label', 'a.id as assignment_id', 'a.title', 'a.type', 'sub.status', 'sub.received_at', 'sub.is_late', 'g.score', 'g.status as grade_status', 'gc.max_score')
            ->orderBy('ps.label')->orderBy('st.nbi')->orderByRaw("FIELD(a.type, 'pendahuluan', 'aktivitas', 'lab', 'final', 'custom')")->orderBy('a.id')
            ->paginate((int) ($d['per_page'] ?? 25))->withQueryString();

        return view('collections.index', ['o' => $roster->offering($offering), 'mode' => $mode, 'meetings' => $meetings, 'meeting' => $meeting, 'sessions' => $sessions, 'rows' => $rows, 'summary' => $summary,
            'states' => $mode === 'print' ? Assessment::PRINT_STATES : Assessment::DIGITAL_STATES]);
    }

    public function finalReports(Request $r, int $offering, Roster $roster)
    {
        $sessions = $this->scopedSessions($r, $offering, $roster);
        $d = $r->validate(['assignment_id' => 'nullable|integer', 'session_id' => 'nullable|integer', 'q' => 'nullable|string|max:100', 'per_page' => ['nullable', Rule::in(self::PER_PAGE)]]);
        $finals = DB::table('assignments')->where('offering_id', $offering)->where('type', 'final')->where('active', true)->orderBy('id')->get(['id', 'title', 'mode']);
        $assignment = $finals->firstWhere('id', (int) ($d['assignment_id'] ?? 0)) ?? $finals->first();
        $rows = null;
        $items = 0;
        if ($assignment) {
            $ids = $sessions->pluck('id');
            if (! empty($d['session_id'])) {
                abort_unless($ids->contains((int) $d['session_id']), 403);
                $ids = collect([(int) $d['session_id']]);
            }
            $items = DB::table('report_checklist_items')->where('assignment_id', $assignment->id)->count();
            $checked = DB::table('report_checklist_results')->where('completed', true)->groupBy('submission_id')->selectRaw('submission_id, count(*) as n');
            $rows = DB::table('enrollments as e')->join('students as st', 'st.id', '=', 'e.student_id')
                ->join('session_memberships as m', $this->currentMembers(now('Asia/Jakarta')->toDateString()))->join('practicum_sessions as ps', 'ps.id', '=', 'm.session_id')
                ->leftJoin('submissions as sub', fn ($j) => $j->on('sub.enrollment_id', '=', 'e.id')->where('sub.assignment_id', $assignment->id))
                ->leftJoinSub($checked, 'c', 'c.submission_id', '=', 'sub.id')
                ->where('e.offering_id', $offering)->where('e.active', true)->whereIn('m.session_id', $ids)
                ->when(! empty($d['q']), fn ($q) => $q->where(fn ($q) => $q->where('st.nbi', 'like', '%'.$d['q'].'%')->orWhere('st.name', 'like', '%'.$d['q'].'%')))
                ->select('e.id', 'st.nbi', 'st.name', 'ps.label as session_label', 'sub.status', 'sub.received_at', 'sub.is_late', 'c.n as checked')
                ->orderBy('ps.label')->orderBy('st.nbi')->paginate((int) ($d['per_page'] ?? 25))->withQueryString();
        }

        return view('collections.final', ['o' => $roster->offering($offering), 'finals' => $finals, 'assignment' => $assignment, 'rows' => $rows, 'items' => $items, 'sessions' => $sessions,
            'states' => Assessment::PRINT_STATES + Assessment::DIGITAL_STATES]);
    }
}
