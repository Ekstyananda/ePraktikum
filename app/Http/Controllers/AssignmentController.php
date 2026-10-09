<?php

namespace App\Http\Controllers;

use App\Services\Assessment;
use App\Services\Audit;
use App\Support\PerPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssignmentController
{
    public function index(Request $r, int $offering, Assessment $s)
    {
        $s->roster->require($r->user(), $offering, 'submissions.manage');
        $f = $r->validate(['mode' => ['nullable', Rule::in(array_keys(Assessment::MODES))], 'q' => 'nullable|string|max:100']);
        $q = DB::table('assignments')->where('offering_id', $offering);
        if (! empty($f['mode'])) {
            $q->where('mode', $f['mode']);
        }if (! empty($f['q'])) {
            $q->where('title', 'like', '%'.$f['q'].'%');
        }

        return view('assignments.index', ['o' => $s->roster->offering($offering), 'rows' => $q->orderBy('id')->paginate(PerPage::from($r))->withQueryString(), 'shared' => $s->roster->access->allowed($r->user(), $offering, 'submissions.manage')]);
    }

    public function form(Request $r, int $offering, Assessment $s, ?int $id = null)
    {
        $s->shared($r->user(), $offering, 'submissions.manage');

        return view('assignments.form', ['o' => $s->roster->offering($offering), 'row' => $id ? $s->assignment($offering, $id) : null, 'meetings' => DB::table('meetings')->where('offering_id', $offering)->orderBy('number')->get()]);
    }

    public function save(Request $r, int $offering, Assessment $s, ?int $id = null)
    {
        $s->shared($r->user(), $offering, 'submissions.manage');
        $d = $r->validate(['title' => 'required|string|max:180', 'type' => ['required', Rule::in(array_keys(Assessment::TYPES))], 'mode' => ['required', Rule::in(array_keys(Assessment::MODES))], 'origin_meeting_id' => ['nullable', 'integer', Rule::exists('meetings', 'id')->where('offering_id', $offering)], 'instructions' => 'nullable|string|max:10000', 'mandatory' => 'required|boolean', 'active' => 'required|boolean'] + ($id ? ['version' => 'required|integer', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted'] : []));
        if ($d['type'] === 'lab' && $d['mode'] !== 'direct') {
            throw ValidationException::withMessages(['mode' => 'Praktik lab memakai pemeriksaan langsung.']);
        }if ($d['type'] === 'final' && ($d['mode'] !== 'print' || ! empty($d['origin_meeting_id']))) {
            throw ValidationException::withMessages(['mode' => 'Laporan akhir memakai cetak tanpa pertemuan asal.']);
        }if (in_array($d['type'], ['pendahuluan', 'aktivitas', 'lab']) && empty($d['origin_meeting_id'])) {
            throw ValidationException::withMessages(['origin_meeting_id' => 'Tugas pertemuan harus memiliki pertemuan asal.']);
        }DB::transaction(function () use ($offering, $s, $id, $d) {
            $s->writable($offering);
            $old = $id ? DB::table('assignments')->where('offering_id', $offering)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            if ($old) {
                abort_if($old->version != (int) $d['version'], 409);
                foreach (['mode', 'type', 'origin_meeting_id'] as $key) {
                    if (($old->$key ?? null) != ($d[$key] ?? null)) {
                        throw ValidationException::withMessages([$key => 'Identitas tugas tidak dapat diubah setelah dibuat.']);
                    }
                }
            }
            $values = array_intersect_key($d, array_flip(['title', 'type', 'mode', 'origin_meeting_id', 'instructions', 'mandatory', 'active']));
            $values['updated_at'] = now();
            if ($old) {
                $values['version'] = $old->version + 1;
                DB::table('assignments')->where('id', $id)->update($values);
            } else {
                $id = DB::table('assignments')->insertGetId($values + ['offering_id' => $offering, 'created_at' => now()]);
                if ($d['type'] === 'final') {
                    $items = ['cover' => 'Cover'];
                    for ($n = 1; $n <= 5; $n++) {
                        $items['pendahuluan_'.$n] = 'Pendahuluan '.$n;
                        $items['pustaka_'.$n] = 'Daftar pustaka bagian '.$n;
                        $items['aktivitas_'.$n] = 'Aktivitas '.$n;
                    }$order = 0;
                    foreach ($items as $key => $label) {
                        DB::table('report_checklist_items')->insert(['assignment_id' => $id, 'key' => $key, 'label' => $label, 'sort_order' => ++$order]);
                    }
                }
            }Audit::record('assignment', $id, 'assignment.saved', $old ? (array) $old : null, $values, $d['reason'] ?? null, $offering);
        });

        return redirect()->route('assignments.index', $offering)->with('success', 'Tugas disimpan. Jadwal pengumpulan diatur terpisah dari pertemuan asal.');
    }

    public function schedules(Request $r, int $offering, int $assignment, Assessment $s)
    {
        $s->roster->require($r->user(), $offering, 'submissions.manage');
        $sessions = $s->roster->access->sessions($r->user(), $offering)->filter(fn ($x) => $s->roster->access->allowed($r->user(), $offering, 'submissions.manage', $x->id));

        return view('assignments.schedules', ['o' => $s->roster->offering($offering), 'a' => $s->assignment($offering, $assignment), 'sessions' => $sessions, 'rows' => DB::table('assignment_schedules')->where('assignment_id', $assignment)->whereIn('session_id', $sessions->pluck('id'))->get(), 'executions' => DB::table('session_meetings as sm')->join('meetings as m', 'm.id', '=', 'sm.meeting_id')->join('practicum_sessions as ps', 'ps.id', '=', 'sm.session_id')->where('sm.offering_id', $offering)->whereIn('sm.session_id', $sessions->pluck('id'))->select('sm.*', 'm.number', 'ps.label')->get()]);
    }

    public function scheduleSave(Request $r, int $offering, int $assignment, Assessment $s)
    {
        // Authorize before validating so users without access never see validation feedback.
        $s->roster->require($r->user(), $offering, 'submissions.manage');
        $a = $s->assignment($offering, $assignment);
        abort_if($a->mode === 'direct', 422);
        $d = $r->validate(['session_id' => 'required|integer', 'collection_session_meeting_id' => 'nullable|integer', 'opens_at' => 'required|date_format:Y-m-d\TH:i', 'due_at' => 'required|date_format:Y-m-d\TH:i|after_or_equal:opens_at', 'closes_at' => 'required|date_format:Y-m-d\TH:i|after_or_equal:due_at', 'allow_late' => 'required|boolean', 'version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted']);
        $s->roster->require($r->user(), $offering, 'submissions.manage', (int) $d['session_id']);
        if (! empty($d['collection_session_meeting_id'])) {
            abort_unless(DB::table('session_meetings')->where('offering_id', $offering)->where('session_id', $d['session_id'])->where('id', $d['collection_session_meeting_id'])->exists(), 422);
        }DB::transaction(function () use ($offering, $assignment, $s, $d) {
            $s->writable($offering);
            $old = DB::table('assignment_schedules')->where('assignment_id', $assignment)->where('session_id', $d['session_id'])->lockForUpdate()->first();
            abort_if(($old?->version ?? 0) != (int) $d['version'], 409);
            if ($old && DB::table('submissions')->where('schedule_id', $old->id)->exists()) {
                throw ValidationException::withMessages(['version' => 'Jadwal sudah dipakai penerimaan. Tidak dapat mengubah batas waktu arsip.']);
            }$values = ['collection_session_meeting_id' => $d['collection_session_meeting_id'] ?? null, 'allow_late' => $d['allow_late'], 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
            foreach (['opens_at', 'due_at', 'closes_at'] as $field) {
                $values[$field] = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $d[$field], 'Asia/Jakarta')->utc()->format('Y-m-d H:i:00');
            }if ($old) {
                $id = $old->id;
                DB::table('assignment_schedules')->where('id', $id)->update($values);
            } else {
                $id = DB::table('assignment_schedules')->insertGetId($values + ['offering_id' => $offering, 'assignment_id' => $assignment, 'session_id' => $d['session_id'], 'created_at' => now()]);
            }Audit::record('assignment_schedule', $id, 'schedule.saved', $old ? (array) $old : null, $values, ($d['reason'] ?? null), $offering);
        });

        return back()->with('success', 'Jadwal pengumpulan disimpan dalam WIB.');
    }
}
