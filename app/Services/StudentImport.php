<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

class StudentImport
{
    public const FIELDS = ['nbi' => 'NBI', 'name' => 'Nama', 'sim_class' => 'Simpraktikum', 'class_category' => 'Kelas', 'session_label' => 'Sesi', 'supervisor_name' => 'Dosen Pembimbing (opsional)'];

    public function normalize(string $v): string
    {
        return mb_strtolower(preg_replace('/^tt\s+/i', '', trim(str_replace("\xEF\xBB\xBF", '', $v))));
    }

    public function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $rows = [];
        if ($ext === 'csv') {
            $h = fopen($file->getRealPath(), 'r');
            $i = 0;
            try {
                while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
                    $i++;
                    $this->append($rows, $i, $cells, []);
                }
            } finally {
                fclose($h);
            }
        } else {
            $zip = new \ZipArchive;
            if ($zip->open($file->getRealPath()) !== true) {
                throw ValidationException::withMessages(['file' => 'XLSX tidak valid.']);
            }$size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
            }$bad = $zip->numFiles > 2000 || $size > 64 * 1024 * 1024;
            $zip->close();
            if ($bad) {
                throw ValidationException::withMessages(['file' => 'Ukuran XLSX setelah ekstraksi melebihi batas.']);
            }
            $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
            try {
                $reader->open($file->getRealPath());
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $i => $row) {
                        $values = [];
                        $badCells = [];
                        foreach ($row->cells as $column => $cell) {
                            $value = $cell->getValue();
                            if ($cell instanceof FormulaCell || (! is_scalar($value) && $value !== null)) {
                                $badCells[$column] = 'Formula/tanggal tidak diperbolehkan.';
                            } elseif (is_int($value) || is_float($value)) {
                                $badCells[$column] = 'numeric';
                            }$values[$column] = $cell instanceof FormulaCell ? '[Formula]' : (is_scalar($value) ? (string) $value : '');
                        }$this->append($rows, $i, $values, $badCells);
                    }break;
                }
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['file' => 'XLSX tidak dapat dibaca. Periksa format berkas.']);
            } finally {
                $reader->close();
            }
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'Berkas kosong.']);
        }$header = array_shift($rows);
        if (count($header['cells']) > 30) {
            throw ValidationException::withMessages(['file' => 'Maksimal 30 kolom.']);
        }
        $mapping = [];
        $aliases = ['nbi' => ['nbi'], 'name' => ['nama', 'name'], 'sim_class' => ['simpraktikum', 'sim_class'], 'class_category' => ['kelas', 'class_category'], 'session_label' => ['sesi', 'session_label'], 'supervisor_name' => ['dosen pembimbing', 'supervisor_name']];
        foreach ($aliases as $key => $names) {
            $index = null;
            foreach ($header['cells'] as $i => $v) {
                if (in_array($this->normalize($v), $names, true)) {
                    $index = $i;
                    break;
                }
            }$mapping[$key] = $index;
        }

        return ['type' => 'import', 'headers' => $header['cells'], 'rows' => $rows, 'mapping' => $mapping, 'result' => null];
    }

    private function append(array &$rows, int $row, array $cells, array $types): void
    {
        if (! array_filter($cells, fn ($v) => trim((string) $v) !== '')) {
            return;
        }
        if (count($rows) >= 2001) {
            throw ValidationException::withMessages(['file' => 'Maksimal 2000 baris data.']);
        }
        foreach ($cells as $value) {
            if (! mb_check_encoding((string) $value, 'UTF-8') || mb_strlen((string) $value) > 5000) {
                throw ValidationException::withMessages(['file' => "Baris $row: teks terlalu panjang atau bukan UTF-8."]);
            }
        }
        $rows[] = ['row' => $row, 'cells' => array_map(fn ($v) => trim((string) $v), $cells), 'types' => $types];
    }

    public function validate(array $payload, int $o, User $user, Roster $roster): array
    {
        $result = [];
        $seen = [];
        $counts = [];
        foreach ($payload['rows'] as $row) {
            $d = [];
            $errors = [];
            foreach (self::FIELDS as $key => $label) {
                $index = $payload['mapping'][$key] ?? null;
                $d[$key] = $index === null ? '' : ($row['cells'][$index] ?? '');
                if ($index !== null && isset($row['types'][$index])) {
                    if ($row['types'][$index] !== 'numeric') {
                        $errors[] = "$label: {$row['types'][$index]}";
                    } elseif ($key === 'nbi') {
                        $errors[] = 'NBI Excel harus berupa sel teks, bukan angka. Ubah sumber menjadi teks agar nol awal tidak hilang.';
                    }
                }
            }
            if ($this->normalize($d['nbi']) === 'nbi' && in_array($this->normalize($d['name']), ['nama', 'name'], true)) {
                continue;
            }
            $v = validator($d, ['nbi' => 'required|string|max:40|regex:/^[0-9A-Za-z.-]+$/', 'name' => 'required|string|max:150', 'sim_class' => 'required|string|max:80', 'class_category' => 'required|string|max:80', 'session_label' => 'required|string|max:100', 'supervisor_name' => 'nullable|string|max:150']);
            $errors = [...$errors, ...$v->errors()->all()];
            $key = mb_strtolower($d['nbi']);
            if (isset($seen[$key])) {
                $errors[] = 'NBI duplikat dalam berkas.';
            }$seen[$key] = true;
            $student = DB::table('students')->where('nbi', $d['nbi'])->first();
            if ($student) {
                if ($student->name !== $d['name']) {
                    $errors[] = 'NBI sudah ada dengan nama berbeda.';
                }if (DB::table('enrollments')->where('student_id', $student->id)->where('offering_id', $o)->exists()) {
                    $errors[] = 'NBI sudah terdaftar pada praktikum ini.';
                }
            }
            $session = DB::table('practicum_sessions')->where('offering_id', $o)->where('label', $d['session_label'])->first();
            if (! $session) {
                $errors[] = 'Sesi tidak ditemukan dalam praktikum.';
            } elseif (! $roster->access->allowed($user, $o, 'students.manage', $session->id)) {
                $errors[] = 'Sesi di luar lingkup izin.';
            }
            $d['session_id'] = $session?->id;
            $d['supervisor_id'] = null;
            if ($d['supervisor_name'] !== '') {
                $supervisors = DB::table('supervisors')->where('active', true)->where(fn ($q) => $q->where('name', $d['supervisor_name'])->orWhere('identity_code', $d['supervisor_name']))->get();
                if ($supervisors->count() !== 1) {
                    $errors[] = 'Dosen tidak ditemukan/ambigu. Gunakan kode identitas master yang unik.';
                } else {
                    $d['supervisor_id'] = $supervisors->first()->id;
                }
            }
            if ($session) {
                $counts[$session->id] = ($counts[$session->id] ?? 0) + 1;
                $current = app(Roster::class)->reservedCount($session->id);
                if ($session->capacity !== null && $current + $counts[$session->id] > $session->capacity) {
                    $errors[] = 'Kapasitas sesi tidak mencukupi.';
                }
            }
            $result[] = ['row' => $row['row'], 'data' => $d, 'errors' => $errors];
        }

        return $result;
    }
}
