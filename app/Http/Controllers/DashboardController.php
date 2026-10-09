<?php

namespace App\Http\Controllers;

use App\Services\Roster;
use App\Services\StaffAccess;
use App\View\Composers\ManagerShell;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController
{
    public function index(Request $r, StaffAccess $access, Roster $roster)
    {
        $offerings = $access->offerings($r->user());
        if ($r->filled('praktikum')) {
            $chosen = $offerings->firstWhere('id', (int) $r->query('praktikum'));
            abort_unless($chosen, 403);
            $r->session()->put(ManagerShell::SESSION_KEY, $chosen->id);

            return redirect()->route('dashboard');
        }
        $current = $offerings->firstWhere('id', (int) $r->session()->get(ManagerShell::SESSION_KEY)) ?? $offerings->first();

        return view('dashboard', ['offerings' => $offerings, 'current' => $current, 'summary' => $current ? $this->summary($r, $current->id, $roster) : null]);
    }

    /** Counts are scoped to the sessions this user may manage for each permission. */
    private function summary(Request $r, int $o, Roster $roster): array
    {
        $user = $r->user();
        $access = $roster->access;
        $scoped = fn (string $key) => $access->sessions($user, $o)->filter(fn ($s) => $access->allowed($user, $o, $key, $s->id))->pluck('id');
        $today = now('Asia/Jakarta')->toDateString();
        $members = fn ($sessions) => DB::table('session_memberships')->where('offering_id', $o)->whereIn('session_id', $sessions)
            ->where('valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $today))->select('enrollment_id');

        $allSessions = $access->sessions($user, $o)->pluck('id');
        $students = DB::table('enrollments')->where('offering_id', $o)->where('active', true)->whereIn('id', $members($allSessions))->count();

        $submissionSessions = $scoped('submissions.manage');
        $submissions = DB::table('submissions')->where('offering_id', $o)->whereIn('enrollment_id', $members($submissionSessions));
        $toReview = (clone $submissions)->whereIn('status', ['received', 'submitted', 'revised'])->count();
        $revisions = (clone $submissions)->where('status', 'revision_requested')->count();
        $lateDecisions = (clone $submissions)->where('is_late', true)->where('late_decision', 'pending')->count();

        $attendanceSessions = $scoped('attendance.manage');
        $nowUtc = now('UTC');
        $unrecorded = DB::table('attendances as a')->join('meeting_participants as p', 'p.id', '=', 'a.participant_id')->join('session_meetings as sm', 'sm.id', '=', 'p.session_meeting_id')
            ->where('sm.offering_id', $o)->whereIn('sm.session_id', $attendanceSessions)->where('sm.starts_at', '<=', $nowUtc)->where('a.status', 'unrecorded')->count();
        $needSnapshot = DB::table('session_meetings')->where('offering_id', $o)->whereIn('session_id', $attendanceSessions)->whereNull('snapshot_created_at')->where('starts_at', '<=', $nowUtc)->count();

        $requestSessions = $scoped('requests.manage');
        $pendingRequests = DB::table('academic_requests')->where('offering_id', $o)->where('status', 'pending')->whereIn('source_session_id', $requestSessions)
            ->where(fn ($q) => $q->whereNull('target_session_id')->orWhereIn('target_session_id', $requestSessions))->count();
        $pendingDeliveries = DB::table('public_deliveries as d')->join('assignment_schedules as sc', 'sc.id', '=', 'd.schedule_id')->where('d.offering_id', $o)->where('d.status', 'pending')->whereIn('sc.session_id', $submissionSessions)->count();

        $dayStart = CarbonImmutable::parse($today, 'Asia/Jakarta')->startOfDay()->utc();
        $schedule = DB::table('session_meetings as sm')->join('practicum_sessions as s', 's.id', '=', 'sm.session_id')->join('meetings as m', 'm.id', '=', 'sm.meeting_id')
            ->where('sm.offering_id', $o)->whereIn('sm.session_id', $allSessions)->where('sm.starts_at', '>=', $dayStart)->where('sm.starts_at', '<', $dayStart->addDay())
            ->orderBy('sm.starts_at')->select('sm.*', 's.label as session_label', 'm.number', 'm.title')->get()
            ->map(fn ($row) => tap($row, fn ($row) => $row->can_attend = $attendanceSessions->contains($row->session_id)));

        return [
            'students' => $students,
            'to_review' => $toReview,
            'revisions' => $revisions,
            'late_decisions' => $lateDecisions,
            'unrecorded' => $unrecorded,
            'need_snapshot' => $needSnapshot,
            'announcements' => DB::table('announcements')->where('status', 'published')->where(fn ($q) => $q->where('offering_id', $o)->orWhereNull('offering_id'))->orderByDesc('published_at')->limit(3)->get(['id', 'title', 'audience', 'published_at', 'offering_id']),
            'pending_requests' => $pendingRequests,
            'pending_deliveries' => $pendingDeliveries,
            'schedule' => $schedule,
            'today' => CarbonImmutable::parse($today, 'Asia/Jakarta'),
            'can' => [
                'students' => $roster->any($user, $o, 'students.manage'),
                'submissions' => $submissionSessions->isNotEmpty(),
                'attendance' => $attendanceSessions->isNotEmpty(),
                'requests' => $requestSessions->isNotEmpty(),
                'announcements' => $access->allowed($user, $o, 'announcements.manage'),
                'meetings' => $roster->any($user, $o, 'materials.manage') || $attendanceSessions->isNotEmpty(),
            ],
        ];
    }

    public function offering(Request $r, int $offering, StaffAccess $access)
    {
        $o = $access->offerings($r->user())->firstWhere('id', $offering);
        abort_unless($o, 403);
        $sessions = $access->sessions($r->user(), $offering);

        return view('offering', compact('o', 'sessions', 'access'));
    }

    public function session(Request $r, int $offering, int $session, StaffAccess $access)
    {
        abort_unless($access->allowed($r->user(), $offering, 'sessions.manage', $session), 403);
        $s = DB::table('practicum_sessions')->where('id', $session)->first();

        return view('session', ['s' => $s]);
    }
}
