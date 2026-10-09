<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class StaffAccess
{
    public const PERMISSIONS = [
        'students.manage' => 'Kelola praktikan & dosen pembimbing',
        'sessions.manage' => 'Kelola sesi dan jadwal',
        'materials.manage' => 'Kelola pertemuan dan modul',
        'attendance.manage' => 'Kelola presensi',
        'submissions.manage' => 'Kelola pengumpulan',
        'grades.manage' => 'Input dan koreksi nilai',
        'requests.manage' => 'Kelola pengajuan, susulan dan remidi',
        'announcements.manage' => 'Kelola pengumuman',
        'reports.export' => 'Rekap dan ekspor',
        'grading_rules.manage' => 'Ubah bobot dan aturan kelulusan',
        'backup.run' => 'Jalankan backup',
        'logs.view' => 'Lihat log aktivitas praktikum',
        'portal.manage' => 'Kelola tampilan portal praktikum',
    ];

    public const STANDARD = ['students.manage', 'sessions.manage', 'materials.manage', 'attendance.manage', 'submissions.manage', 'grades.manage', 'requests.manage', 'announcements.manage', 'reports.export'];

    public function allowed(User $user, int $offering, string $permission, ?int $session = null): bool
    {
        // Re-read account and grants for immediate revocation, including existing sessions.
        $user = User::find($user->id);
        if (! $user || ! $user->active || ! array_key_exists($permission, self::PERMISSIONS)) {
            return false;
        }
        if (! DB::table('practicum_offerings')->where('id', $offering)->exists()) {
            return false;
        }
        if ($session !== null && ! DB::table('practicum_sessions')->where('id', $session)->where('offering_id', $offering)->exists()) {
            return false;
        }
        if ($user->role === 'admin') {
            return true;
        }
        $a = DB::table('staff_assignments')->where('user_id', $user->id)->where('offering_id', $offering)->first();
        if (! $a) {
            return false;
        }
        if (! $a->all_sessions && ($session === null || ! DB::table('staff_session_scopes')->where('assignment_id', $a->id)->where('session_id', $session)->exists())) {
            return false;
        }
        $override = DB::table('user_permissions')->where('assignment_id', $a->id)->where('permission_key', $permission)->first();

        return $override ? (bool) $override->allowed : in_array($permission, self::STANDARD, true);
    }

    public function offerings(User $user)
    {
        $query = DB::table('practicum_offerings as o')->join('practicums as p', 'p.id', '=', 'o.practicum_id')->join('semesters as s', 's.id', '=', 'o.semester_id')->select('o.*', 'p.name', 's.label as semester');
        if ($user->role !== 'admin') {
            $query->whereIn('o.id', DB::table('staff_assignments')->where('user_id', $user->id)->select('offering_id'));
        }

        return $query->orderBy('o.id')->get();
    }

    public function sessions(User $user, int $offering)
    {
        $q = DB::table('practicum_sessions')->where('offering_id', $offering);
        if ($user->role !== 'admin') {
            $a = DB::table('staff_assignments')->where('user_id', $user->id)->where('offering_id', $offering)->first();
            if (! $a) {
                return collect();
            }
            if (! $a->all_sessions) {
                $q->whereIn('id', DB::table('staff_session_scopes')->where('assignment_id', $a->id)->select('session_id'));
            }
        }

        return $q->orderBy('label')->get();
    }
}
