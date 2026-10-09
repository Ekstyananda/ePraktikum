<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Academic recap tables. Each report returns ['headers' => [...], 'rows' => [[...]]] of plain strings so the
 * page, CSV and XLSX always show the same data. Rows are limited to sessions where the user has reports.export.
 */
class Reports
{
    public const TYPES = ['presensi' => 'Rekap presensi', 'pengumpulan' => 'Rekap pengumpulan', 'nilai' => 'Rekap nilai', 'pengajuan' => 'Rekap pengajuan'];

    public function __construct(public Roster $roster) {}

    public function sessions(User $u, int $o)
    {
        $this->roster->require($u, $o, 'reports.export');

        return $this->roster->access->sessions($u, $o)->filter(fn ($s) => $this->roster->access->allowed($u, $o, 'reports.export', $s->id))->values();
    }

    public function build(User $u, int $o, string $type, ?int $session = null, ?int $meeting = null): array
    {
        $ids = $this->sessions($u, $o)->pluck('id');
        if ($session) {
            $ids = $ids->intersect([$session])->values();
        }

        return match ($type) {
            'presensi' => $this->attendance($o, $ids, $meeting),
            'pengumpulan' => $this->submissions($o, $ids, $meeting),
            'nilai' => $this->grades($o, $ids),
            'pengajuan' => $this->requests($o, $ids),
        };
    }

    private function wib($utc, string $format = 'd/m/Y H:i'): string
    {
        return $utc ? CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Jakarta')->format($format) : '';
    }

    /** Current roster of the scoped sessions (today's membership). */
    private function roster(int $o, $ids)
    {
        $today = now('Asia/Jakarta')->toDateString();

        return DB::table('enrollments as e')->join('students as st', 'st.id', '=', 'e.student_id')
            ->join('session_memberships as m', fn ($j) => $j->on('m.enrollment_id', '=', 'e.id')->where('m.valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('m.valid_until')->orWhere('m.valid_until', '>', $today)))
            ->join('practicum_sessions as ps', 'ps.id', '=', 'm.session_id')
            ->where('e.offering_id', $o)->whereIn('m.session_id', $ids)
            ->orderBy('ps.label')->orderBy('st.nbi')->get(['e.id', 'st.nbi', 'st.name', 'ps.label as session', 'e.active']);
    }

    private function attendance(int $o, $ids, ?int $meeting): array
    {
        $labels = MeetingRoster::STATUSES;
        $meetings = DB::table('meetings')->where('offering_id', $o)->when($meeting, fn ($q) => $q->where('id', $meeting))->orderBy('number')->get();
        // Snapshot rows are the archive: identity and session as frozen at the meeting.
        $records = DB::table('meeting_participants as p')->join('attendances as a', 'a.participant_id', '=', 'p.id')->join('session_meetings as sm', 'sm.id', '=', 'p.session_meeting_id')
            ->where('p.offering_id', $o)->whereIn('sm.session_id', $ids)->whereIn('p.meeting_id', $meetings->pluck('id'))
            ->orderBy('p.nbi')->get(['p.enrollment_id', 'p.nbi', 'p.name', 'p.session_label', 'p.meeting_id', 'a.status']);
        $headers = array_merge(['No', 'NBI', 'Nama', 'Sesi'], $meetings->map(fn ($m) => 'P'.$m->number)->all(), ['Hadir', 'Izin', 'Sakit', 'Alpa', 'Belum dicatat']);
        $rows = [];
        foreach ($records->groupBy('enrollment_id') as $group) {
            $first = $group->last();
            $byMeeting = $group->keyBy('meeting_id');
            $cells = $meetings->map(fn ($m) => isset($byMeeting[$m->id]) ? $labels[$byMeeting[$m->id]->status] : '—')->all();
            $count = fn ($s) => (string) $group->where('status', $s)->count();
            $rows[] = array_merge([(string) (count($rows) + 1), $first->nbi, $first->name, $group->pluck('session_label')->unique()->implode(', ')], $cells, [$count('present'), $count('excused'), $count('sick'), $count('absent'), $count('unrecorded')]);
        }

        return ['headers' => $headers, 'rows' => $rows, 'note' => 'Sumber: snapshot peserta dan rekap TTD. "—" berarti belum ada snapshot untuk pertemuan itu.'];
    }

    private function submissions(int $o, $ids, ?int $meeting): array
    {
        $assignments = DB::table('assignments')->where('offering_id', $o)->where('active', true)->where('mode', '!=', 'direct')
            ->when($meeting, fn ($q) => $q->where('origin_meeting_id', $meeting))->orderBy('id')->get();
        $roster = $this->roster($o, $ids);
        $subs = DB::table('submissions')->where('offering_id', $o)->whereIn('enrollment_id', $roster->pluck('id'))->whereIn('assignment_id', $assignments->pluck('id'))->get()->groupBy('enrollment_id');
        $states = Assessment::PRINT_STATES + Assessment::DIGITAL_STATES;
        $rows = [];
        foreach ($roster as $i => $e) {
            $mine = ($subs[$e->id] ?? collect())->keyBy('assignment_id');
            $cells = $assignments->map(function ($a) use ($mine, $states) {
                $s = $mine[$a->id] ?? null;

                return $s ? $states[$s->status].($s->is_late ? ' (terlambat)' : '') : 'Belum diterima';
            })->all();
            $rows[] = array_merge([(string) ($i + 1), $e->nbi, $e->name, $e->session], $cells);
        }

        return ['headers' => array_merge(['No', 'NBI', 'Nama', 'Sesi'], $assignments->pluck('title')->all()), 'rows' => $rows, 'note' => 'Status penerimaan per tugas. Tugas pemeriksaan langsung tidak termasuk.'];
    }

    private function grades(int $o, $ids): array
    {
        $components = DB::table('grading_components')->where('offering_id', $o)->where('active', true)->orderBy('id')->get();
        $roster = $this->roster($o, $ids);
        $grades = DB::table('grades')->where('offering_id', $o)->whereIn('enrollment_id', $roster->pluck('id'))->get()->groupBy('enrollment_id');
        $finals = DB::table('final_results')->where('offering_id', $o)->whereNull('superseded_at')->whereIn('enrollment_id', $roster->pluck('id'))->get()->keyBy('enrollment_id');
        $remedial = DB::table('academic_requests as r')->join('request_results as rr', 'rr.request_id', '=', 'r.id')->where('r.offering_id', $o)->where('r.type', 'remidi')
            ->whereIn('r.enrollment_id', $roster->pluck('id'))->orderBy('rr.version')->get(['r.enrollment_id', 'rr.score'])->groupBy('enrollment_id');
        $rows = [];
        foreach ($roster as $i => $e) {
            $final = $finals[$e->id] ?? null;
            if ($final) {
                // Finalized: values come from the archived snapshot, i.e. the configuration valid at finalization.
                $snapshot = collect(json_decode($final->snapshot_json, true)['components'] ?? [])->keyBy(fn ($c) => $c['component']['id']);
                $cells = $components->map(fn ($c) => isset($snapshot[$c->id]) ? $this->score($snapshot[$c->id]['grade']['score'] ?? null, $snapshot[$c->id]['grade']['status'] ?? null) : '—')->all();
                $tail = [(string) (float) $final->score, $final->letter, $final->decision, 'Final v'.$final->version.' · aturan v'.(json_decode($final->snapshot_json, true)['rules_version'] ?? '?')];
            } else {
                $mine = ($grades[$e->id] ?? collect())->keyBy('component_id');
                $cells = $components->map(fn ($c) => isset($mine[$c->id]) ? $this->score($mine[$c->id]->score, $mine[$c->id]->status) : 'Belum diperiksa')->all();
                $tail = ['', '', '', 'Belum final'];
            }
            $last = ($remedial[$e->id] ?? collect())->last();
            $rows[] = array_merge([(string) ($i + 1), $e->nbi, $e->name, $e->session], $cells, $tail, [$last ? (string) (float) $last->score : '']);
        }

        return ['headers' => array_merge(['No', 'NBI', 'Nama', 'Sesi'], $components->pluck('label')->all(), ['Nilai akhir', 'Huruf', 'Keputusan', 'Status', 'Hasil remidi (terpisah)']), 'rows' => $rows,
            'note' => 'Praktikan final memakai snapshot finalisasi (aturan saat itu). Kosong berarti belum diperiksa, bukan nol. Hasil remidi tidak digabung otomatis.'];
    }

    private function score($score, ?string $status): string
    {
        if ($status === 'missing_zero') {
            return '0 (diputuskan)';
        }

        return $score === null ? 'Belum diperiksa' : (string) (float) $score;
    }

    private function requests(int $o, $ids): array
    {
        $types = PublicWorkflow::TYPES;
        $states = PublicWorkflow::STATES;
        $rows = DB::table('academic_requests as r')->join('enrollments as e', 'e.id', '=', 'r.enrollment_id')->join('students as st', 'st.id', '=', 'e.student_id')
            ->leftJoin('practicum_sessions as a', 'a.id', '=', 'r.source_session_id')->leftJoin('practicum_sessions as b', 'b.id', '=', 'r.target_session_id')
            ->where('r.offering_id', $o)->whereIn('r.source_session_id', $ids)->where(fn ($q) => $q->whereNull('r.target_session_id')->orWhereIn('r.target_session_id', $ids))
            ->orderBy('r.created_at')->get(['r.*', 'st.nbi', 'st.name', 'a.label as source', 'b.label as target'])
            ->values()->map(fn ($r, $i) => [(string) ($i + 1), $this->wib($r->created_at), $r->nbi, $r->name, $types[$r->type], $r->source.($r->target ? ' → '.$r->target : ''), $states[$r->status] ?? $r->status, $this->wib($r->decision_at), (string) $r->decision_note])->all();

        return ['headers' => ['No', 'Diajukan', 'NBI', 'Nama', 'Jenis', 'Sesi', 'Status', 'Diputuskan', 'Catatan keputusan'], 'rows' => $rows, 'note' => 'Waktu dalam WIB.'];
    }
}
