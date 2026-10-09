<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Backup;
use App\Services\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Backup status and manual trigger. Admin only, unless an assignment explicitly grants backup.run. */
class BackupController
{
    public static function allowed($user, StaffAccess $access): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return DB::table('staff_assignments')->where('user_id', $user->id)->pluck('offering_id')->contains(fn ($o) => $access->allowed($user, $o, 'backup.run'));
    }

    public function index(Request $r, Backup $backup, StaffAccess $access)
    {
        abort_unless(self::allowed($r->user(), $access), 403);
        $runs = DB::table('backup_runs as b')->leftJoin('users as u', 'u.id', '=', 'b.initiated_by')->select('b.*', 'u.name as initiator')->orderByDesc('b.id')->paginate(15);

        return view('backup.index', [
            'runs' => $runs, 'problems' => $backup->problems(), 'enabled' => (bool) config('backup.enabled'), 'label' => config('backup.label'),
            'time' => $backup->time(), 'retention' => $backup->retention(), 'encrypted' => (bool) config('backup.password'),
            'changeThreshold' => (int) config('backup.change_threshold'), 'changeInterval' => (int) config('backup.change_min_interval'), 'pendingChanges' => $backup->pendingChanges(),
            'lastSuccess' => DB::table('backup_runs')->where('status', 'success')->orderByDesc('finished_at')->first(),
            'lastFailure' => DB::table('backup_runs')->where('status', 'failed')->orderByDesc('finished_at')->first(),
            'active' => DB::table('backup_runs')->whereIn('status', ['queued', 'running'])->exists(),
            'settingsVersion' => (int) DB::table('settings')->where('key', 'backup.time')->value('version'),
        ]);
    }

    public function run(Request $r, Backup $backup, StaffAccess $access)
    {
        abort_unless(self::allowed($r->user(), $access), 403);
        $r->validate(['confirmed' => 'required|accepted']);
        if ($problems = $backup->problems()) {
            return back()->withErrors(['backup' => implode(' ', $problems)]);
        }
        $id = DB::transaction(function () use ($r, $backup) {
            // Serialize clicks so only one manual run waits in the queue.
            DB::table('backup_runs')->lockForUpdate()->whereIn('status', ['queued', 'running'])->count();
            if (DB::table('backup_runs')->whereIn('status', ['queued', 'running'])->exists()) {
                return null;
            }
            $id = $backup->queue('manual', $r->user()->id);
            Audit::record('backup_run', $id, 'backup.queued', null, ['trigger' => 'manual']);

            return $id;
        });

        return back()->with('success', $id ? 'Backup diantrekan. Scheduler menjalankannya di latar belakang dalam ±1 menit.' : 'Backup lain sedang diantrekan atau berjalan.');
    }

    public function settings(Request $r, Backup $backup)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'retention' => 'required|integer|min:1|max:90', 'version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($backup, $d) {
            $current = DB::table('settings')->where('key', 'backup.time')->lockForUpdate()->first();
            abort_unless((int) ($current?->version ?? 0) === (int) $d['version'], 409);
            $before = ['time' => $backup->time(), 'retention' => $backup->retention()];
            foreach (['time' => $d['time'], 'retention' => (int) $d['retention']] as $key => $value) {
                $old = DB::table('settings')->where('key', 'backup.'.$key)->first();
                DB::table('settings')->updateOrInsert(['key' => 'backup.'.$key], ['value_json' => json_encode($value), 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()] + ($old ? [] : ['created_at' => now()]));
            }
            Audit::record('setting', 0, 'backup.settings_saved', $before, ['time' => $d['time'], 'retention' => (int) $d['retention']], ($d['reason'] ?? null));
        });

        return back()->with('success', 'Jadwal dan retensi backup disimpan.');
    }
}
