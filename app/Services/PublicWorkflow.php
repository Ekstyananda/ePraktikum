<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules for the public (no-login) portal and request handling.
 * Identity matching is not authentication: every public entry stays pending until staff review it.
 */
class PublicWorkflow
{
    public const TYPES = ['izin' => 'Izin', 'temporary' => 'Pindah satu pertemuan', 'permanent' => 'Pindah permanen', 'susulan' => 'Susulan', 'remidi' => 'Remidi'];

    public const STATES = ['pending' => 'Menunggu pemeriksaan', 'approved' => 'Disetujui', 'rejected' => 'Tidak disetujui', 'completed' => 'Selesai', 'accepted' => 'Diterima pengelola'];

    /** One generic message for every identity failure so the form cannot be used to probe the roster. */
    public const IDENTITY_ERROR = 'Data tidak dapat diproses. Periksa NBI, nama dan sesi, atau hubungi pengelola.';

    public function __construct(public Roster $roster) {}

    /** Only active offerings in an unlocked semester are visible to the public. */
    public function offerings()
    {
        return DB::table('practicum_offerings as o')->join('practicums as p', 'p.id', '=', 'o.practicum_id')->join('semesters as s', 's.id', '=', 'o.semester_id')
            ->where('o.status', 'active')->where('s.status', '!=', 'locked')
            ->select('o.id', 'p.name', 's.label as semester')->orderByDesc('o.id')->get();
    }

    public function publicOffering(int $id): object
    {
        $o = $this->offerings()->firstWhere('id', $id);
        if (! $o) {
            throw ValidationException::withMessages(['offering_id' => 'Praktikum tidak tersedia untuk layanan publik.']);
        }

        return $o;
    }

    public function enrollment(array $d): object
    {
        $this->publicOffering((int) $d['offering_id']);
        $today = now('Asia/Jakarta')->toDateString();
        $e = DB::table('enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')->join('session_memberships as m', 'm.enrollment_id', '=', 'e.id')
            ->where('e.offering_id', $d['offering_id'])->where('e.active', true)->where('s.nbi', trim($d['nbi']))->where('m.session_id', $d['session_id'])
            ->where('m.valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('m.valid_until')->orWhere('m.valid_until', '>', $today))
            ->select('e.*', 's.name', 'm.session_id')->first();
        if (! $e || $this->normalize($e->name) !== $this->normalize($d['name'])) {
            throw ValidationException::withMessages(['identity' => self::IDENTITY_ERROR]);
        }

        return $e;
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    public function isOpen($opens, $closes): bool
    {
        return now()->gte(CarbonImmutable::parse($opens, 'UTC')) && now()->lte(CarbonImmutable::parse($closes, 'UTC'));
    }

    public function period($opens, $closes): void
    {
        if (! $this->isOpen($opens, $closes)) {
            throw ValidationException::withMessages(['period' => 'Form belum dibuka atau sudah ditutup.']);
        }
    }

    /** Last moment a digital task accepts entries: due date, or closing date when late entries are allowed. */
    public function scheduleCloses(object $schedule): string
    {
        return $schedule->allow_late ? $schedule->closes_at : min($schedule->due_at, $schedule->closes_at);
    }

    public function writable(int $o, int $e): void
    {
        app(Assessment::class)->writable($o, $e);
        abort_unless(DB::table('enrollments')->where('id', $e)->where('offering_id', $o)->where('active', true)->exists(), 422);
    }

    public function schedule(int $id): object
    {
        $s = DB::table('assignment_schedules as sc')->join('assignments as a', 'a.id', '=', 'sc.assignment_id')
            ->where('sc.id', $id)->where('a.active', true)->where('a.mode', 'digital')
            ->select('sc.*', 'a.title', 'a.instructions')->first();
        if (! $s) {
            throw ValidationException::withMessages(['schedule_id' => 'Tugas digital tidak tersedia.']);
        }
        $this->period($s->opens_at, $this->scheduleCloses($s));

        return $s;
    }

    public function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** One-time page with a reissued token for staff to hand over; never cached, flashed or redirected. */
    public function issuedToken(string $token, string $label, string $back, object $offering)
    {
        return response()->view('portal-token.issued', compact('token', 'label', 'back') + ['o' => $offering])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function member(int $enrollment, string $date): ?object
    {
        return DB::table('session_memberships')->where('enrollment_id', $enrollment)->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $date))->first();
    }

    public function execution(int $o, int $id): object
    {
        return DB::table('session_meetings')->where('id', $id)->where('offering_id', $o)->firstOrFail();
    }

    public function date($utc): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Jakarta')->toDateString();
    }

    public function utc(string $local): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $local, 'Asia/Jakarta')->utc()->format('Y-m-d H:i:s');
    }

    /** Requests the user may handle: source session and (if any) target session must be inside requests.manage scope. */
    public function scoped($user, int $o)
    {
        $this->roster->require($user, $o, 'requests.manage');
        $sessions = $this->roster->access->sessions($user, $o)->filter(fn ($s) => $this->roster->access->allowed($user, $o, 'requests.manage', $s->id))->pluck('id');

        return DB::table('academic_requests')->where('academic_requests.offering_id', $o)->whereIn('academic_requests.source_session_id', $sessions)
            ->where(fn ($q) => $q->whereNull('academic_requests.target_session_id')->orWhereIn('academic_requests.target_session_id', $sessions));
    }

    /** Participants of a session on a date, including approved one-meeting moves for that meeting. */
    public function capacityAt(int $session, string $date, ?int $meeting = null, ?int $exclude = null): int
    {
        $ids = DB::table('session_memberships as m')->join('enrollments as e', 'e.id', '=', 'm.enrollment_id')
            ->where('m.session_id', $session)->where('e.active', true)->where('m.valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('m.valid_until')->orWhere('m.valid_until', '>', $date))->pluck('e.id')->all();
        if ($meeting) {
            $moves = DB::table('academic_requests as r')->join('session_meetings as sm', 'sm.id', '=', 'r.target_execution_id')
                ->where('r.type', 'temporary')->where('r.status', 'approved')->where('sm.meeting_id', $meeting)->get(['r.*']);
            foreach ($moves as $move) {
                if ($move->source_session_id === $session) {
                    $ids = array_diff($ids, [$move->enrollment_id]);
                }
                if ($move->target_session_id === $session) {
                    $ids[] = $move->enrollment_id;
                }
            }
        }

        return count(array_diff(array_unique($ids), [$exclude]));
    }

    /** Locks the session row so concurrent approvals cannot oversubscribe it. */
    public function reserve(int $session, string $date, ?int $meeting = null, ?int $exclude = null): void
    {
        $row = DB::table('practicum_sessions')->where('id', $session)->lockForUpdate()->firstOrFail();
        if ($row->capacity !== null && $this->capacityAt($session, $date, $meeting, $exclude) + 1 > $row->capacity) {
            throw ValidationException::withMessages(['capacity' => 'Kapasitas sesi tujuan tidak mencukupi.']);
        }
    }
}
