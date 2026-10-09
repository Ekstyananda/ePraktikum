<?php

namespace App\Console\Commands;

use App\Services\Backup;
use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'portal:backup-verify {archive : Nama arsip di folder backup atau path lengkap} {--restore : Pulihkan ke koneksi "restore" yang terisolasi lalu bandingkan jumlah baris}';

    protected $description = 'Periksa checksum arsip backup dan, opsional, uji restore ke database terisolasi (bukan database aplikasi)';

    public function handle(Backup $backup): int
    {
        $path = $this->argument('archive');
        if (! str_contains($path, '/')) {
            $path = $backup->destination().'/'.$path;
        }
        if (! is_file($path)) {
            $this->error('Arsip tidak ditemukan.');

            return self::FAILURE;
        }
        $sidecar = $path.'.sha256';
        if (is_file($sidecar) && strtok((string) file_get_contents($sidecar), ' ') !== hash_file('sha256', $path)) {
            $this->error('SHA-256 arsip tidak cocok dengan berkas .sha256.');

            return self::FAILURE;
        }
        try {
            if (! $this->option('restore')) {
                $m = $backup->verify($path);
                $this->info('Arsip valid: '.count($m['tables']).' tabel, '.array_sum($m['tables']).' baris, '.count($m['files']).' berkas.');

                return self::SUCCESS;
            }
            $result = $backup->restoreInto($path, 'restore');
            if ($result['mismatch']) {
                $this->error('Jumlah baris berbeda: '.json_encode($result['mismatch']));

                return self::FAILURE;
            }
            $this->info('Restore terverifikasi ke database "'.config('database.connections.restore.database').'": '.count($result['manifest']['tables']).' tabel dan jumlah baris cocok; '.count($result['manifest']['files']).' checksum berkas cocok.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
