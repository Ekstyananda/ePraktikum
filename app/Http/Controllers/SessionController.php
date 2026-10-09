<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Roster;
use App\Support\PerPage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SessionController
{
    public function index(Request $r, int $offering, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'sessions.manage');
        $r->validate(['q' => 'nullable|string|max:100']);
        $ids = $roster->access->sessions($r->user(), $offering)->filter(fn ($s) => $roster->access->allowed($r->user(), $offering, 'sessions.manage', $s->id))->pluck('id');
        $rows = DB::table('practicum_sessions as ps')->leftJoin('users as u', 'u.id', '=', 'ps.responsible_user_id')->whereIn('ps.id', $ids)->when($r->q, fn ($q) => $q->where(fn ($q) => $q->where('ps.label', 'like', '%'.$r->q.'%')->orWhere('ps.room', 'like', '%'.$r->q.'%')))->select('ps.*', 'u.name as responsible_name')->orderBy('ps.label')->paginate(PerPage::from($r))->withQueryString();

        return view('sessions.index', ['o' => $roster->offering($offering), 'rows' => $rows, 'canCreate' => $roster->access->allowed($r->user(), $offering, 'sessions.manage')]);
    }

    public function form(Request $r, int $offering, Roster $roster, ?int $id = null)
    {
        if ($id) {
            $roster->require($r->user(), $offering, 'sessions.manage', $id);
        } else {
            abort_unless($roster->access->allowed($r->user(), $offering, 'sessions.manage'), 403);
        }$row = $id ? DB::table('practicum_sessions')->find($id) : null;

        return view('sessions.form', ['o' => $roster->offering($offering), 'row' => $row, 'staff' => DB::table('users')->where('active', true)->where(fn ($q) => $q->where('role', 'admin')->orWhereIn('id', DB::table('staff_assignments')->where('offering_id', $offering)->select('user_id')))->get()]);
    }

    public function save(Request $r, int $offering, Roster $roster, ?int $id = null)
    {
        if ($id) {
            $roster->require($r->user(), $offering, 'sessions.manage', $id);
        } else {
            abort_unless($roster->access->allowed($r->user(), $offering, 'sessions.manage'), 403);
        }
        $d = $r->validate(['label' => ['required', 'string', 'max:100', Rule::unique('practicum_sessions')->where('offering_id', $offering)->ignore($id)], 'weekday' => 'required|integer|between:1,7', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time', 'room' => 'required|string|max:100', 'capacity' => 'required|integer|min:1|max:1000', 'responsible_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('active', true)], 'version' => $id ? 'required|integer' : 'nullable', 'reason' => 'nullable|string|max:1000', 'confirmed' => $id ? 'required|accepted' : 'nullable']);
        if (! empty($d['responsible_user_id'])) {
            $u = User::find($d['responsible_user_id']);
            if (! $roster->access->allowed($u, $offering, 'sessions.manage', $id)) {
                throw ValidationException::withMessages(['responsible_user_id' => 'Penanggung jawab harus berizin pada praktikum dan sesi ini.']);
            }
        }
        DB::transaction(function () use ($roster, $offering, $id, $d) {
            $roster->writable($offering);
            $before = $id ? DB::table('practicum_sessions')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            if ($before) {
                abort_if($before->version != (int) $d['version'], 409);
                $roster->capacity($id, 0);
                $used = $roster->reservedCount($id);
                if ($used > $d['capacity']) {
                    throw ValidationException::withMessages(['capacity' => 'Kapasitas lebih kecil dari jumlah peserta aktif.']);
                }
            }
            $save = array_diff_key($d, array_flip(['version', 'reason', 'confirmed']));
            $save['updated_at'] = now();
            if ($id) {
                $save['version'] = $before->version + 1;
                DB::table('practicum_sessions')->where('id', $id)->update($save);
            } else {
                $save += ['offering_id' => $offering, 'created_at' => now()];
                $id = DB::table('practicum_sessions')->insertGetId($save);
            }Audit::record('session', $id, $before ? 'session.updated' : 'session.created', $before ? (array) $before : null, $save, $d['reason'] ?? null, $offering);
        });

        return redirect()->route('sessions.index', $offering)->with('success', 'Sesi dan jadwal mingguan berhasil disimpan.');
    }

    public function destroy(Request $r, int $offering, int $id, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'sessions.manage', $id);
        $d = $r->validate(['reason' => 'nullable|string|max:1000', 'version' => 'required|integer']);
        try {
            DB::transaction(function () use ($offering, $id, $roster, $d) {
                $roster->writable($offering);
                $row = DB::table('practicum_sessions')->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_if($row->version != (int) $d['version'], 409);
                DB::table('practicum_sessions')->where('id', $id)->delete();
                Audit::record('session', $id, 'session.deleted', (array) $row, null, ($d['reason'] ?? null), $offering);
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }throw ValidationException::withMessages(['reason' => 'Sesi sudah memiliki penugasan/peserta; tidak dapat dihapus.']);
        }

        return back()->with('success', 'Sesi berhasil dihapus.');
    }
}
