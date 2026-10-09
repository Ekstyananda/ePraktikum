<?php

namespace App\Http\Controllers;

use App\Services\PracticumPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Public landing: practicum picker at "/" and one home page per practicum at "/{slug}". */
class HomeController
{
    public function __invoke(PracticumPortal $portal)
    {
        return view('portal.hub', ['practicums' => $portal->listed(), 'announcements' => AnnouncementController::publicQuery()->limit(3)->get()]);
    }

    /** Practicum home: same layout as before, scoped to the portal's offering. */
    public function portal(Request $r, MaterialController $materials)
    {
        $ctx = $r->attributes->get('portal');
        $o = $ctx['offering']->id;
        $svc = $ctx['services'];

        return view('public', [
            // Disabled services show nothing, not just hidden links.
            'materials' => ! $svc['modul'] ? collect() : $materials->published()->where('mt.offering_id', $o)->select('m.id', 'm.title', 'mt.number', 'p.name as practicum')->orderByDesc('m.published_at')->limit(4)->get(),
            'upcoming' => ! $svc['jadwal'] ? collect() : DB::table('session_meetings as sm')->join('practicum_sessions as ps', 'ps.id', '=', 'sm.session_id')->join('meetings as m', 'm.id', '=', 'sm.meeting_id')
                ->where('sm.offering_id', $o)->where('sm.ends_at', '>=', now())->orderBy('sm.starts_at')->limit(4)->get(['sm.starts_at', 'sm.ends_at', 'sm.room', 'ps.label', 'm.number']),
            'announcements' => AnnouncementController::publicQuery($o)->limit(3)->get(),
        ]);
    }

    /** Old service URLs (/jadwal, /modul, ...): forward when exactly one practicum is public, else to the picker. */
    public function legacy(Request $r, PracticumPortal $portal)
    {
        $listed = $portal->listed();
        if ($listed->count() !== 1) {
            return redirect()->route('home');
        }
        $path = trim($r->path(), '/');

        return redirect('/'.$listed->first()->slug.'/'.$path.($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301);
    }
}
