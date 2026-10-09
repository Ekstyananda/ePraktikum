<?php

namespace App\View\Composers;

use App\Services\Roster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Builds the manager sidebar/topbar context. The active offering comes from the
 * route, then the session, then the first offering in scope; it is always
 * re-checked against StaffAccess so a stale session value never leaks scope.
 */
class ManagerShell
{
    public const SESSION_KEY = 'portal.offering';

    public function __construct(private Request $request, private Roster $roster) {}

    public function compose(View $view): void
    {
        $user = $this->request->user();
        if (! $user || $view->offsetExists('shell')) {
            return;
        }
        $offerings = $this->roster->access->offerings($user);
        $current = $this->current($offerings);
        $view->with('shell', [
            'offerings' => $offerings,
            'current' => $current,
            'menu' => $current ? $this->menu($user, $current->id) : [],
            'initials' => collect(preg_split('/\s+/', trim($user->name)))->filter(fn ($w) => preg_match('/^\p{L}/u', $w))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
        ]);
    }

    private function current($offerings): ?object
    {
        $session = $this->request->hasSession() ? $this->request->session() : null;
        $candidates = [(int) $this->request->route('offering'), (int) $session?->get(self::SESSION_KEY)];
        foreach ($candidates as $id) {
            if ($id && ($o = $offerings->firstWhere('id', $id))) {
                $session?->put(self::SESSION_KEY, $o->id);

                return $o;
            }
        }

        return $offerings->first();
    }

    private function menu($user, int $o): array
    {
        $can = fn (string $key) => $this->roster->any($user, $o, $key);
        $r = $this->request;

        return array_values(array_filter([
            ['label' => 'Dashboard', 'icon' => 'house-door', 'url' => route('dashboard'), 'active' => $r->routeIs('dashboard', 'offering', 'session')],
            $can('students.manage') ? ['label' => 'Praktikan', 'icon' => 'people', 'url' => route('students.index', $o), 'active' => $r->routeIs('students.*', 'supervisors.*', 'imports.*')] : null,
            $can('sessions.manage') ? ['label' => 'Sesi', 'icon' => 'calendar-week', 'url' => route('sessions.index', $o), 'active' => $r->routeIs('sessions.*')] : null,
            ($can('materials.manage') || $can('attendance.manage')) ? ['label' => 'Pertemuan', 'icon' => 'journal-bookmark', 'url' => route('meetings.index', $o), 'active' => $r->routeIs('meetings.*', 'executions.*', 'materials.*')] : null,
            $can('attendance.manage') ? ['label' => 'Presensi', 'icon' => 'clipboard-check', 'url' => route('attendance.overview', $o), 'active' => $r->routeIs('attendance.*')] : null,
            $can('submissions.manage') ? ['label' => 'Pengumpulan', 'icon' => 'cloud-arrow-up', 'url' => route('collections.index', $o), 'active' => $r->routeIs('collections.*', 'assignments.*', 'submissions.*', 'deliveries.*') && ! $this->isFinalReport($r)] : null,
            $can('submissions.manage') ? ['label' => 'Laporan Akhir', 'icon' => 'journal-check', 'url' => route('final-reports.index', $o), 'active' => $this->isFinalReport($r)] : null,
            $can('grades.manage') ? ['label' => 'Penilaian', 'icon' => 'pencil-square', 'url' => route('grades.index', $o), 'active' => $r->routeIs('grades.index', 'grades.form', 'grades.final')] : null,
            $this->roster->access->allowed($user, $o, 'grading_rules.manage') ? ['label' => 'Aturan Nilai', 'icon' => 'sliders', 'url' => route('grades.rules', $o), 'active' => $r->routeIs('grades.rules', 'grades.components')] : null,
            $can('requests.manage') ? ['label' => 'Pengajuan', 'icon' => 'envelope-paper', 'url' => route('requests.index', $o), 'active' => $r->routeIs('requests.*'), 'badge' => DB::table('academic_requests')->where('offering_id', $o)->where('status', 'pending')->count() ?: null] : null,
            $this->roster->access->allowed($user, $o, 'announcements.manage') ? ['label' => 'Pengumuman', 'icon' => 'megaphone', 'url' => route('announcements.index', $o), 'active' => $r->routeIs('announcements.*')] : null,
            ($user->role === 'admin' || $this->roster->access->allowed($user, $o, 'portal.manage')) ? ['label' => 'Tampilan Portal', 'icon' => 'palette', 'url' => route('portal-identity.edit', $o), 'active' => $r->routeIs('portal-identity.*')] : null,
            $can('reports.export') ? ['label' => 'Rekap', 'icon' => 'bar-chart-line', 'url' => route('reports.index', $o), 'active' => $r->routeIs('reports.*')] : null,
            ($user->role !== 'admin' && $this->roster->access->allowed($user, $o, 'logs.view')) ? ['label' => 'Log Aktivitas', 'icon' => 'journal-text', 'url' => route('logs.offering', $o), 'active' => $r->routeIs('logs.offering*')] : null,
        ]));
    }

    /** Final-report pages, including a receipt form of a "final" assignment. */
    private function isFinalReport(Request $r): bool
    {
        if ($r->routeIs('final-reports.*')) {
            return true;
        }
        $assignment = (int) $r->route('assignment');

        return $assignment && $r->routeIs('submissions.*') && DB::table('assignments')->where('id', $assignment)->value('type') === 'final';
    }
}
