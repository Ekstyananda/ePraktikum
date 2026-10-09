<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public identity of each practicum: slug registry, active offering, colours, cover and enabled services.
 * The offering resolved from the URL is the server-side reference for every public read and write.
 */
class PracticumPortal
{
    /** Paths used by fixed routes; a practicum can never take them as slug. */
    public const RESERVED = ['login', 'logout', 'dashboard', 'praktikum', 'pengaturan', 'cek-status', 'pengumuman', 'modul', 'jadwal', 'pengajuan', 'remidi', 'pengumpulan', 'up', 'health', 'vendor', 'assets', 'storage', 'sampul', 'favicon-ico', 'robots-txt', 'api', 'admin'];

    /** Accent palette; every colour keeps white text at WCAG AA contrast. */
    public const PALETTE = [
        'blue' => ['label' => 'Biru', 'accent' => '#2563eb', 'dark' => '#1e40af', 'soft' => '#eaf1fe'],
        'teal' => ['label' => 'Toska', 'accent' => '#0f766e', 'dark' => '#115e59', 'soft' => '#e6f6f4'],
        'green' => ['label' => 'Hijau', 'accent' => '#15803d', 'dark' => '#166534', 'soft' => '#e8f7ed'],
        'purple' => ['label' => 'Ungu', 'accent' => '#7c3aed', 'dark' => '#5b21b6', 'soft' => '#f1ebfe'],
        'orange' => ['label' => 'Oranye', 'accent' => '#c2410c', 'dark' => '#9a3412', 'soft' => '#fdf0e8'],
        'rose' => ['label' => 'Merah muda', 'accent' => '#be123c', 'dark' => '#9f1239', 'soft' => '#fdecef'],
    ];

    public const PRESETS = ['database' => 'Basis data', 'image' => 'Citra digital', 'network' => 'Jaringan', 'code' => 'Pemrograman', 'circuit' => 'Sistem/elektronika'];

    public const SERVICES = ['jadwal' => 'Jadwal', 'modul' => 'Modul & Soal', 'pengumpulan' => 'Pengumpulan tugas digital', 'pengajuan' => 'Pengajuan (izin, pindah, susulan)', 'remidi' => 'Remidi'];

    public static function services(object $practicum): array
    {
        $saved = $practicum->services ? json_decode($practicum->services, true) : [];

        return array_map(fn ($key) => (bool) ($saved[$key] ?? true), array_combine(array_keys(self::SERVICES), array_keys(self::SERVICES)));
    }

    public static function palette(object $practicum): array
    {
        return self::PALETTE[$practicum->accent] ?? self::PALETTE['blue'];
    }

    public static function displayName(object $practicum): string
    {
        return $practicum->display_name ?: $practicum->name;
    }

    /**
     * The one public offering of a practicum: active, semester not locked; newest semester (starts_at), then highest id.
     */
    public function activeOffering(int $practicum): ?object
    {
        return DB::table('practicum_offerings as o')->join('semesters as s', 's.id', '=', 'o.semester_id')
            ->where('o.practicum_id', $practicum)->where('o.status', 'active')->where('s.status', '!=', 'locked')
            ->orderByRaw('s.starts_at IS NULL')->orderByDesc('s.starts_at')->orderByDesc('o.id')
            ->select('o.id', 'o.practicum_id', 's.label as semester')->first();
    }

    /** Practicums shown on the hub: those with a public offering. */
    public function listed()
    {
        // A practicum without a registered slug has no public address yet.
        return DB::table('practicums')->whereNotNull('slug')->orderBy('name')->get()
            ->map(fn ($p) => ($o = $this->activeOffering($p->id)) ? tap($p, fn ($p) => $p->offering = $o) : null)->filter()->values();
    }

    /**
     * Resolve a slug (current or alias) to ['practicum', 'offering', 'current_slug', 'alias'].
     * Returns null when unknown or when the practicum has no public offering.
     */
    public function resolve(string $slug): ?array
    {
        $row = DB::table('practicum_slugs')->where('slug', Str::lower($slug))->first();
        if (! $row) {
            return null;
        }
        $practicum = DB::table('practicums')->where('id', $row->practicum_id)->first();
        $offering = $practicum ? $this->activeOffering($practicum->id) : null;
        if (! $offering) {
            return null;
        }

        return ['practicum' => $practicum, 'offering' => $offering, 'current_slug' => $practicum->slug, 'alias' => ! $row->is_current];
    }

    /** Context for an offering id, used when a legacy URL or a form carries only the offering. */
    public function forOffering(int $offering): ?array
    {
        $practicum = DB::table('practicums as p')->join('practicum_offerings as o', 'o.practicum_id', '=', 'p.id')->where('o.id', $offering)->select('p.*')->first();
        $active = $practicum && $practicum->slug ? $this->activeOffering($practicum->id) : null;

        return $active && $active->id === $offering ? ['practicum' => $practicum, 'offering' => $active, 'current_slug' => $practicum->slug, 'alias' => false] : null;
    }

    public function validateSlug(string $slug): string
    {
        $slug = Str::lower(trim($slug));
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) < 2 || strlen($slug) > 50) {
            throw ValidationException::withMessages(['slug' => 'Slug 2–50 karakter: huruf kecil, angka dan tanda strip.']);
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw ValidationException::withMessages(['slug' => 'Slug ini dipakai halaman sistem. Pilih slug lain.']);
        }

        return $slug;
    }

    /**
     * Change the current slug inside the caller's transaction. The old slug stays as alias and stays reserved.
     * Uniqueness across current and alias slugs is guaranteed by the single registry table.
     */
    public function changeSlug(int $practicum, string $slug): bool
    {
        $slug = $this->validateSlug($slug);
        $p = DB::table('practicums')->where('id', $practicum)->lockForUpdate()->firstOrFail();
        if ($p->slug === $slug) {
            return false;
        }
        $existing = DB::table('practicum_slugs')->where('slug', $slug)->first();
        if ($existing && $existing->practicum_id !== $practicum) {
            throw ValidationException::withMessages(['slug' => 'Slug sudah dipakai atau pernah dipakai praktikum lain.']);
        }
        DB::table('practicum_slugs')->where('practicum_id', $practicum)->where('is_current', true)->update(['is_current' => false, 'updated_at' => now()]);
        try {
            if ($existing) {
                DB::table('practicum_slugs')->where('id', $existing->id)->update(['is_current' => true, 'updated_at' => now()]);
            } else {
                DB::table('practicum_slugs')->insert(['slug' => $slug, 'practicum_id' => $practicum, 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        } catch (QueryException $e) {
            // A concurrent save claimed the same slug first.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['slug' => 'Slug baru saja dipakai pengguna lain. Pilih slug lain.']);
            }
            throw $e;
        }
        DB::table('practicums')->where('id', $practicum)->update(['slug' => $slug]);

        return true;
    }

    /** Registers the first slug of a newly created practicum (inside the caller's transaction). */
    public function registerInitial(int $practicum, string $code): void
    {
        $base = Str::slug($code);
        if ($base === '' || in_array($base, self::RESERVED, true) || strlen($base) > 50) {
            $base = 'praktikum-'.$practicum;
        }
        $slug = $base;
        for ($n = 2; DB::table('practicum_slugs')->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }
        DB::table('practicum_slugs')->insert(['slug' => $slug, 'practicum_id' => $practicum, 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('practicums')->where('id', $practicum)->update(['slug' => $slug, 'short_name' => Str::upper(Str::limit($code, 20, ''))]);
    }

    /** Inline SVG covers; colours follow the practicum accent. */
    public static function presetSvg(string $preset, array $c): string
    {
        $a = $c['accent'];
        $d = $c['dark'];
        $body = match ($preset) {
            'image' => "<rect x='60' y='45' width='140' height='105' rx='12' fill='$a'/><circle cx='100' cy='80' r='14' fill='#fff' opacity='.9'/><path d='M70 140l38-42 26 28 18-18 38 32z' fill='$d'/><rect x='24' y='112' width='62' height='72' rx='8' fill='#fff'/><path d='M36 130h38M36 146h38M36 162h24' stroke='#cbd5e1' stroke-width='5' stroke-linecap='round'/>",
            'network' => "<circle cx='130' cy='60' r='18' fill='$d'/><circle cx='75' cy='140' r='18' fill='$a'/><circle cx='185' cy='140' r='18' fill='$a'/><path d='M130 78L80 124M130 78l50 46M93 140h74' stroke='$d' stroke-width='6'/><rect x='20' y='112' width='54' height='70' rx='8' fill='#fff' opacity='.95'/>",
            'code' => "<rect x='55' y='45' width='150' height='110' rx='12' fill='$d'/><path d='M100 82l-20 18 20 18M160 82l20 18-20 18M138 76l-16 48' stroke='#fff' stroke-width='7' fill='none' stroke-linecap='round'/><rect x='24' y='118' width='62' height='68' rx='8' fill='#fff'/>",
            'circuit' => "<rect x='80' y='55' width='100' height='90' rx='10' fill='$a'/><rect x='105' y='80' width='50' height='40' rx='6' fill='$d'/><path d='M80 75H52M80 100H40M80 125H52M180 75h28M180 100h40M180 125h28M110 55V32M150 55V32M110 145v23M150 145v23' stroke='$d' stroke-width='6' stroke-linecap='round'/>",
            default => "<path d='M75 55v100c0 30 110 30 110 0V55' fill='$a'/><ellipse cx='130' cy='55' rx='55' ry='23' fill='$d'/><path d='M75 88c0 30 110 30 110 0M75 121c0 30 110 30 110 0' stroke='#fff' stroke-opacity='.6' stroke-width='3' fill='none'/><rect x='22' y='108' width='70' height='78' rx='8' fill='#fff'/><path d='M36 128h42M36 144h42M36 160h26' stroke='#cbd5e1' stroke-width='5' stroke-linecap='round'/>",
        };

        return "<svg width='240' height='200' viewBox='0 0 240 200' role='img' aria-hidden='true'><circle cx='130' cy='100' r='92' fill='{$c['soft']}'/>$body</svg>";
    }
}
