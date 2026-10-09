<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingRoster
{
    public const STATUSES = ['unrecorded' => 'Belum dicatat', 'present' => 'Hadir', 'excused' => 'Izin', 'sick' => 'Sakit', 'absent' => 'Alpa'];

    public function __construct(public Roster $roster) {}

    public function meeting(int $o, int $id): object
    {
        return DB::table('meetings')->where('offering_id', $o)->where('id', $id)->firstOrFail();
    }

    public function execution(User $u, int $o, int $id, string $key = 'attendance.manage', bool $lock = false): object
    {
        $q = DB::table('session_meetings')->where('offering_id', $o)->where('id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }$sm = $q->firstOrFail();
        $this->roster->require($u, $o, $key, $sm->session_id);

        return $sm;
    }

    public function snapshot(User $u, int $o, int $id, int $version): void
    {
        DB::transaction(function () use ($u, $o, $id, $version) {
            $this->roster->writable($o);
            $sm = $this->execution($u, $o, $id, 'attendance.manage', true);
            abort_if($sm->version !== $version, 409);
            if ($sm->snapshot_created_at) {
                return;
            }
            $date = CarbonImmutable::parse($sm->starts_at, 'UTC')->setTimezone('Asia/Jakarta')->toDateString();
            $rows = DB::table('session_memberships as m')->join('enrollments as e', 'e.id', '=', 'm.enrollment_id')->join('students as s', 's.id', '=', 'e.student_id')->where('m.session_id', $sm->session_id)->where('m.offering_id', $o)->where('e.active', true)->whereDate('m.valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('m.valid_until')->orWhereDate('m.valid_until', '>', $date))->select('e.id', 'e.sim_class', 'e.class_category', 's.nbi', 's.name')->orderBy('s.nbi')->get();
            $moves = DB::table('academic_requests as r')->join('session_meetings as src', 'src.id', '=', 'r.source_execution_id')->where('r.type', 'temporary')->where('r.status', 'approved')->where('src.meeting_id', $sm->meeting_id)->get(['r.*']);
            $outgoing = $moves->where('source_execution_id', $id)->pluck('enrollment_id')->all();
            $rows = $rows->reject(fn ($row) => in_array($row->id, $outgoing));
            $incoming = $moves->where('target_execution_id', $id)->keyBy('enrollment_id');
            $extra = DB::table('enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')->whereIn('e.id', $incoming->keys())->where('e.active', true)->select('e.id', 'e.sim_class', 'e.class_category', 's.nbi', 's.name')->get();
            $rows = $rows->concat($extra)->unique('id')->sortBy('nbi')->values();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['snapshot' => 'Tidak ada peserta aktif pada tanggal pelaksanaan. Periksa roster dan tanggal efektif.']);
            }
            if (DB::table('meeting_participants')->where('meeting_id', $sm->meeting_id)->whereIn('enrollment_id', $rows->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['snapshot' => 'Peserta sudah memiliki snapshot di sesi lain untuk pertemuan ini. Tidak membuat presensi ganda.']);
            }
            $offering = $this->roster->offering($o);
            $session = DB::table('practicum_sessions')->where('id', $sm->session_id)->firstOrFail();
            $meeting = $this->meeting($o, $sm->meeting_id);
            $meta = ['practicum' => $offering->name, 'semester' => $offering->semester, 'session' => $session->label, 'number' => $meeting->number, 'title' => $meeting->title, 'starts_at' => $sm->starts_at, 'ends_at' => $sm->ends_at, 'room' => $sm->room];
            foreach ($rows as $index => $r) {
                $pid = DB::table('meeting_participants')->insertGetId(['session_meeting_id' => $id, 'meeting_id' => $sm->meeting_id, 'offering_id' => $o, 'enrollment_id' => $r->id, 'nbi' => $r->nbi, 'name' => $r->name, 'sim_class' => $r->sim_class, 'class_category' => $r->class_category, 'session_label' => $session->label, 'print_order' => $index + 1, 'source' => $incoming->has($r->id) ? 'temporary' : 'membership', 'approved_request_id' => $incoming->get($r->id)?->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('attendances')->insert(['participant_id' => $pid, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('session_meetings')->where('id', $id)->update(['snapshot_created_at' => now(), 'snapshot_meta' => json_encode($meta), 'status' => 'performed', 'version' => $sm->version + 1, 'updated_at' => now()]);
            Audit::record('session_meeting', $id, 'participants.snapshotted', null, ['metadata' => $meta, 'count' => $rows->count()], null, $o);
        }, 3);
    }

    public function participants(int $sm)
    {
        return DB::table('meeting_participants as p')->join('attendances as a', 'a.participant_id', '=', 'p.id')->where('p.session_meeting_id', $sm)->select('p.*', 'a.status', 'a.note', 'a.version as attendance_version', 'a.recorded_at', 'a.recorded_by')->orderBy('p.print_order');
    }
}
