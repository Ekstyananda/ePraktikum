<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\PracticumPortal;
use App\Support\PerPage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MasterController
{
    public const TYPES = ['semester' => 'semesters', 'praktikum' => 'practicums', 'offering' => 'practicum_offerings'];

    private function table(string $type): string
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    public function index(Request $r, string $type)
    {
        $table = $this->table($type);
        $r->validate(['q' => 'nullable|string|max:100']);
        $q = DB::table($table);
        if ($type === 'offering') {
            $q->join('semesters as s', 's.id', '=', 'practicum_offerings.semester_id')->join('practicums as p', 'p.id', '=', 'practicum_offerings.practicum_id')->select('practicum_offerings.*', 's.label', 'p.name')->when($r->q, fn ($q) => $q->where('p.name', 'like', '%'.$r->q.'%'));
        } else {
            $q->when($r->q, fn ($q) => $q->where($type === 'semester' ? 'label' : 'name', 'like', '%'.$r->q.'%'));
        }

        return view('master.index', ['type' => $type, 'rows' => $q->orderByDesc($table.'.id')->paginate(PerPage::from($r))->withQueryString()]);
    }

    public function form(string $type, ?int $id = null)
    {
        $table = $this->table($type);

        return view('master.form', ['type' => $type, 'row' => $id ? DB::table($table)->where('id', $id)->firstOrFail() : null, 'semesters' => DB::table('semesters')->where('status', '!=', 'locked')->get(), 'practicums' => DB::table('practicums')->get()]);
    }

    public function save(Request $r, string $type, ?int $id = null)
    {
        $table = $this->table($type);
        $rules = match ($type) {
            'semester' => ['code' => ['required', 'string', 'max:40', Rule::unique($table)->ignore($id)], 'label' => 'required|string|max:150', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after_or_equal:starts_at', 'status' => 'required|in:draft,active'],
            'praktikum' => ['code' => ['required', 'string', 'max:40', Rule::unique($table)->ignore($id)], 'name' => 'required|string|max:150', 'description' => 'nullable|string|max:5000'],
            'offering' => ['semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')->where('status', '!=', 'locked')], 'practicum_id' => ['required', 'integer', Rule::exists('practicums', 'id'), Rule::unique($table, 'practicum_id')->where('semester_id', $r->semester_id)->ignore($id)], 'status' => 'required|in:draft,active'],
        };
        if ($id) {
            $rules += ['version' => 'required|integer', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted'];
        }
        $d = $r->validate($rules);
        DB::transaction(function () use ($table, $d, $id, $type) {
            $before = $id ? DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            if ($before && $type === 'praktikum' && DB::table('practicum_offerings as o')->join('semesters as s', 's.id', '=', 'o.semester_id')->where('o.practicum_id', $id)->where(fn ($q) => $q->where('o.status', 'locked')->orWhere('s.status', 'locked'))->exists()) {
                abort(423);
            }
            if ($before) {
                abort_if(($before->status ?? null) === 'locked', 423);
                abort_if($before->version != (int) $d['version'], 409);
            }
            if ($before && $type === 'offering' && ($before->semester_id != $d['semester_id'] || $before->practicum_id != $d['practicum_id'])) {
                throw ValidationException::withMessages(['semester_id' => 'Pelaksanaan praktikum tidak dapat dipindahkan. Buat pelaksanaan baru.']);
            }
            $save = array_diff_key($d, array_flip(['version', 'reason', 'confirmed']));
            $save['updated_at'] = now();
            if ($id) {
                $save['version'] = $before->version + 1;
                DB::table($table)->where('id', $id)->update($save);
            } else {
                $save['created_at'] = now();
                $id = DB::table($table)->insertGetId($save);
                if ($type === 'praktikum') {
                    // Every practicum gets a public address right away; admin can change it in Tampilan Portal.
                    app(PracticumPortal::class)->registerInitial($id, $save['code']);
                }
            }
            Audit::record($type, $id, $before ? 'master.updated' : 'master.created', $before ? (array) $before : null, $save, $d['reason'] ?? null);
        });

        return redirect()->route('master.index', $type)->with('success', 'Data master berhasil disimpan.');
    }

    public function destroy(Request $r, string $type, int $id)
    {
        $table = $this->table($type);
        $d = $r->validate(['reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted', 'version' => 'required|integer']);
        try {
            DB::transaction(function () use ($table, $type, $id, $d) {
                $row = DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_if(($row->status ?? null) === 'locked', 423);
                abort_if($row->version != (int) $d['version'], 409);
                DB::table($table)->where('id', $id)->delete();
                Audit::record($type, $id, 'master.deleted', (array) $row, null, ($d['reason'] ?? null));
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }throw ValidationException::withMessages(['reason' => 'Data sudah digunakan; tidak dapat dihapus.']);
        }

        return back()->with('success', 'Data master yang belum digunakan berhasil dihapus.');
    }

    /** Admin locks or reopens a semester/offering. Locking stops every academic write; reopening needs a logged reason. */
    public function lock(Request $r, string $type, int $id)
    {
        abort_unless(in_array($type, ['semester', 'offering'], true), 404);
        $d = $r->validate(['action' => 'required|in:lock,reopen', 'version' => 'required|integer', 'reason' => 'required|string|min:10|max:1000', 'confirmed' => 'required|accepted']);
        $table = $this->table($type);
        DB::transaction(function () use ($table, $type, $id, $d) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if($row->version != (int) $d['version'], 409);
            $locking = $d['action'] === 'lock';
            if ($locking === ($row->status === 'locked')) {
                throw ValidationException::withMessages(['action' => $locking ? 'Data sudah terkunci.' : 'Data tidak sedang terkunci.']);
            }
            if (! $locking && $type === 'offering' && DB::table('semesters')->where('id', $row->semester_id)->value('status') === 'locked') {
                throw ValidationException::withMessages(['action' => 'Buka semester terlebih dahulu.']);
            }
            $v = ['status' => $locking ? 'locked' : 'active', 'version' => $row->version + 1, 'updated_at' => now()];
            DB::table($table)->where('id', $id)->update($v);
            Audit::record($type, $id, $locking ? 'master.locked' : 'master.reopened', ['status' => $row->status], ['status' => $v['status']], ($d['reason'] ?? null), $type === 'offering' ? $id : null);
        });

        return back()->with('success', $d['action'] === 'lock' ? 'Data dikunci. Seluruh perubahan akademik ditolak sampai dibuka kembali.' : 'Data dibuka kembali. Alasan tercatat di log aktivitas.');
    }
}
