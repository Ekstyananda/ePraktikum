<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Side effects of approving a request. Runs inside the caller's transaction after the offering lock.
 * Approval never writes attendance or grades: izin/susulan are not "Hadir" and remidi keeps the original grade.
 */
class RequestApproval
{
    public function __construct(public PublicWorkflow $public) {}

    public function approve(object $row, array &$values): void
    {
        $s = $this->public;
        $eid = $row->enrollment_id;
        $member = $s->member($eid, now('Asia/Jakarta')->toDateString());
        if (! $member || $member->session_id !== $row->source_session_id) {
            $this->fail('Sesi praktikan sudah berubah sejak pengajuan dibuat. Tolak dan minta pengajuan baru.');
        }

        match ($row->type) {
            'temporary' => $this->temporary($row),
            'permanent' => $this->permanent($row, $member),
            'izin', 'susulan' => $this->sourceMeeting($row),
            'remidi' => $this->remidi($row, $values),
        };
    }

    private function temporary(object $row): void
    {
        $s = $this->public;
        $src = $s->execution($row->offering_id, $row->source_execution_id);
        $target = $s->execution($row->offering_id, $row->target_execution_id);
        if ($src->meeting_id !== $target->meeting_id || $target->session_id !== $row->target_session_id) {
            $this->fail('Pelaksanaan tujuan harus pertemuan yang sama pada sesi tujuan.');
        }
        if ($src->snapshot_created_at || $target->snapshot_created_at) {
            $this->fail('Snapshot peserta sudah dibekukan. Gunakan alur susulan agar arsip tidak berubah.');
        }
        if (now()->gt($target->starts_at)) {
            $this->fail('Pelaksanaan tujuan sudah dimulai.');
        }
        $on = $s->member($row->enrollment_id, $s->date($src->starts_at));
        if (! $on || $on->session_id !== $src->session_id) {
            $this->fail('Praktikan tidak terdaftar di sesi asal pada tanggal pertemuan.');
        }
        $already = DB::table('academic_requests as r')->join('session_meetings as sm', 'sm.id', '=', 'r.source_execution_id')
            ->where('r.enrollment_id', $row->enrollment_id)->where('r.type', 'temporary')->where('r.status', 'approved')->where('sm.meeting_id', $src->meeting_id)->exists();
        if ($already) {
            $this->fail('Sudah ada perpindahan yang disetujui untuk pertemuan ini.');
        }
        $s->reserve($target->session_id, $s->date($target->starts_at), $target->meeting_id, $row->enrollment_id);
    }

    private function permanent(object $row, object $member): void
    {
        $s = $this->public;
        if ($row->effective_date < now('Asia/Jakarta')->toDateString()) {
            $this->fail('Tanggal efektif sudah lewat. Minta pengajuan baru.');
        }
        if ($member->valid_until !== null || $member->valid_from > $row->effective_date) {
            $this->fail('Membership praktikan sudah memiliki perubahan terjadwal.');
        }
        $pendingMove = DB::table('academic_requests as r')->join('session_meetings as sm', 'sm.id', '=', 'r.source_execution_id')
            ->where('r.enrollment_id', $row->enrollment_id)->where('r.type', 'temporary')->where('r.status', 'approved')
            ->where('sm.starts_at', '>=', $row->effective_date.' 00:00:00')->exists();
        if ($pendingMove) {
            $this->fail('Ada perpindahan satu pertemuan yang disetujui setelah tanggal efektif; selesaikan terlebih dahulu.');
        }
        // Check capacity at every future membership boundary and every future meeting of the target session.
        $dates = DB::table('session_memberships')->where('session_id', $row->target_session_id)->where('valid_from', '>=', $row->effective_date)
            ->pluck('valid_from')->push($row->effective_date)->unique();
        foreach ($dates as $date) {
            $s->reserve($row->target_session_id, $date, null, $row->enrollment_id);
        }
        foreach (DB::table('session_meetings')->where('session_id', $row->target_session_id)->where('starts_at', '>=', $row->effective_date.' 00:00:00')->get() as $sm) {
            $s->reserve($row->target_session_id, $s->date($sm->starts_at), $sm->meeting_id, $row->enrollment_id);
        }
        // History stays intact: the old membership is closed, a new one starts on the effective date.
        DB::table('session_memberships')->where('id', $member->id)->update(['valid_until' => $row->effective_date, 'updated_at' => now()]);
        DB::table('session_memberships')->insert(['enrollment_id' => $row->enrollment_id, 'offering_id' => $row->offering_id, 'session_id' => $row->target_session_id, 'valid_from' => $row->effective_date, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->where('id', $row->enrollment_id)->increment('version');
    }

    private function sourceMeeting(object $row): void
    {
        $s = $this->public;
        $source = $s->execution($row->offering_id, $row->source_execution_id);
        $on = $s->member($row->enrollment_id, $s->date($source->starts_at));
        if (! $on || $on->session_id !== $source->session_id) {
            $this->fail('Praktikan tidak terdaftar di sesi pelaksanaan tersebut.');
        }
    }

    private function remidi(object $row, array &$values): void
    {
        $p = DB::table('remedial_programs')->where('id', $row->program_id)->where('offering_id', $row->offering_id)->where('active', true)->first();
        if (! $p) {
            $this->fail('Program remidi tidak aktif.');
        }
        $g = DB::table('grades')->where('enrollment_id', $row->enrollment_id)->where('component_id', $p->component_id)->first();
        if (! $g || $g->score === null) {
            $this->fail('Nilai awal harus sudah dicatat sebelum remidi disetujui.');
        }
        // Freeze the original grade with the request; later corrections never rewrite this copy.
        $values['original_grade'] = json_encode((array) $g);
        $values['scheduled_at'] = $p->scheduled_at;
        $values['room'] = $p->room;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['decision' => $message]);
    }
}
