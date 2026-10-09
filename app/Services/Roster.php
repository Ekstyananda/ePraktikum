<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Roster
{
    public function __construct(public StaffAccess $access) {}

    public function offering(int $id): object
    {
        return DB::table('practicum_offerings as o')->join('practicums as p', 'p.id', '=', 'o.practicum_id')->join('semesters as t', 't.id', '=', 'o.semester_id')->where('o.id', $id)->select('o.*', 'p.name', 't.label as semester', 't.status as semester_status')->firstOrFail();
    }

    public function any(User $u, int $o, string $permission): bool
    {
        if ($this->access->allowed($u, $o, $permission)) {
            return true;
        }

        return $this->access->sessions($u, $o)->contains(fn ($s) => $this->access->allowed($u, $o, $permission, $s->id));
    }

    public function require(User $u, int $o, string $key, ?int $s = null): void
    {
        abort_unless($s === null ? $this->any($u, $o, $key) : $this->access->allowed($u, $o, $key, $s), 403);
    }

    public function writable(int $o): void
    {
        // Shared offering lock serializes M2 writes and capacity decisions.
        $offering = DB::table('practicum_offerings')->where('id', $o)->lockForUpdate()->firstOrFail();
        $semester = DB::table('semesters')->where('id', $offering->semester_id)->lockForUpdate()->firstOrFail();
        abort_if($offering->status === 'locked' || $semester->status === 'locked', 423, 'Semester atau praktikum terkunci.');
    }

    public function query(User $u, int $o, string $permission = 'students.manage')
    {
        $this->require($u, $o, $permission);
        $sessions = $this->access->sessions($u, $o)->filter(fn ($s) => $this->access->allowed($u, $o, $permission, $s->id))->pluck('id');

        return DB::table('enrollments as e')->join('students as st', 'st.id', '=', 'e.student_id')->join('session_memberships as m', fn ($j) => $j->on('m.enrollment_id', '=', 'e.id')->where('m.valid_from', '<=', now('Asia/Jakarta')->toDateString())->where(fn ($q) => $q->whereNull('m.valid_until')->orWhere('m.valid_until', '>', now('Asia/Jakarta')->toDateString())))->join('practicum_sessions as ps', 'ps.id', '=', 'm.session_id')->leftJoin('supervisors as sp', 'sp.id', '=', 'e.supervisor_id')->where('e.offering_id', $o)->whereIn('m.session_id', $sessions)->select('e.*', 'st.nbi', 'st.name', 'st.version as student_version', 'm.session_id', 'm.valid_from', 'ps.label as session_label', 'sp.name as supervisor_name', 'sp.identity_code');
    }

    public function filters($q, array $f)
    {
        if (! empty($f['q'])) {
            $q->where(fn ($q) => $q->where('st.nbi', 'like', '%'.$f['q'].'%')->orWhere('st.name', 'like', '%'.$f['q'].'%'));
        }
        foreach (['session_id' => 'm.session_id', 'class_category' => 'e.class_category', 'active' => 'e.active'] as $key => $col) {
            if (isset($f[$key]) && $f[$key] !== '') {
                $q->where($col, $f[$key]);
            }
        }
        if (($f['supervisor_id'] ?? '') === 'empty') {
            $q->whereNull('e.supervisor_id');
        } elseif (! empty($f['supervisor_id'])) {
            $q->where('e.supervisor_id', $f['supervisor_id']);
        }

        return $q;
    }

    public function capacity(int $session, int $extra = 1, ?int $exclude = null): void
    {
        $s = DB::table('practicum_sessions')->where('id', $session)->lockForUpdate()->firstOrFail();
        if ($s->capacity !== null && $this->reservedCount($session, $exclude) + $extra > $s->capacity) {
            throw ValidationException::withMessages(['session_id' => 'Kapasitas sesi tidak mencukupi, termasuk reservasi perpindahan.']);
        }
    }

    public function reservedCount(int $session, ?int $exclude = null): int
    {
        $workflow = app(PublicWorkflow::class);
        $today = now('Asia/Jakarta')->toDateString();
        $dates = DB::table('session_memberships')->where('session_id', $session)->where('valid_from', '>=', $today)->pluck('valid_from')->push($today)->unique();
        $max = 0;
        foreach ($dates as $date) {
            $max = max($max, $workflow->capacityAt($session, $date, null, $exclude));
        }
        foreach (DB::table('session_meetings')->where('session_id', $session)->where('starts_at', '>=', now())->get() as $sm) {
            $max = max($max, $workflow->capacityAt($session, $workflow->date($sm->starts_at), $sm->meeting_id, $exclude));
        }

        return $max;
    }

    public function add(array $data, int $o): int
    {
        $student = DB::table('students')->where('nbi', $data['nbi'])->lockForUpdate()->first();
        if ($student && $student->name !== $data['name']) {
            throw ValidationException::withMessages(['nbi' => 'NBI sudah terdaftar dengan nama berbeda. Periksa master praktikan.']);
        }
        if ($student && DB::table('enrollments')->where('offering_id', $o)->where('student_id', $student->id)->exists()) {
            throw ValidationException::withMessages(['nbi' => 'NBI sudah terdaftar pada praktikum ini.']);
        }
        $this->capacity((int) $data['session_id']);
        $sid = $student?->id ?? DB::table('students')->insertGetId(['nbi' => $data['nbi'], 'name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);
        $eid = DB::table('enrollments')->insertGetId(['offering_id' => $o, 'student_id' => $sid, 'sim_class' => $data['sim_class'], 'class_category' => $data['class_category'], 'supervisor_id' => $data['supervisor_id'] ?? null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('session_memberships')->insert(['enrollment_id' => $eid, 'session_id' => $data['session_id'], 'offering_id' => $o, 'valid_from' => $data['valid_from'] ?? now('Asia/Jakarta')->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('enrollment', $eid, 'enrollment.created', null, $data, null, $o);

        return $eid;
    }
}
