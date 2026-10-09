<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Assessment
{
    public const TYPES = ['pendahuluan' => 'Pendahuluan', 'aktivitas' => 'Aktivitas', 'lab' => 'Praktik Lab', 'final' => 'Laporan akhir', 'custom' => 'Lainnya'];

    public const MODES = ['print' => 'Cetak', 'digital' => 'Digital', 'direct' => 'Pemeriksaan langsung'];

    public const PRINT_STATES = ['received' => 'Diterima', 'revision_requested' => 'Perlu revisi', 'revised' => 'Revisi diterima', 'complete' => 'Selesai', 'missing_decided' => 'Tidak dikumpulkan — diputuskan'];

    public const DIGITAL_STATES = ['submitted' => 'Dikirim', 'revision_requested' => 'Perlu revisi', 'revised' => 'Revisi dikirim', 'complete' => 'Selesai', 'missing_decided' => 'Tidak dikumpulkan — diputuskan'];

    public function __construct(public Roster $roster) {}

    public function assignment(int $o, int $id): object
    {
        return DB::table('assignments')->where('offering_id', $o)->where('id', $id)->firstOrFail();
    }

    public function shared(User $u, int $o, string $key): void
    {
        abort_unless($this->roster->access->allowed($u, $o, $key), 403);
    }

    public function enrollment(User $u, int $o, int $id, string $key): object
    {
        $row = $this->roster->query($u, $o, $key)->where('e.id', $id)->first();
        abort_unless($row, 403);

        return $row;
    }

    public function writable(int $o, ?int $e = null): void
    {
        $this->roster->writable($o);
        $q = DB::table('final_results')->where('offering_id', $o)->whereNull('superseded_at');
        if ($e) {
            $q->where('enrollment_id', $e);
        }abort_if($q->exists(), 423, 'Hasil final sudah diarsipkan.');
    }

    public function fingerprint(int $o): array
    {
        return DB::table('grading_components')->where('offering_id', $o)->orderBy('id')->get()->map(fn ($c) => ['id' => $c->id, 'assignment_id' => $c->assignment_id, 'label' => $c->label, 'weight' => $c->weight, 'max_score' => $c->max_score, 'mandatory' => (bool) $c->mandatory, 'active' => (bool) $c->active, 'version' => $c->version])->all();
    }

    public function validateRules(int $o, array $config): array
    {
        $errors = [];
        foreach (['pass_score', 'rounding', 'precision', 'late_policy'] as $key) {
            if (! isset($config[$key])) {
                $errors[] = 'Aturan '.$key.' belum lengkap.';
            }
        }$components = $this->fingerprint($o);
        $active = array_filter($components, fn ($c) => $c['active']);
        if (! $active) {
            $errors[] = 'Belum ada komponen nilai aktif.';
        }
        $bands = $config['bands'] ?? [];
        if (! $bands || min(array_column($bands, 'minimum')) != 0) {
            $errors[] = 'Batas nilai huruf wajib mencakup skor 0.';
        }
        $sum = 0;
        foreach ($active as $c) {
            if ($c['weight'] === null) {
                $errors[] = 'Bobot '.$c['label'].' belum ditentukan.';
            }$sum += (float) $c['weight'];
            $a = $this->assignment($o, $c['assignment_id']);
            if (! $a->active) {
                $errors[] = 'Komponen aktif menunjuk tugas nonaktif.';
            }
        }
        if (abs($sum - 100) > 0.00001) {
            $errors[] = 'Jumlah bobot aktif harus tepat 100%.';
        }
        $activity5 = DB::table('grading_components as c')->join('assignments as a', 'a.id', '=', 'c.assignment_id')->join('meetings as m', 'm.id', '=', 'a.origin_meeting_id')->where('c.offering_id', $o)->where('c.active', true)->where('c.weight', '>', 0)->where('a.type', 'aktivitas')->where('m.number', 5)->exists();
        $final = DB::table('grading_components as c')->join('assignments as a', 'a.id', '=', 'c.assignment_id')->where('c.offering_id', $o)->where('c.active', true)->where('c.weight', '>', 0)->where('a.type', 'final')->exists();
        if ($activity5 && $final && ! ($config['separate_activity5'] ?? false)) {
            $errors[] = 'Nilai Aktivitas 5 dan laporan akhir berbobot terpisah perlu konfirmasi ketentuan resmi.';
        }
        // MySQL JSON normalizes object-key order; compare values rather than PHP key order.
        if (($config['components'] ?? []) != $components) {
            $errors[] = 'Komponen berubah sejak draf aturan dibuat. Buat versi aturan baru.';
        }

        return $errors;
    }

    public function finalPreview(User $u, int $o, int $eid): array
    {
        $e = $this->enrollment($u, $o, $eid, 'grades.manage');
        $errors = [];
        $rule = DB::table('grading_rules')->where('offering_id', $o)->where('status', 'published')->orderByDesc('version')->first();
        $config = $rule ? json_decode($rule->config_json, true) : null;
        if (! $rule) {
            $errors[] = 'Aturan nilai belum diterbitkan.';
        } else {
            $errors = array_merge($errors, $this->validateRules($o, $config));
        }
        $components = DB::table('grading_components as c')->join('assignments as a', 'a.id', '=', 'c.assignment_id')->where('c.offering_id', $o)->where('c.active', true)->select('c.*', 'a.title as assignment_title')->orderBy('c.id')->get();
        $grades = DB::table('grades')->where('enrollment_id', $eid)->get()->keyBy('component_id');
        $total = 0;
        $details = [];
        foreach ($components as $c) {
            $g = $grades->get($c->id);
            if ((! $g || $g->status === 'ungraded' || $g->score === null) && ($c->mandatory || (float) $c->weight > 0)) {
                $errors[] = 'Nilai '.$c->label.' belum ditangani.';
            }if ($g && $g->score !== null && (float) $g->score > (float) $c->max_score) {
                $errors[] = 'Nilai melebihi maksimum komponen '.$c->label;
            }$contribution = $g && $g->score !== null ? (float) $g->score / (float) $c->max_score * (float) $c->weight : 0;
            $total += $contribution;
            $details[] = ['component' => (array) $c, 'grade' => $g ? (array) $g : null, 'contribution' => $contribution];
        }
        // Open susulan/remidi requests are unresolved obligations; remedial scores are never merged automatically.
        $open = DB::table('academic_requests')->where('offering_id', $o)->where('enrollment_id', $eid)->whereIn('type', ['susulan', 'remidi'])->whereIn('status', ['pending', 'approved'])->count();
        if ($open) {
            $errors[] = $open.' pengajuan susulan/remidi belum selesai diproses.';
        }
        $obligations = [];
        foreach (DB::table('assignments')->where('offering_id', $o)->where('active', true)->where('mandatory', true)->get() as $a) {
            if ($a->mode === 'direct') {
                $directComponent = $components->firstWhere('assignment_id', $a->id);
                $directGrade = $directComponent ? $grades->get($directComponent->id) : null;
                if ($directComponent && (! $directGrade || $directGrade->status === 'ungraded' || $directGrade->score === null)) {
                    $errors[] = 'Nilai tugas langsung wajib '.$a->title.' belum ditangani.';
                }
                if (! $components->contains('assignment_id', $a->id)) {
                    $errors[] = 'Tugas langsung '.$a->title.' belum memiliki komponen aktif.';
                }

                continue;
            }$sub = DB::table('submissions')->where('assignment_id', $a->id)->where('enrollment_id', $eid)->first();
            if (! $sub || ! in_array($sub->status, ['complete', 'missing_decided'], true)) {
                $errors[] = 'Pengumpulan '.$a->title.' belum diselesaikan/diputuskan.';
            }if ($sub && $sub->is_late && ($config['late_policy'] ?? 'review') === 'review' && $sub->late_decision !== 'accepted') {
                $errors[] = 'Keterlambatan '.$a->title.' belum diputuskan.';
            }
            if ($sub && $a->type === 'final' && $sub->status !== 'missing_decided') {
                $count = DB::table('report_checklist_items')->where('assignment_id', $a->id)->count();
                $done = DB::table('report_checklist_results')->where('submission_id', $sub->id)->where('completed', true)->count();
                if (! $count || $done !== $count) {
                    $errors[] = 'Checklist laporan akhir '.$a->title.' belum lengkap.';
                }
            }
            $obligations[] = ['assignment' => (array) $a, 'submission' => $sub ? (array) $sub : null];
        }
        $rounded = $total;
        if ($config && isset($config['precision'],$config['rounding'])) {
            $factor = 10 ** $config['precision'];
            $rounded = match ($config['rounding']) {
                'half_up' => round($total, $config['precision'], PHP_ROUND_HALF_UP),'floor' => floor($total * $factor) / $factor,'ceil' => ceil($total * $factor) / $factor
            };
        } $letter = null;
        if ($config) {
            foreach ($config['bands'] as $band) {
                if ($rounded >= $band['minimum']) {
                    $letter = $band['letter'];
                    break;
                }
            }
        }

        return ['enrollment' => $e, 'rule' => $rule, 'config' => $config, 'errors' => array_values(array_unique($errors)), 'score' => $rounded, 'letter' => $letter, 'decision' => $config && $rounded >= $config['pass_score'] ? 'Lulus' : 'Tidak lulus', 'details' => $details, 'obligations' => $obligations];
    }

    public function fail(array $errors): void
    {
        if ($errors) {
            throw ValidationException::withMessages(['assessment' => implode(' ', $errors)]);
        }
    }
}
