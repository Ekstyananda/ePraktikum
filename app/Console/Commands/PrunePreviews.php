<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrunePreviews extends Command
{
    protected $signature = 'portal:prune-previews';

    protected $description = 'Hapus staging impor/seleksi yang kedaluwarsa; tidak mengubah data praktikan';

    public function handle(): int
    {
        $count = DB::table('import_previews')->where('expires_at', '<=', now())->delete();
        $this->info("$count pratinjau kedaluwarsa dibersihkan.");

        return self::SUCCESS;
    }
}
