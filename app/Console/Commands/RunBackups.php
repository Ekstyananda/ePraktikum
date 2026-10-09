<?php

namespace App\Console\Commands;

use App\Services\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RunBackups extends Command
{
    protected $signature = 'portal:backup {--queued : Proses backup manual dalam antrean} {--scheduled : Antrekan backup harian bila sudah waktunya} {--now : Antrekan dan jalankan satu backup manual sekarang}';

    protected $description = 'Backup database dan berkas privat ke folder tujuan; dijalankan scheduler tanpa memblokir request HTTP';

    public function handle(Backup $backup): int
    {
        if ($this->option('scheduled') && $backup->scheduleDue()) {
            $backup->queue('scheduled', null);
            $this->info('Backup harian diantrekan.');
        } elseif ($this->option('scheduled') && $backup->changesDue()) {
            $backup->queue('changes', null);
            $this->info('Backup karena perubahan data diantrekan.');
        }
        if ($this->option('now')) {
            $backup->queue('cli', null);
        }
        // One runner at a time across processes/containers.
        $lock = Cache::lock('portal-backup-runner', 3600);
        if (! $lock->get()) {
            $this->warn('Backup lain sedang berjalan.');

            return self::SUCCESS;
        }
        try {
            $status = self::SUCCESS;
            while ($id = DB::table('backup_runs')->where('status', 'queued')->orderBy('id')->value('id')) {
                $run = $backup->run($id);
                $this->line("Backup #{$run->id}: {$run->status}".($run->status === 'success' ? " ({$run->artifact_ref}, sha256 {$run->checksum})" : ' — '.$run->error_summary));
                $status = $run->status === 'success' ? $status : self::FAILURE;
            }

            return $status;
        } finally {
            $lock->release();
        }
    }
}
