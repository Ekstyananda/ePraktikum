<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\MeetingRoster;
use App\Services\PracticumPortal;
use App\Services\PrivateFiles;
use App\Services\Roster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterialController
{
    private function shared(Request $r, int $o, Roster $roster): void
    {
        abort_unless($roster->access->allowed($r->user(), $o, 'materials.manage'), 403);
    }

    public function index(Request $r, int $offering, int $meeting, MeetingRoster $service)
    {
        $service->roster->require($r->user(), $offering, 'materials.manage');

        return view('materials.index', ['o' => $service->roster->offering($offering), 'meeting' => $service->meeting($offering, $meeting), 'materials' => DB::table('materials as m')->join('files as f', 'f.id', '=', 'm.file_id')->where('m.meeting_id', $meeting)->select('m.*', 'f.original_name', 'f.size')->orderByDesc('m.id')->paginate(10), 'canShared' => $service->roster->access->allowed($r->user(), $offering, 'materials.manage')]);
    }

    public function upload(Request $r, int $offering, int $meeting, MeetingRoster $service, PrivateFiles $files)
    {
        $this->shared($r, $offering, $service->roster);
        $service->meeting($offering, $meeting);
        $d = $r->validate(['title' => 'required|string|max:180', 'file' => 'required|file|extensions:pdf,docx,zip,txt,sql|mimes:pdf,docx,zip,txt,sql|max:'.config('academic.upload_max_kb')]);
        $file = $files->stage($r->file('file'));
        try {
            DB::transaction(function () use ($offering, $meeting, $service, $d, $file) {
                $service->roster->writable($offering);
                DB::table('files')->insert($file);
                $id = DB::table('materials')->insertGetId(['meeting_id' => $meeting, 'title' => $d['title'], 'file_id' => $file['id'], 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('material', $id, 'material.uploaded', null, ['title' => $d['title'], 'file_id' => $file['id'], 'checksum' => $file['checksum']], null, $offering);
            });
        } catch (\Throwable $e) {
            $files->discard($file);
            throw $e;
        }

        return redirect()->route('materials.index', [$offering, $meeting])->with('success', 'Materi draf disimpan. Belum tersedia bagi publik.');
    }

    public function publish(Request $r, int $offering, int $meeting, int $material, MeetingRoster $service)
    {
        $this->shared($r, $offering, $service->roster);
        $service->meeting($offering, $meeting);
        $d = $r->validate(['version' => 'required|integer', 'published' => 'required|boolean', 'confirmed' => 'required|accepted', 'reason' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($offering, $meeting, $material, $service, $d) {
            $service->roster->writable($offering);
            $m = DB::table('materials')->where('id', $material)->where('meeting_id', $meeting)->lockForUpdate()->firstOrFail();
            abort_if($m->version !== (int) $d['version'], 409);
            $values = ['published_at' => $d['published'] ? now() : null, 'version' => $m->version + 1, 'updated_at' => now()];
            DB::table('materials')->where('id', $material)->update($values);
            Audit::record('material', $material, $d['published'] ? 'material.published' : 'material.unpublished', (array) $m, $values, ($d['reason'] ?? null), $offering);
        });

        return redirect()->route('materials.index', [$offering, $meeting])->with('success', 'Status publikasi disimpan.');
    }

    public function download(Request $r, int $offering, int $meeting, int $material, MeetingRoster $service, PrivateFiles $files)
    {
        $service->roster->require($r->user(), $offering, 'materials.manage');
        $service->meeting($offering, $meeting);
        $m = DB::table('materials')->where('meeting_id', $meeting)->where('id', $material)->firstOrFail();

        return $files->download($m->file_id);
    }

    /** Published materials of the portal's offering only. */
    public function publicIndex(Request $r)
    {
        $ctx = $r->attributes->get('portal');
        $f = $r->validate(['q' => 'nullable|string|max:100']);
        $q = $this->published()->where('mt.offering_id', $ctx['offering']->id);
        if (! empty($f['q'])) {
            $q->where(fn ($q) => $q->where('m.title', 'like', '%'.$f['q'].'%')->orWhere('mt.title', 'like', '%'.$f['q'].'%'));
        }

        return view('materials.public', ['materials' => $q->select('m.id', 'm.title', 'm.published_at', 'mt.number', 'mt.title as meeting_title', 'p.name as practicum', 's.label as semester')->orderBy('mt.number')->orderBy('m.id')->paginate(10)->withQueryString()]);
    }

    public function published()
    {
        return DB::table('materials as m')->join('meetings as mt', 'mt.id', '=', 'm.meeting_id')->join('practicum_offerings as o', 'o.id', '=', 'mt.offering_id')->join('practicums as p', 'p.id', '=', 'o.practicum_id')->join('semesters as s', 's.id', '=', 'o.semester_id')->whereNotNull('m.published_at')->where('m.published_at', '<=', now());
    }

    /** Download through /{slug}/modul/{material}/unduh: only materials of that portal's offering. */
    public function publicDownload(Request $r, PrivateFiles $files)
    {
        $ctx = $r->attributes->get('portal');
        $m = $this->published()->where('m.id', (int) $r->route('material'))->where('mt.offering_id', $ctx['offering']->id)->select('m.file_id')->first();
        abort_unless($m, 404);

        return $files->download($m->file_id, true);
    }

    /** Old links without slug: forward to the material's own portal when it is still public. */
    public function legacyDownload(int $material, PracticumPortal $portal)
    {
        $offering = $this->published()->where('m.id', $material)->value('mt.offering_id');
        $ctx = $offering ? $portal->forOffering($offering) : null;
        abort_unless($ctx, 404);

        return redirect()->route('portal.material.download', [$ctx['current_slug'], $material], 301);
    }
}
