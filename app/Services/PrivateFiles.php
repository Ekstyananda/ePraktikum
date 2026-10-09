<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivateFiles
{
    public function stage(UploadedFile $upload): array
    {
        $id = (string) Str::uuid();
        $path = 'academic/'.$id;
        $disk = Storage::disk('local');
        $stream = fopen($upload->getRealPath(), 'rb');
        try {
            $ok = $disk->put($path, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }if (! $ok) {
            throw new \RuntimeException('Penyimpanan berkas privat gagal.');
        }
        $name = Str::limit(preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', '_', $upload->getClientOriginalName()), 170, '');

        return ['id' => $id, 'storage_path' => $path, 'original_name' => $name, 'mime' => $upload->getMimeType(), 'size' => $upload->getSize(), 'checksum' => hash_file('sha256', $upload->getRealPath()), 'visibility' => 'private', 'uploaded_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()];
    }

    public function discard(array $file): void
    {
        Storage::disk('local')->delete($file['storage_path']);
    }

    public function download(string $id, bool $public = false)
    {
        $f = DB::table('files')->where('id', $id)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($f->storage_path), 404);

        return Storage::disk('local')->download($f->storage_path, $f->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $public ? 'no-store' : 'private, no-store']);
    }
}
