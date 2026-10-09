<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\MeetingRoster;
use App\Services\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeetingController
{
    private function read(Request $r, int $o, Roster $roster): void
    {
        abort_unless($roster->any($r->user(), $o, 'materials.manage') || $roster->any($r->user(), $o, 'attendance.manage'), 403);
    }

    private function shared(Request $r, int $o, Roster $roster): void
    {
        abort_unless($roster->access->allowed($r->user(), $o, 'materials.manage'), 403);
    }

    public function index(Request $r, int $offering, Roster $roster)
    {
        $this->read($r, $offering, $roster);
        $f = $r->validate(['q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|in:10,25,50']);
        $q = DB::table('meetings')->where('offering_id', $offering);
        if (! empty($f['q'])) {
            $q->where('title', 'like', '%'.$f['q'].'%');
        }$meetings = $q->orderBy('number')->paginate($f['per_page'] ?? 10)->withQueryString();
        $ids = $roster->access->sessions($r->user(), $offering)->filter(fn ($s) => $roster->access->allowed($r->user(), $offering, 'materials.manage', $s->id) || $roster->access->allowed($r->user(), $offering, 'attendance.manage', $s->id))->pluck('id');
        $executions = DB::table('session_meetings as sm')->join('practicum_sessions as s', 's.id', '=', 'sm.session_id')->where('sm.offering_id', $offering)->whereIn('sm.meeting_id', $meetings->pluck('id'))->whereIn('sm.session_id', $ids)->select('sm.*', 's.label as session_label')->orderBy('sm.starts_at')->get()->groupBy('meeting_id');

        return view('meetings.index', ['o' => $roster->offering($offering), 'meetings' => $meetings, 'executions' => $executions, 'roster' => $roster, 'canShared' => $roster->access->allowed($r->user(), $offering, 'materials.manage'), 'canSchedule' => $roster->any($r->user(), $offering, 'materials.manage')]);
    }

    public function form(Request $r, int $offering, Roster $roster, MeetingRoster $service, ?int $id = null)
    {
        $this->shared($r, $offering, $roster);

        return view('meetings.form', ['o' => $roster->offering($offering), 'row' => $id ? $service->meeting($offering, $id) : null]);
    }

    public function defaults(Request $r, int $offering, Roster $roster)
    {
        $this->shared($r, $offering, $roster);
        $r->validate(['count' => 'required|integer|min:1|max:100']);
        DB::transaction(function () use ($r, $offering, $roster) {
            $roster->writable($offering);
            abort_if(DB::table('meetings')->where('offering_id', $offering)->exists(), 409);
            for ($i = 1; $i <= $r->integer('count'); $i++) {
                $id = DB::table('meetings')->insertGetId(['offering_id' => $offering, 'number' => $i, 'title' => 'Pertemuan '.$i, 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('meeting', $id, 'meeting.created', null, ['number' => $i, 'title' => 'Pertemuan '.$i], null, $offering);
            }
        });

        return redirect()->route('meetings.index', $offering)->with('success', 'Pertemuan awal dibuat tanpa materi atau jadwal fiktif.');
    }

    public function save(Request $r, int $offering, Roster $roster, MeetingRoster $service, ?int $id = null)
    {
        $this->shared($r, $offering, $roster);
        $d = $r->validate(['number' => ['required', 'integer', 'min:1', 'max:999', Rule::unique('meetings')->where('offering_id', $offering)->ignore($id)], 'title' => 'required|string|max:180'] + ($id ? ['version' => 'required|integer', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted'] : []));
        DB::transaction(function () use ($offering, $roster, $id, $d) {
            $roster->writable($offering);
            $old = $id ? DB::table('meetings')->where('offering_id', $offering)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            if ($old) {
                abort_if($old->version !== (int) $d['version'], 409);
            }$values = ['number' => $d['number'], 'title' => $d['title'], 'updated_at' => now()];
            if ($old) {
                $values['version'] = $old->version + 1;
                DB::table('meetings')->where('id', $id)->update($values);
            } else {
                $id = DB::table('meetings')->insertGetId($values + ['offering_id' => $offering, 'created_at' => now()]);
            }Audit::record('meeting', $id, 'meeting.saved', $old ? (array) $old : null, $values, $d['reason'] ?? null, $offering);
        });

        return redirect()->route('meetings.index', $offering)->with('success', 'Pertemuan disimpan. Metadata snapshot yang sudah dibuat tetap utuh.');
    }

    public function executionForm(Request $r, int $offering, Roster $roster, MeetingRoster $service, ?int $id = null)
    {
        $roster->require($r->user(), $offering, 'materials.manage');
        $row = $id ? $service->execution($r->user(), $offering, $id, 'materials.manage') : null;
        if ($row) {
            abort_if($row->snapshot_created_at, 423, 'Jadwal pada snapshot tidak dapat diubah.');
        }

        return view('meetings.execution', ['o' => $roster->offering($offering), 'row' => $row, 'meetings' => DB::table('meetings')->where('offering_id', $offering)->orderBy('number')->get(), 'sessions' => $roster->access->sessions($r->user(), $offering)->filter(fn ($s) => $roster->access->allowed($r->user(), $offering, 'materials.manage', $s->id))]);
    }

    public function executionSave(Request $r, int $offering, Roster $roster, MeetingRoster $service, ?int $id = null)
    {
        $roster->require($r->user(), $offering, 'materials.manage');
        $d = $r->validate(['meeting_id' => 'required|integer', 'session_id' => 'required|integer', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at', 'room' => 'required|string|max:150'] + ($id ? ['version' => 'required|integer', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted'] : []));
        $service->meeting($offering, (int) $d['meeting_id']);
        $roster->require($r->user(), $offering, 'materials.manage', (int) $d['session_id']);
        DB::transaction(function () use ($r, $offering, $roster, $service, $id, $d) {
            $roster->writable($offering);
            $old = $id ? $service->execution($r->user(), $offering, $id, 'materials.manage', true) : null;
            if ($old) {
                abort_if($old->version !== (int) $d['version'], 409);
                abort_if($old->snapshot_created_at, 423, 'Jadwal pada snapshot tidak dapat diubah.');
                if ($old->session_id !== (int) $d['session_id'] || $old->meeting_id !== (int) $d['meeting_id']) {
                    throw ValidationException::withMessages(['session_id' => 'Identitas pertemuan/sesi tidak dapat dipindah.']);
                }
            }
            $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $d['starts_at'], 'Asia/Jakarta')->setTimezone('UTC')->format('Y-m-d H:i:00');
            $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $d['ends_at'], 'Asia/Jakarta')->setTimezone('UTC')->format('Y-m-d H:i:00');
            $q = DB::table('session_meetings')->where('session_id', $d['session_id'])->where(fn ($q) => $q->where('meeting_id', $d['meeting_id'])->orWhere(fn ($q) => $q->where('starts_at', '<', $end)->where('ends_at', '>', $start)));
            if ($id) {
                $q->where('id', '!=', $id);
            }if ($q->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Sesi/pertemuan sudah dijadwalkan atau waktunya bertabrakan.']);
            }
            $values = ['session_id' => $d['session_id'], 'meeting_id' => $d['meeting_id'], 'starts_at' => $start, 'ends_at' => $end, 'room' => $d['room'], 'updated_at' => now()];
            if ($old) {
                $values['version'] = $old->version + 1;
                DB::table('session_meetings')->where('id', $id)->update($values);
            } else {
                $id = DB::table('session_meetings')->insertGetId($values + ['offering_id' => $offering, 'created_at' => now()]);
            }Audit::record('session_meeting', $id, 'execution.saved', $old ? (array) $old : null, $values, $d['reason'] ?? null, $offering);
        });

        return redirect()->route('meetings.index', $offering)->with('success', 'Jadwal pertemuan disimpan (tampilan WIB, penyimpanan UTC).');
    }
}
