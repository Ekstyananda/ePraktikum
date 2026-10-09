<?php

namespace App\Http\Controllers;

use App\Services\Roster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceOverviewController
{
    /** Read-only list of executions whose attendance the user may manage. */
    public function __invoke(Request $r, int $offering, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'attendance.manage');
        $f = $r->validate(['session_id' => 'nullable|integer']);
        $sessions = $roster->access->sessions($r->user(), $offering)->filter(fn ($s) => $roster->access->allowed($r->user(), $offering, 'attendance.manage', $s->id))->values();
        $ids = $sessions->pluck('id');
        if (! empty($f['session_id'])) {
            $ids = $ids->intersect([(int) $f['session_id']]);
        }
        $counts = DB::table('meeting_participants as p')->join('attendances as a', 'a.participant_id', '=', 'p.id')->where('p.offering_id', $offering)
            ->groupBy('p.session_meeting_id')->select('p.session_meeting_id', DB::raw('count(*) as total'), DB::raw("sum(a.status = 'present') as present"), DB::raw("sum(a.status = 'unrecorded') as unrecorded"));
        $rows = DB::table('session_meetings as sm')->join('practicum_sessions as s', 's.id', '=', 'sm.session_id')->join('meetings as m', 'm.id', '=', 'sm.meeting_id')
            ->leftJoinSub($counts, 'c', 'c.session_meeting_id', '=', 'sm.id')
            ->where('sm.offering_id', $offering)->whereIn('sm.session_id', $ids)
            ->select('sm.*', 's.label as session_label', 'm.number', 'm.title', 'c.total', 'c.present', 'c.unrecorded')
            ->orderBy('sm.starts_at')->paginate(25)->withQueryString();

        return view('attendance.overview', ['o' => $roster->offering($offering), 'rows' => $rows, 'sessions' => $sessions]);
    }
}
