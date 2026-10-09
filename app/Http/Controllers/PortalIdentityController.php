<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\PracticumPortal;
use App\Services\Roster;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Tampilan Portal": public identity of a practicum. Settings live on the practicum, so they apply to every semester.
 * Admin always; an aslab needs portal.manage on this offering with all sessions. Only admin changes the slug.
 */
class PortalIdentityController
{
    public const COVER_MIN = [600, 300];

    public const COVER_MAX = [2400, 1200];

    private function authorize(Request $r, int $offering, Roster $roster): object
    {
        abort_unless($r->user()->role === 'admin' || $roster->access->allowed($r->user(), $offering, 'portal.manage'), 403);

        return DB::table('practicums as p')->join('practicum_offerings as o', 'o.practicum_id', '=', 'p.id')->where('o.id', $offering)->select('p.*')->firstOrFail();
    }

    public function edit(Request $r, int $offering, Roster $roster, PracticumPortal $portal)
    {
        $p = $this->authorize($r, $offering, $roster);
        $active = $portal->activeOffering($p->id);

        return view('portal-identity.edit', [
            'o' => $roster->offering($offering), 'p' => $p, 'services' => PracticumPortal::services($p), 'active' => $active,
            'isPublicOffering' => $active && $active->id === $offering,
            'aliases' => DB::table('practicum_slugs')->where('practicum_id', $p->id)->where('is_current', false)->orderBy('slug')->pluck('slug'),
            'semesters' => DB::table('practicum_offerings as o')->join('semesters as s', 's.id', '=', 'o.semester_id')->where('o.practicum_id', $p->id)->count(),
        ]);
    }

    public function update(Request $r, int $offering, Roster $roster, PracticumPortal $portal)
    {
        $p = $this->authorize($r, $offering, $roster);
        $isAdmin = $r->user()->role === 'admin';
        $d = $r->validate([
            'display_name' => 'nullable|string|max:150', 'short_name' => 'required|string|max:20', 'tagline' => 'nullable|string|max:255',
            'accent' => ['required', Rule::in(array_keys(PracticumPortal::PALETTE))], 'hero_preset' => ['required', Rule::in(array_keys(PracticumPortal::PRESETS))],
            'hero_mode' => 'required|in:preset,keep,upload', 'cover' => 'nullable|required_if:hero_mode,upload|file|mimes:png,jpg,jpeg,webp|max:1024',
            'contact' => 'nullable|string|max:500', 'contact_url' => 'nullable|url:https|max:255',
            'services' => 'nullable|array', 'services.*' => ['string', Rule::in(array_keys(PracticumPortal::SERVICES))],
            'slug' => 'nullable|string|max:60', 'reason' => 'nullable|string|max:1000',
            'version' => 'required|integer', 'confirmed' => 'required|accepted',
        ]);
        $slugChange = $isAdmin && ! empty($d['slug']) && Str::lower(trim($d['slug'])) !== $p->slug;
        if (! $isAdmin && ! empty($d['slug']) && Str::lower(trim($d['slug'])) !== $p->slug) {
            throw ValidationException::withMessages(['slug' => 'Hanya admin yang dapat mengubah alamat portal.']);
        }
        // Changing the address breaks nothing (old links redirect) but is a crucial change: reason required.
        if ($slugChange && mb_strlen(trim((string) ($d['reason'] ?? ''))) < 10) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib (minimal 10 karakter) saat mengubah alamat portal.']);
        }
        $cover = $d['hero_mode'] === 'upload' ? $this->reencode($r->file('cover')) : null;
        try {
            DB::transaction(function () use ($p, $d, $cover, $slugChange, $portal, $offering) {
                $old = DB::table('practicums')->where('id', $p->id)->lockForUpdate()->first();
                abort_unless($old->portal_version === (int) $d['version'], 409);
                if ($slugChange) {
                    $portal->changeSlug($p->id, $d['slug']);
                }
                $heroFile = match ($d['hero_mode']) {
                    'upload' => $cover['id'],
                    'keep' => $old->hero_file_id,
                    default => null,
                };
                if ($cover) {
                    DB::table('files')->insert($cover);
                }
                $v = [
                    'display_name' => ($d['display_name'] ?? null) ?: null, 'short_name' => $d['short_name'], 'tagline' => ($d['tagline'] ?? null) ?: null, 'accent' => $d['accent'],
                    'hero_preset' => $d['hero_preset'], 'hero_file_id' => $heroFile, 'contact' => ($d['contact'] ?? null) ?: null, 'contact_url' => ($d['contact_url'] ?? null) ?: null,
                    'services' => json_encode(array_map(fn ($key) => in_array($key, $d['services'] ?? [], true), array_combine(array_keys(PracticumPortal::SERVICES), array_keys(PracticumPortal::SERVICES)))),
                    'portal_version' => $old->portal_version + 1, 'updated_at' => now(),
                ];
                DB::table('practicums')->where('id', $p->id)->update($v);
                $before = array_intersect_key((array) $old, $v);
                Audit::record('practicum', $p->id, $slugChange ? 'portal.slug_changed' : 'portal.updated', $before + ['slug' => $old->slug], $v + ['slug' => DB::table('practicums')->where('id', $p->id)->value('slug')], $d['reason'] ?? null, $offering);
            }, 3);
        } catch (\Throwable $e) {
            if ($cover) {
                Storage::disk('local')->delete($cover['storage_path']);
            }
            throw $e;
        }

        return back()->with('success', 'Tampilan portal disimpan. Perubahan berlaku untuk semua semester praktikum ini.');
    }

    /**
     * Read the image and store a fresh WebP copy: drops metadata and anything appended to the file.
     * Requires GD with WebP; SVG and other formats never reach this point (mimes rule).
     */
    private function reencode(UploadedFile $file): array
    {
        if (! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['cover' => 'Server belum mendukung pemrosesan gambar (ekstensi GD). Gunakan ilustrasi bawaan.']);
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['cover' => 'Berkas bukan gambar PNG, JPG atau WebP yang valid.']);
        }
        [$w, $h] = $info;
        if ($w < self::COVER_MIN[0] || $h < self::COVER_MIN[1] || $w > self::COVER_MAX[0] || $h > self::COVER_MAX[1]) {
            throw ValidationException::withMessages(['cover' => sprintf('Ukuran gambar %d×%d px. Gunakan antara %d×%d dan %d×%d px.', $w, $h, ...self::COVER_MIN, ...self::COVER_MAX)]);
        }
        $src = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            default => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $src) {
            throw ValidationException::withMessages(['cover' => 'Gambar tidak dapat dibaca.']);
        }
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagewebp($canvas, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($canvas);
        $id = (string) Str::uuid();
        $path = 'portal/covers/'.$id.'.webp';
        Storage::disk('local')->put($path, $bytes);

        return ['id' => $id, 'storage_path' => $path, 'original_name' => 'sampul.webp', 'mime' => 'image/webp', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'visibility' => 'private', 'uploaded_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()];
    }

    /** Public cover image, only for practicums that currently have a public portal. */
    public function cover(int $practicum, PracticumPortal $portal)
    {
        $p = DB::table('practicums')->where('id', $practicum)->first();
        abort_unless($p && $p->hero_file_id && $portal->activeOffering($p->id), 404);
        $f = DB::table('files')->where('id', $p->hero_file_id)->first();
        abort_unless($f && $f->mime === 'image/webp' && Storage::disk('local')->exists($f->storage_path), 404);

        return response(Storage::disk('local')->get($f->storage_path), 200, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=86400', 'Content-Disposition' => 'inline; filename="sampul.webp"']);
    }
}
