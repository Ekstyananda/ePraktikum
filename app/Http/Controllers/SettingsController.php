<?php

namespace App\Http\Controllers;

use App\Services\Backup;
use Illuminate\Support\Facades\DB;

/** Admin settings hub: semester/offering identity and lock state, accounts, logs and backup. */
class SettingsController
{
    public function index(Backup $backup)
    {
        return view('settings.index', [
            'semesters' => DB::table('semesters')->orderByDesc('id')->limit(5)->get(),
            'counts' => ['aslab' => DB::table('users')->where('role', 'aslab')->where('active', true)->count(), 'offerings' => DB::table('practicum_offerings')->count(), 'logs' => DB::table('activity_logs')->where('created_at', '>=', now()->subDay())->count()],
            'lastBackup' => DB::table('backup_runs')->where('status', 'success')->orderByDesc('finished_at')->value('finished_at'),
            'backupProblems' => $backup->problems(),
        ]);
    }
}
