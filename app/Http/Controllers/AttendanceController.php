<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\MeetingRoster;
use App\Services\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController
{
    public function index(Request $r, int $offering, int $execution, MeetingRoster $service)
    {
        $sm = $service->execution($r->user(), $offering, $execution);
        $f = $r->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(array_keys(MeetingRoster::STATUSES))], 'per_page' => 'nullable|integer|in:10,25,50,100']);
        $q = $service->participants($execution);
        if (! empty($f['q'])) {
            $q->where(fn ($q) => $q->where('p.nbi', 'like', '%'.$f['q'].'%')->orWhere('p.name', 'like', '%'.$f['q'].'%'));
        }if (! empty($f['status'])) {
            $q->where('a.status', $f['status']);
        }$documents = DB::table('attendance_documents as d')->join('files as f', 'f.id', '=', 'd.file_id')->where('d.session_meeting_id', $execution)->select('d.*', 'f.original_name')->orderByDesc('d.id')->get();

        return view('attendance.index', ['o' => $service->roster->offering($offering), 'sm' => $sm, 'meeting' => $service->meeting($offering, $sm->meeting_id), 'rows' => $q->paginate($f['per_page'] ?? 25)->withQueryString(), 'documents' => $documents, 'statuses' => MeetingRoster::STATUSES]);
    }

    public function edit(Request $r, int $offering, int $execution, int $participant, MeetingRoster $service)
    {
        $sm = $service->execution($r->user(), $offering, $execution);
        $row = $service->participants($execution)->where('p.id', $participant)->first();
        abort_unless($row, 403);

        return view('attendance.form', ['o' => $service->roster->offering($offering), 'sm' => $sm, 'row' => $row, 'statuses' => MeetingRoster::STATUSES]);
    }

    public function snapshot(Request $r, int $offering, int $execution, MeetingRoster $service)
    {
        $d = $r->validate(['version' => 'required|integer', 'confirmed' => 'required|accepted']);
        $service->snapshot($r->user(), $offering, $execution, (int) $d['version']);

        return redirect()->route('attendance.index', [$offering, $execution])->with('success', 'Snapshot dibekukan. Presensi awal seluruh peserta Belum dicatat.');
    }

    public function save(Request $r, int $offering, int $execution, MeetingRoster $service)
    {
        $service->execution($r->user(), $offering, $execution);
        $d = $r->validate(['selected' => 'required|array|min:1|max:100', 'selected.*' => 'required|integer|distinct', 'rows' => 'required|array|max:100', 'rows.*.version' => 'required|integer|min:1', 'rows.*.status' => ['required', Rule::in(array_keys(MeetingRoster::STATUSES))], 'rows.*.note' => 'nullable|string|max:1000', 'action' => 'required|in:save,present', 'reason' => 'nullable|string|min:5|max:1000']);
        $changed = DB::transaction(function () use ($r, $offering, $execution, $service, $d) {
            $service->roster->writable($offering);
            $sm = $service->execution($r->user(), $offering, $execution, 'attendance.manage', true);
            abort_unless($sm->snapshot_created_at, 409);
            $selected = array_map('intval', $d['selected']);
            sort($selected);
            $batch = (string) Str::uuid();
            $changes = 0;
            foreach ($selected as $id) {
                $p = DB::table('meeting_participants')->where('id', $id)->where('session_meeting_id', $execution)->first();
                abort_unless($p, 403);
                $a = DB::table('attendances')->where('participant_id', $id)->lockForUpdate()->firstOrFail();
                $row = $d['rows'][$id] ?? null;
                if (! $row) {
                    throw ValidationException::withMessages(['selected' => 'Payload versi/status baris terpilih wajib lengkap.']);
                }abort_if($a->version !== (int) $row['version'], 409);
                $status = $d['action'] === 'present' ? 'present' : $row['status'];
                $note = $row['note'] ?? null;
                if ($a->status === $status && $a->note === $note) {
                    continue;
                }if ($a->recorded_at && ! filled($d['reason'] ?? null)) {
                    throw ValidationException::withMessages(['reason' => 'Koreksi presensi yang sudah tersimpan wajib alasan.']);
                }$values = ['status' => $status, 'note' => $note, 'recorded_by' => $r->user()->id, 'recorded_at' => now(), 'version' => $a->version + 1, 'updated_at' => now()];
                DB::table('attendances')->where('id', $a->id)->update($values);
                Audit::record('attendance', $a->id, $a->recorded_at ? 'attendance.corrected' : 'attendance.recorded', (array) $a, $values, $d['reason'] ?? null, $offering, $batch);
                $changes++;
            }

            return $changes;
        }, 3);

        return redirect()->route('attendance.index', [$offering, $execution])->with('success', "Presensi disimpan: $changed baris berubah. Baris di luar pilihan tetap utuh.");
    }

    public function print(Request $r, int $offering, int $execution, MeetingRoster $service)
    {
        $sm = $service->execution($r->user(), $offering, $execution);
        if (! $sm->snapshot_created_at) {
            return redirect()->route('attendance.index', [$offering, $execution])->withErrors(['snapshot' => 'Bekukan snapshot peserta sebelum mencetak.']);
        }

        return response()->view('attendance.print', ['sm' => $sm, 'meta' => json_decode($sm->snapshot_meta, true), 'rows' => $service->participants($execution)->get(), 'o' => $service->roster->offering($offering)])->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $r, int $offering, int $execution, MeetingRoster $service, PrivateFiles $files)
    {
        $sm = $service->execution($r->user(), $offering, $execution);
        abort_unless($sm->snapshot_created_at, 409);
        $d = $r->validate(['file' => 'required|file|extensions:pdf,jpg,jpeg,png|mimes:pdf,jpg,jpeg,png|max:'.config('academic.upload_max_kb'), 'note' => 'nullable|string|max:1000']);
        $file = $files->stage($r->file('file'));
        try {
            DB::transaction(function () use ($r, $offering, $execution, $service, $d, $file) {
                $service->roster->writable($offering);
                $service->execution($r->user(), $offering, $execution, 'attendance.manage', true);
                DB::table('files')->insert($file);
                $id = DB::table('attendance_documents')->insertGetId(['session_meeting_id' => $execution, 'file_id' => $file['id'], 'uploaded_by' => $r->user()->id, 'note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('attendance_document', $id, 'scan.uploaded', null, ['file_id' => $file['id'], 'checksum' => $file['checksum'], 'note' => $d['note'] ?? null], null, $offering);
            });
        } catch (\Throwable $e) {
            $files->discard($file);
            throw $e;
        }

        return redirect()->route('attendance.index', [$offering, $execution])->with('success', 'Scan disimpan privat; scan sebelumnya tetap tersedia.');
    }

    public function download(Request $r, int $offering, int $execution, int $document, MeetingRoster $service, PrivateFiles $files)
    {
        $service->execution($r->user(), $offering, $execution);
        $doc = DB::table('attendance_documents')->where('id', $document)->where('session_meeting_id', $execution)->firstOrFail();

        return $files->download($doc->file_id);
    }
}
