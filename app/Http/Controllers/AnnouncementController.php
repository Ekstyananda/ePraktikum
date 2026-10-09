<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\PracticumPortal;
use App\Services\Roster;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Announcements: staff editor per offering (admin may publish general ones) and the public list/detail. */
class AnnouncementController
{
    public const AUDIENCES = ['public' => 'Publik (portal praktikan)', 'staff' => 'Internal pengelola'];

    public const STATES = ['draft' => 'Draf', 'published' => 'Terbit', 'archived' => 'Diarsipkan'];

    /** Markdown without raw HTML or unsafe links. */
    public static function render(string $body): string
    {
        return Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 10]);
    }

    private function authorizeOffering(Request $r, int $o, Roster $roster): void
    {
        // Announcements are shared by all sessions, so the permission must cover the whole offering.
        abort_unless($roster->access->allowed($r->user(), $o, 'announcements.manage'), 403);
    }

    public function index(Request $r, int $offering, Roster $roster)
    {
        $this->authorizeOffering($r, $offering, $roster);
        $d = $r->validate(['status' => ['nullable', Rule::in(array_keys(self::STATES))]]);
        $q = DB::table('announcements as a')->join('users as u', 'u.id', '=', 'a.created_by')
            ->where(fn ($q) => $q->where('a.offering_id', $offering)->orWhereNull('a.offering_id'))
            ->when(! empty($d['status']), fn ($q) => $q->where('a.status', $d['status']))
            ->select('a.*', 'u.name as author')->orderByRaw("a.status = 'draft' desc")->orderByDesc('a.updated_at');

        return view('announcements.index', ['o' => $roster->offering($offering), 'rows' => $q->paginate(PerPage::from($r))->withQueryString()]);
    }

    public function form(Request $r, int $offering, Roster $roster, ?int $id = null)
    {
        $this->authorizeOffering($r, $offering, $roster);
        $row = $id ? $this->find($r, $offering, $id) : null;

        return view('announcements.form', ['o' => $roster->offering($offering), 'row' => $row]);
    }

    private function find(Request $r, int $o, int $id, bool $lock = false): object
    {
        $q = DB::table('announcements')->where('id', $id)->where(fn ($q) => $q->where('offering_id', $o)->orWhereNull('offering_id'));
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($row, 404);
        // General announcements belong to admin.
        abort_if($row->offering_id === null && $r->user()->role !== 'admin', 403);

        return $row;
    }

    public function save(Request $r, int $offering, Roster $roster, ?int $id = null)
    {
        $this->authorizeOffering($r, $offering, $roster);
        $d = $r->validate([
            'title' => 'required|string|max:180', 'body' => 'required|string|max:20000', 'audience' => ['required', Rule::in(array_keys(self::AUDIENCES))],
            'general' => 'nullable|boolean', 'action' => 'required|in:draft,publish', 'version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'nullable|boolean',
        ]);
        if (! empty($d['general']) && $r->user()->role !== 'admin') {
            throw ValidationException::withMessages(['general' => 'Hanya admin yang dapat membuat pengumuman umum.']);
        }
        $published = $id && DB::table('announcements')->where('id', $id)->value('status') === 'published';
        if (($d['action'] === 'publish' || $published) && empty($d['confirmed'])) {
            throw ValidationException::withMessages(['confirmed' => 'Centang konfirmasi sebelum menerbitkan atau mengubah pengumuman yang sudah terbit.']);
        }
        $id = DB::transaction(function () use ($r, $offering, $roster, $d, $id) {
            $roster->writable($offering);
            $old = $id ? $this->find($r, $offering, $id, true) : null;
            abort_unless(($old?->version ?? 0) === (int) $d['version'], 409);
            if ($old && $old->status === 'archived') {
                throw ValidationException::withMessages(['title' => 'Pengumuman yang diarsipkan tidak dapat diubah.']);
            }
            $publish = $d['action'] === 'publish';
            $v = ['offering_id' => empty($d['general']) ? $offering : null, 'title' => $d['title'], 'body' => $d['body'], 'audience' => $d['audience'],
                'status' => $publish ? 'published' : ($old?->status ?? 'draft'), 'published_at' => $publish ? ($old?->published_at ?? now()) : $old?->published_at,
                'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                DB::table('announcements')->where('id', $old->id)->update($v);
                $id = $old->id;
            } else {
                $id = DB::table('announcements')->insertGetId($v + ['created_by' => $r->user()->id, 'created_at' => now()]);
            }
            Audit::record('announcement', $id, $old ? 'announcement.updated' : 'announcement.created', $old ? (array) $old : null, $v, $d['reason'] ?? null, $offering);

            return $id;
        });

        return redirect()->route('announcements.edit', [$offering, $id])->with('success', $d['action'] === 'publish' ? 'Pengumuman diterbitkan.' : 'Draf pengumuman disimpan.');
    }

    public function archive(Request $r, int $offering, int $id, Roster $roster)
    {
        $this->authorizeOffering($r, $offering, $roster);
        $d = $r->validate(['version' => 'required|integer', 'reason' => 'nullable|string|max:1000', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $offering, $id, $roster, $d) {
            $roster->writable($offering);
            $old = $this->find($r, $offering, $id, true);
            abort_unless($old->version === (int) $d['version'] && $old->status !== 'archived', 409);
            $v = ['status' => 'archived', 'archived_at' => now(), 'version' => $old->version + 1, 'updated_at' => now()];
            DB::table('announcements')->where('id', $id)->update($v);
            Audit::record('announcement', $id, 'announcement.archived', ['status' => $old->status], $v, ($d['reason'] ?? null), $offering);
        });

        return redirect()->route('announcements.index', $offering)->with('success', 'Pengumuman diarsipkan dan tidak lagi tampil.');
    }

    /** Public announcements: general ones, plus those of one portal offering when given. */
    public static function publicQuery(?int $offering = null)
    {
        return DB::table('announcements as a')->leftJoin('practicum_offerings as o', 'o.id', '=', 'a.offering_id')->leftJoin('practicums as p', 'p.id', '=', 'o.practicum_id')
            ->where('a.status', 'published')->where('a.audience', 'public')->whereNotNull('a.published_at')->where('a.published_at', '<=', now())
            ->where(fn ($q) => $offering ? $q->whereNull('a.offering_id')->orWhere('a.offering_id', $offering) : $q->whereNull('a.offering_id'))
            ->select('a.id', 'a.title', 'a.body', 'a.published_at', 'a.offering_id', 'p.name as practicum')->orderByDesc('a.published_at');
    }

    public function publicIndex(Request $r)
    {
        $ctx = $r->attributes->get('portal');

        return view('announcements.public', ['rows' => self::publicQuery($ctx['offering']->id ?? null)->paginate(10), 'row' => null]);
    }

    public function publicShow(Request $r, PracticumPortal $portal)
    {
        $id = (int) $r->route('id');
        $ctx = $r->attributes->get('portal');
        if ($ctx) {
            // Inside a portal: general announcements or this offering's only.
            $row = self::publicQuery($ctx['offering']->id)->where('a.id', $id)->first();
            abort_unless($row, 404);

            return view('announcements.public', ['rows' => null, 'row' => $row]);
        }
        $row = self::publicQuery()->where('a.id', $id)->first();
        if (! $row) {
            // Old link to a practicum announcement: forward to its portal when still public.
            $offering = DB::table('announcements')->where('id', $id)->where('status', 'published')->where('audience', 'public')->value('offering_id');
            $target = $offering ? $portal->forOffering($offering) : null;
            abort_unless($target && self::publicQuery($offering)->where('a.id', $id)->exists(), 404);

            return redirect()->route('portal.announcement', [$target['current_slug'], $id], 301);
        }

        return view('announcements.public', ['rows' => null, 'row' => $row]);
    }
}
