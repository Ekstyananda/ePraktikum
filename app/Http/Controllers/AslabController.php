<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AslabController
{
    public function index(Request $r)
    {
        $data = $r->validate(['q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|in:10,25,50']);
        $users = User::where('role', 'aslab')->when($data['q'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))->orderBy('name')->paginate($data['per_page'] ?? 10)->withQueryString();

        return view('aslab.index', compact('users'));
    }

    public function create()
    {
        return view('aslab.create');
    }

    public function store(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()]]);
        $u = DB::transaction(function () use ($data, $r) {
            $u = User::create([...$data, 'role' => 'aslab', 'active' => true]);
            $this->audit($r, $u, 'account.created', null, ['name' => $u->name, 'email' => $u->email, 'active' => true]);

            return $u;
        });

        return redirect()->route('aslab.edit', $u)->with('success', 'Akun aslab dibuat. Sampaikan kata sandi melalui saluran pribadi.');
    }

    public function edit(User $aslab, StaffAccess $access)
    {
        abort_unless($aslab->role === 'aslab', 404);
        $offerings = $access->offerings(request()->user());
        $assignments = DB::table('staff_assignments')->where('user_id', $aslab->id)->get()->keyBy('offering_id');
        $scopes = DB::table('staff_session_scopes')->whereIn('assignment_id', $assignments->pluck('id'))->get()->groupBy('assignment_id');
        $permissions = DB::table('user_permissions')->whereIn('assignment_id', $assignments->pluck('id'))->get()->groupBy('assignment_id');
        $sessions = DB::table('practicum_sessions')->get()->groupBy('offering_id');

        return view('aslab.edit', compact('aslab', 'offerings', 'assignments', 'scopes', 'permissions', 'sessions'));
    }

    public function update(Request $r, User $aslab)
    {
        abort_unless($aslab->role === 'aslab', 404);
        $data = $r->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($aslab->id)],
            'active' => 'required|boolean',
            'version' => 'required|integer|min:1',
            'reason' => 'required|string|min:5|max:1000',
            'password' => ['nullable', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
            'assignments' => 'sometimes|array',
            'assignments.*' => 'array:offering_id,all_sessions,sessions,permissions',
            'assignments.*.offering_id' => 'required|integer|distinct|exists:practicum_offerings,id',
            'assignments.*.all_sessions' => 'required|boolean',
            'assignments.*.sessions' => 'sometimes|array',
            'assignments.*.sessions.*' => 'integer|distinct|exists:practicum_sessions,id',
            'assignments.*.permissions' => 'sometimes|array',
            'assignments.*.permissions.*' => ['string', Rule::in(array_keys(StaffAccess::PERMISSIONS))],
        ]);
        foreach ($data['assignments'] ?? [] as $i => $a) {
            $ids = $a['sessions'] ?? [];
            if (DB::table('practicum_sessions')->where('offering_id', $a['offering_id'])->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(["assignments.$i.sessions" => 'Sesi harus berasal dari praktikum yang sama.']);
            }
            if ($a['all_sessions'] && $ids) {
                throw ValidationException::withMessages(["assignments.$i.sessions" => 'Hapus pilihan sesi bila semua sesi diaktifkan.']);
            }
        }
        DB::transaction(function () use ($data, $aslab, $r) {
            $u = User::whereKey($aslab->id)->lockForUpdate()->firstOrFail();
            abort_if($u->version !== (int) $data['version'], 409, 'Akun telah diubah pengelola lain. Muat ulang sebelum menyimpan.');
            $before = $this->snapshot($u);
            $u->fill(['name' => $data['name'], 'email' => $data['email'], 'active' => $data['active']]);
            if (! empty($data['password'])) {
                $u->password = $data['password'];
                $u->remember_token = null;
            }
            $u->version++;
            $u->save();
            DB::table('staff_assignments')->where('user_id', $u->id)->delete();
            foreach ($data['assignments'] ?? [] as $a) {
                $id = DB::table('staff_assignments')->insertGetId(['user_id' => $u->id, 'offering_id' => $a['offering_id'], 'all_sessions' => $a['all_sessions'], 'created_at' => now(), 'updated_at' => now()]);
                foreach ($a['sessions'] ?? [] as $sid) {
                    DB::table('staff_session_scopes')->insert(['assignment_id' => $id, 'session_id' => $sid, 'offering_id' => $a['offering_id']]);
                }
                foreach (StaffAccess::PERMISSIONS as $key => $label) {
                    DB::table('user_permissions')->insert(['assignment_id' => $id, 'permission_key' => $key, 'allowed' => in_array($key, $a['permissions'] ?? [], true)]);
                }
            }
            $after = $this->snapshot($u);
            $after['password_reset'] = ! empty($data['password']);
            $this->audit($r, $u, 'account.updated', $before, $after, $data['reason']);
            if (! $u->active || ! empty($data['password'])) {
                DB::table('sessions')->where('user_id', $u->id)->delete();
            }
        });

        return redirect()->route('aslab.edit', $aslab)->with('success', 'Profil, penugasan, dan hak akses berhasil disimpan.');
    }

    private function snapshot(User $u): array
    {
        $a = DB::table('staff_assignments')->where('user_id', $u->id)->get();

        return ['name' => $u->name, 'email' => $u->email, 'active' => $u->active, 'version' => $u->version, 'assignments' => $a->map(fn ($x) => ['offering_id' => $x->offering_id, 'all_sessions' => (bool) $x->all_sessions, 'sessions' => DB::table('staff_session_scopes')->where('assignment_id', $x->id)->pluck('session_id')->all(), 'permissions' => DB::table('user_permissions')->where('assignment_id', $x->id)->pluck('allowed', 'permission_key')->all()])->all()];
    }

    private function audit(Request $r, User $u, string $action, ?array $before, array $after, ?string $reason = null): void
    {
        DB::table('activity_logs')->insert(['actor_id' => $r->user()->id, 'entity_type' => 'user', 'entity_id' => $u->id, 'action' => $action, 'before_json' => $before ? json_encode($before) : null, 'after_json' => json_encode($after), 'reason' => $reason, 'request_id' => (string) Str::uuid(), 'created_at' => now()]);
    }
}
