<?php

namespace App\Http\Controllers;

use App\Services\Assessment;
use App\Services\Audit;
use App\Services\PracticumPortal;
use App\Services\PrivateFiles;
use App\Services\PublicWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Public portal for praktikan: no login, no roster, every entry pending staff review. */
class PublicPortalController
{
    public const PAGES = ['jadwal' => 'Jadwal Praktikum', 'pengumpulan' => 'Pengumpulan Tugas Digital', 'pengajuan' => 'Pengajuan', 'remidi' => 'Remidi'];

    public function page(Request $r)
    {
        $page = (string) ($r->route()->defaults['page'] ?? $r->route('page'));
        abort_unless(array_key_exists($page, self::PAGES), 404);
        $r->validate(['session' => 'nullable|integer']);
        $ctx = $r->attributes->get('portal');
        $o = (object) ['id' => $ctx['offering']->id, 'name' => $ctx['name'], 'semester' => $ctx['offering']->semester];
        $data = ['page' => $page, 'title' => self::PAGES[$page], 'o' => $o];
        $data['sessions'] = DB::table('practicum_sessions as ps')->leftJoin('users as u', 'u.id', '=', 'ps.responsible_user_id')->where('ps.offering_id', $o->id)
            ->orderBy('ps.label')->select('ps.id', 'ps.label', 'ps.weekday', 'ps.start_time', 'ps.end_time', 'ps.room', 'u.name as aslab')->get();
        $data['executions'] = DB::table('session_meetings as sm')->join('practicum_sessions as ps', 'ps.id', '=', 'sm.session_id')->join('meetings as m', 'm.id', '=', 'sm.meeting_id')
            ->where('sm.offering_id', $o->id)->select('sm.id', 'sm.session_id', 'sm.meeting_id', 'sm.starts_at', 'sm.ends_at', 'sm.room', 'ps.label', 'm.number', 'm.title')->orderBy('sm.starts_at')->get();
        $data['schedules'] = DB::table('assignment_schedules as sc')->join('assignments as a', 'a.id', '=', 'sc.assignment_id')->join('practicum_sessions as ps', 'ps.id', '=', 'sc.session_id')
            ->where('sc.offering_id', $o->id)->where('a.mode', 'digital')->where('a.active', true)
            ->select('sc.id', 'sc.session_id', 'sc.opens_at', 'sc.due_at', 'sc.closes_at', 'sc.allow_late', 'a.title', 'a.instructions', 'ps.label')->orderBy('sc.due_at')->get();
        $data['programs'] = DB::table('remedial_programs')->where('offering_id', $o->id)->where('active', true)->orderBy('opens_at')
            ->get(['id', 'title', 'instructions', 'eligibility_rule', 'opens_at', 'closes_at', 'scheduled_at', 'room', 'requires_file']);
        $data['window'] = DB::table('request_windows')->where('offering_id', $o->id)->first(['opens_at', 'closes_at', 'instructions']);

        return view('portal.page', $data);
    }

    private function identity(Request $r): array
    {
        return $r->validate(['offering_id' => 'required|integer', 'session_id' => 'required|integer', 'nbi' => 'required|string|max:40', 'name' => 'required|string|max:150', 'confirmed' => 'required|accepted']);
    }

    private function uploadRule(): string
    {
        return 'file|extensions:pdf,docx,zip,txt,sql|mimes:pdf,docx,zip,txt,sql|max:'.config('academic.upload_max_kb');
    }

    private function receipt(string $token, string $kind)
    {
        return response()->view('portal.receipt', compact('token', 'kind'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public const PERIOD_CHANGED = 'Periode praktikum ini sudah berganti sejak halaman dibuka. Muat ulang halaman, periksa isian, lalu kirim kembali.';

    /**
     * The offering of the URL is authoritative. Slug routes carry it from ResolvePortal; legacy routes (no slug)
     * resolve the submitted offering and accept it only if it is still the public offering of its practicum.
     */
    private function context(Request $r, array $d, string $service): array
    {
        $ctx = $r->attributes->get('portal') ?? app(PracticumPortal::class)->forOffering((int) $d['offering_id']);
        if (! $ctx) {
            $this->invalid('offering_id', self::PERIOD_CHANGED);
        }
        abort_unless(PracticumPortal::services($ctx['practicum'])[$service] ?? false, 404);
        if ((int) $d['offering_id'] !== $ctx['offering']->id) {
            $this->invalid('offering_id', self::PERIOD_CHANGED);
        }

        return $ctx;
    }

    /** Re-check inside the write transaction (after the offering row is locked) that the offering is still current. */
    private function stillCurrent(array $ctx): void
    {
        $now = app(PracticumPortal::class)->activeOffering($ctx['practicum']->id);
        if (! $now || $now->id !== $ctx['offering']->id) {
            $this->invalid('offering_id', self::PERIOD_CHANGED);
        }
    }

    public function submit(Request $r, PublicWorkflow $s, PrivateFiles $files)
    {
        $d = $this->identity($r) + $r->validate(['schedule_id' => 'required|integer', 'file' => 'required|'.$this->uploadRule()]);
        $ctx = $this->context($r, $d, 'pengumpulan');
        $e = $s->enrollment($d);
        $sc = $s->schedule((int) $d['schedule_id']);
        if ($sc->offering_id !== (int) $d['offering_id'] || $sc->session_id !== (int) $d['session_id']) {
            $this->invalid('schedule_id', 'Tugas ini bukan untuk sesi yang dipilih.');
        }
        $file = $files->stage($r->file('file'));
        $file['uploaded_by'] = null;
        $token = $s->token();
        try {
            DB::transaction(function () use ($s, $d, $e, $sc, $file, $token, $ctx) {
                $s->writable((int) $d['offering_id'], $e->id);
                $this->stillCurrent($ctx);
                $s->schedule($sc->id);
                DB::table('files')->insert($file);
                $id = DB::table('public_deliveries')->insertGetId(['offering_id' => $d['offering_id'], 'enrollment_id' => $e->id, 'assignment_id' => $sc->assignment_id, 'schedule_id' => $sc->id, 'file_id' => $file['id'], 'token_hash' => $s->hash($token), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('public_delivery', $id, 'delivery.pending', null, ['status' => 'pending', 'file_id' => $file['id'], 'schedule_id' => $sc->id], null, $sc->offering_id);
            });
        } catch (\Throwable $err) {
            $files->discard($file);
            throw $err;
        }

        return $this->receipt($token, 'Pengumpulan tugas digital');
    }

    public function requestStore(Request $r, PublicWorkflow $s, PrivateFiles $files)
    {
        $d = $this->identity($r) + $r->validate([
            'type' => ['required', Rule::in(array_keys(PublicWorkflow::TYPES))], 'source_execution_id' => 'nullable|integer', 'target_session_id' => 'nullable|integer',
            'target_execution_id' => 'nullable|integer', 'program_id' => 'nullable|integer', 'effective_date' => 'nullable|date_format:Y-m-d|after_or_equal:'.now('Asia/Jakarta')->toDateString(),
            'reason' => 'required|string|min:5|max:2000', 'file' => 'nullable|file|extensions:pdf,jpg,jpeg,png,docx,zip,txt,sql|mimes:pdf,jpg,jpeg,png,docx,zip,txt,sql|max:'.config('academic.upload_max_kb'),
        ]);
        // Remidi has its own service switch and endpoint; the other request types belong to Pengajuan.
        $service = $d['type'] === 'remidi' ? 'remidi' : 'pengajuan';
        $routeService = $r->route()->defaults['service'] ?? null;
        abort_if($routeService && $routeService !== $service, 404);
        $ctx = $this->context($r, $d, $service);
        $e = $s->enrollment($d);
        $token = $s->token();
        $file = $r->hasFile('file') ? $files->stage($r->file('file')) : null;
        if ($file) {
            $file['uploaded_by'] = null;
        }
        try {
            DB::transaction(function () use ($s, $d, $e, $token, $file, $ctx) {
                $o = (int) $d['offering_id'];
                $s->writable($o, $e->id);
                $this->stillCurrent($ctx);
                $type = $d['type'];
                $v = ['offering_id' => $o, 'enrollment_id' => $e->id, 'type' => $type, 'source_session_id' => $d['session_id'], 'reason' => $d['reason'], 'token_hash' => $s->hash($token), 'evidence_file_id' => $file['id'] ?? null, 'created_at' => now(), 'updated_at' => now()];
                if ($type === 'remidi') {
                    $p = DB::table('remedial_programs')->where('offering_id', $o)->where('id', $d['program_id'] ?? 0)->where('active', true)->first() ?? $this->invalid('program_id', 'Pilih program remidi yang aktif.');
                    $s->period($p->opens_at, $p->closes_at);
                    if ($p->requires_file && ! $file) {
                        $this->invalid('file', 'Program ini memerlukan berkas.');
                    }
                    $v['program_id'] = $p->id;
                } else {
                    $w = DB::table('request_windows')->where('offering_id', $o)->first() ?? $this->invalid('period', 'Form pengajuan belum dibuka.');
                    $s->period($w->opens_at, $w->closes_at);
                }
                if (in_array($type, ['izin', 'temporary', 'susulan'], true)) {
                    $source = DB::table('session_meetings')->where('offering_id', $o)->where('id', $d['source_execution_id'] ?? 0)->first();
                    if (! $source || $source->session_id !== (int) $d['session_id']) {
                        $this->invalid('source_execution_id', 'Pilih pelaksanaan pada sesi Anda.');
                    }
                    $v['source_execution_id'] = $source->id;
                }
                if (in_array($type, ['temporary', 'permanent'], true)) {
                    $target = DB::table('practicum_sessions')->where('offering_id', $o)->where('id', $d['target_session_id'] ?? 0)->first();
                    if (! $target || $target->id === (int) $d['session_id']) {
                        $this->invalid('target_session_id', 'Pilih sesi tujuan yang berbeda dari sesi Anda.');
                    }
                    $v['target_session_id'] = $target->id;
                }
                if ($type === 'temporary') {
                    $targetSm = DB::table('session_meetings')->where('offering_id', $o)->where('id', $d['target_execution_id'] ?? 0)->first();
                    if (! $targetSm || $targetSm->session_id !== $target->id || $targetSm->meeting_id !== $source->meeting_id) {
                        $this->invalid('target_execution_id', 'Pelaksanaan tujuan harus pertemuan yang sama pada sesi tujuan.');
                    }
                    $v['target_execution_id'] = $targetSm->id;
                }
                if ($type === 'permanent') {
                    if (empty($d['effective_date'])) {
                        $this->invalid('effective_date', 'Tanggal efektif wajib untuk pindah permanen.');
                    }
                    $v['effective_date'] = $d['effective_date'];
                }
                $duplicate = DB::table('academic_requests')->where('enrollment_id', $e->id)->where('type', $type)->whereIn('status', $type === 'permanent' ? ['pending'] : ['pending', 'approved']);
                foreach (['source_execution_id', 'program_id'] as $key) {
                    if (isset($v[$key])) {
                        $duplicate->where($key, $v[$key]);
                    }
                }
                if ($duplicate->exists()) {
                    $this->invalid('type', 'Pengajuan serupa masih diproses. Gunakan Cek Status dengan token sebelumnya.');
                }
                if ($file) {
                    DB::table('files')->insert($file);
                }
                $id = DB::table('academic_requests')->insertGetId($v);
                unset($v['token_hash']);
                Audit::record('academic_request', $id, 'request.pending', null, $v, null, $o);
            });
        } catch (\Throwable $err) {
            if ($file) {
                $files->discard($file);
            }
            throw $err;
        }

        return $this->receipt($token, 'Pengajuan '.PublicWorkflow::TYPES[$d['type']]);
    }

    private function deliveriesEnabled(int $offering): bool
    {
        $practicum = DB::table('practicums as p')->join('practicum_offerings as o', 'o.practicum_id', '=', 'p.id')->where('o.id', $offering)->select('p.services')->first();

        return $practicum && PracticumPortal::services($practicum)['pengumpulan'];
    }

    public function statusForm()
    {
        return response()->view('portal.status', ['result' => null, 'checked' => false, 'revision' => false, 'token' => null])->header('Cache-Control', 'private, no-store');
    }

    public const STATUS_NOT_FOUND = 'Bukti tidak ditemukan. Periksa token atau hubungi pengelola.';

    public function status(Request $r, PublicWorkflow $s)
    {
        $d = $r->validate(['token' => 'required|string|max:200']);
        $detail = $this->statusDetail($d['token'], $s);
        $revision = (bool) ($detail['revision'] ?? false);

        return response()->view('portal.status', ['result' => $detail, 'checked' => true, 'revision' => $revision, 'token' => $revision ? $this->normalizeToken($d['token']) : null])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Pop-up details for one token (POST body only, never in the URL). */
    public function statusJson(Request $r, PublicWorkflow $s)
    {
        $d = $r->validate(['token' => 'required|string|max:200']);
        $detail = $this->statusDetail($d['token'], $s);

        return $this->json($detail ? ['found' => true] + $detail : ['found' => false, 'message' => self::STATUS_NOT_FOUND]);
    }

    /** "Cek semua" for tokens saved on the device: one request, short summary per position. */
    public function statusBatch(Request $r, PublicWorkflow $s)
    {
        $d = $r->validate(['tokens' => 'required|array|min:1|max:30', 'tokens.*' => 'nullable|string|max:200']);
        $out = [];
        foreach (array_values($d['tokens']) as $token) {
            $x = $this->statusDetail((string) $token, $s);
            $out[] = $x ? ['found' => true, 'kind' => $x['kind'], 'practicum' => $x['practicum'], 'label' => $x['label'], 'state' => $x['state']] : ['found' => false, 'message' => self::STATUS_NOT_FOUND];
        }

        return $this->json(['results' => $out]);
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function normalizeToken(string $token): string
    {
        return strtolower(trim($token));
    }

    /**
     * Everything the token holder may see about their own entry: no score, NBI, name or file.
     * Steps only show times that were actually recorded. Unknown, revoked and malformed tokens all return null.
     */
    private function statusDetail(string $token, PublicWorkflow $s): ?array
    {
        $token = $this->normalizeToken($token);
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $hash = $s->hash($token);
        $wib = fn ($utc, $f = 'd/m/Y H:i') => $utc ? CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Jakarta')->format($f).' WIB' : null;
        $practicum = function (int $offering) {
            $p = DB::table('practicums as p')->join('practicum_offerings as o', 'o.practicum_id', '=', 'p.id')->where('o.id', $offering)->first(['p.name', 'p.display_name']);

            return $p ? PracticumPortal::displayName($p) : null;
        };

        if ($entry = DB::table('public_deliveries')->where('token_hash', $hash)->first()) {
            $sub = $entry->submission_id ? DB::table('submissions')->where('id', $entry->submission_id)->first() : null;
            $state = $sub?->status ?? $entry->status;
            $sc = DB::table('assignment_schedules as sc')->join('practicum_sessions as ps', 'ps.id', '=', 'sc.session_id')->where('sc.id', $entry->schedule_id)->first(['sc.due_at', 'sc.closes_at', 'ps.label']);
            $ext = strtoupper(pathinfo((string) DB::table('files')->where('id', $entry->file_id)->value('original_name'), PATHINFO_EXTENSION));
            $revision = $sub?->status === 'revision_requested' && $this->deliveriesEnabled($entry->offering_id);
            $steps = [['label' => 'Dikirim', 'at' => $wib($entry->received_at), 'done' => true]];
            $steps[] = ['label' => match ($entry->status) {
                'accepted' => 'Diterima aslab', 'rejected' => 'Ditolak aslab', default => 'Diperiksa aslab'
            }, 'at' => $entry->status === 'pending' ? null : $wib($entry->decided_at), 'done' => $entry->status !== 'pending'];
            // Acceptance creates the submission as "submitted": only later changes (revision, complete) are extra steps.
            if ($sub && $sub->status !== 'submitted') {
                $steps[] = ['label' => Assessment::DIGITAL_STATES[$sub->status] ?? 'Diperbarui', 'at' => $wib($sub->recorded_at), 'done' => true];
            }
            $next = match (true) {
                $state === 'pending' => 'Menunggu pemeriksaan aslab. Tidak perlu mengirim ulang.',
                $state === 'rejected' => 'Kiriman ditolak. Baca catatan aslab, lalu hubungi aslab sesi Anda bila perlu mengirim ulang.',
                $state === 'revision_requested' => $revision ? 'Revisi diminta. Unggah versi baru sebelum '.$wib($sc?->closes_at).'.' : 'Revisi diminta. Hubungi aslab sesi Anda.',
                $state === 'complete' => 'Selesai. Tidak ada tindakan lanjutan.',
                default => 'Kiriman sudah tercatat. Pantau status ini bila ada permintaan revisi.',
            };
            $notes = array_values(array_filter([$entry->student_note, $sub?->student_note]));

            return [
                'kind' => 'Pengumpulan tugas digital', 'practicum' => $practicum($entry->offering_id), 'state' => $state,
                'label' => $state === 'submitted' ? 'Diterima aslab' : ((Assessment::DIGITAL_STATES + PublicWorkflow::STATES)[$state] ?? 'Menunggu pemeriksaan'),
                'facts' => array_values(array_filter([
                    ['Tugas', DB::table('assignments')->where('id', $entry->assignment_id)->value('title')],
                    ['Sesi', $sc?->label],
                    ['Tenggat', $wib($sc?->due_at)],
                    ['Berkas', 'Berkas kiriman '.($ext !== '' ? $ext : 'digital')],
                ], fn ($f) => $f[1] !== null)),
                'steps' => $steps, 'notes' => $notes, 'next' => $next, 'revision' => $revision,
            ];
        }

        if ($entry = DB::table('academic_requests')->where('token_hash', $hash)->first()) {
            $exec = function (?int $id) use ($wib) {
                $x = $id ? DB::table('session_meetings as sm')->join('meetings as m', 'm.id', '=', 'sm.meeting_id')->join('practicum_sessions as ps', 'ps.id', '=', 'sm.session_id')->where('sm.id', $id)->first(['ps.label', 'm.number', 'sm.starts_at']) : null;

                return $x ? $x->label.' · Pertemuan '.$x->number.' · '.$wib($x->starts_at) : null;
            };
            $visible = in_array($entry->status, ['approved', 'completed'], true);
            $result = DB::table('request_results')->where('request_id', $entry->id)->orderByDesc('version')->first(['performed_at']);
            $steps = [['label' => 'Dikirim', 'at' => $wib($entry->created_at), 'done' => true]];
            $steps[] = ['label' => match ($entry->status) {
                'rejected' => 'Tidak disetujui', 'pending' => 'Keputusan aslab', default => 'Disetujui'
            }, 'at' => $wib($entry->decision_at), 'done' => $entry->status !== 'pending'];
            if (in_array($entry->type, ['susulan', 'remidi'], true)) {
                $steps[] = ['label' => 'Hasil pelaksanaan dicatat', 'at' => $wib($result?->performed_at), 'done' => (bool) $result];
            }
            $next = match ($entry->status) {
                'pending' => 'Menunggu keputusan aslab. Tidak perlu mengajukan ulang.',
                'rejected' => 'Tidak disetujui. Baca catatan aslab, lalu hubungi aslab sesi Anda bila ada pertanyaan.',
                'completed' => 'Selesai.',
                default => $entry->scheduled_at ? 'Disetujui. Hadir sesuai jadwal di bawah.' : 'Disetujui.',
            };

            return [
                'kind' => 'Pengajuan '.PublicWorkflow::TYPES[$entry->type], 'practicum' => $practicum($entry->offering_id), 'state' => $entry->status,
                'label' => PublicWorkflow::STATES[$entry->status] ?? 'Menunggu pemeriksaan',
                'facts' => array_values(array_filter([
                    ['Pertemuan asal', $exec($entry->source_execution_id)],
                    ['Sesi tujuan', $entry->target_session_id ? DB::table('practicum_sessions')->where('id', $entry->target_session_id)->value('label') : null],
                    ['Pertemuan tujuan', $exec($entry->target_execution_id)],
                    ['Program', $entry->program_id ? DB::table('remedial_programs')->where('id', $entry->program_id)->value('title') : null],
                    ['Berlaku mulai', $entry->effective_date ? CarbonImmutable::parse($entry->effective_date)->format('d/m/Y') : null],
                    ['Jadwal', $visible && $entry->scheduled_at ? $wib($entry->scheduled_at).' · '.$entry->room : null],
                ], fn ($f) => $f[1] !== null)),
                'steps' => $steps, 'notes' => array_values(array_filter([$entry->student_note])), 'next' => $next, 'revision' => false,
            ];
        }

        return null;
    }

    public function revise(Request $r, PublicWorkflow $s, PrivateFiles $files)
    {
        $d = $r->validate(['token' => 'required|string|regex:/^[a-f0-9]{64}$/', 'file' => 'required|'.$this->uploadRule(), 'confirmed' => 'required|accepted']);
        $entry = DB::table('public_deliveries')->where('token_hash', $s->hash($d['token']))->first();
        if (! $entry || ! $entry->submission_id) {
            $this->invalid('token', 'Revisi tidak dapat diproses untuk token ini.');
        }
        abort_unless($this->deliveriesEnabled($entry->offering_id), 404);
        $file = $files->stage($r->file('file'));
        $file['uploaded_by'] = null;
        try {
            DB::transaction(function () use ($s, $entry, $file) {
                $s->writable($entry->offering_id, $entry->enrollment_id);
                $sc = $s->schedule($entry->schedule_id);
                $sub = DB::table('submissions')->where('id', $entry->submission_id)->lockForUpdate()->firstOrFail();
                if ($sub->status !== 'revision_requested') {
                    $this->invalid('token', 'Revisi hanya dapat dikirim setelah pengelola memintanya.');
                }
                DB::table('files')->insert($file);
                $n = 1 + (int) DB::table('submission_versions')->where('submission_id', $sub->id)->max('version_number');
                DB::table('submission_versions')->insert(['submission_id' => $sub->id, 'version_number' => $n, 'file_id' => $file['id'], 'submitted_at' => now(), 'recorded_at' => now(), 'recorded_by' => null, 'revision_note' => 'Revisi melalui bukti privat', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('submissions')->where('id', $sub->id)->update(['status' => 'revised', 'received_at' => now(), 'recorded_at' => now(), 'receiver_id' => null, 'is_late' => now()->gt($sc->due_at), 'late_decision' => 'pending', 'version' => $sub->version + 1, 'updated_at' => now()]);
                Audit::record('submission', $sub->id, 'submission.public_revision', ['status' => $sub->status], ['status' => 'revised', 'version' => $n, 'file_id' => $file['id']], null, $sub->offering_id);
            });
        } catch (\Throwable $err) {
            $files->discard($file);
            throw $err;
        }

        return response()->view('portal.status', ['result' => $this->statusDetail($d['token'], $s), 'checked' => true, 'revision' => false, 'token' => null, 'flash' => 'Revisi dikirim — menunggu pemeriksaan.'])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
