<?php

namespace App\Http\Controllers;

use App\Services\Roster;
use App\Services\StudentImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportController
{
    public function form(Request $r, int $offering, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'students.manage');

        return view('imports.upload', ['o' => $roster->offering($offering)]);
    }

    public function upload(Request $r, int $offering, Roster $roster, StudentImport $reader)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $r->validate(['file' => 'required|file|max:5120|extensions:csv,xlsx|mimes:csv,txt,xlsx']);
        $data = $reader->read($r->file('file'));
        $token = (string) Str::uuid();
        DB::table('import_previews')->insert(['id' => $token, 'offering_id' => $offering, 'user_id' => $r->user()->id, 'payload' => Crypt::encryptString(json_encode($data)), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('imports.preview', [$offering, $token]);
    }

    private function get(Request $r, int $offering, string $token, bool $lock = false)
    {
        $q = DB::table('import_previews')->where('id', $token);
        if ($lock) {
            $q->lockForUpdate();
        }$p = $q->first();
        abort_unless($p && $p->user_id === $r->user()->id && $p->offering_id === $offering, 403);
        abort_if($p->committed_at || now()->greaterThan($p->expires_at), 409, 'Pratinjau sudah dipakai/kedaluwarsa.');

        return $p;
    }

    public function preview(Request $r, int $offering, string $token, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $p = $this->get($r, $offering, $token);
        $payload = json_decode(Crypt::decryptString($p->payload), true);
        abort_unless($payload['type'] === 'import', 422);

        return view('imports.preview', ['o' => $roster->offering($offering), 'p' => $p, 'payload' => $payload]);
    }

    public function map(Request $r, int $offering, string $token, Roster $roster, StudentImport $reader)
    {
        $roster->require($r->user(), $offering, 'students.manage');
        $rules = ['version' => 'required|integer', 'mapping' => 'required|array:'.implode(',', array_keys(StudentImport::FIELDS))];
        foreach (StudentImport::FIELDS as $key => $label) {
            $rules['mapping.'.$key] = $key === 'supervisor_name' ? 'nullable|integer|min:0|max:29' : 'required|integer|min:0|max:29';
        }$d = $r->validate($rules);
        DB::transaction(function () use ($r, $offering, $token, $roster, $reader, $d) {
            $p = $this->get($r, $offering, $token, true);
            abort_if($p->version != (int) $d['version'], 409);
            $payload = json_decode(Crypt::decryptString($p->payload), true);
            abort_unless($payload['type'] === 'import', 422);
            $indices = array_values(array_filter($d['mapping'], fn ($v) => $v !== null));
            if (count(array_unique($indices)) !== count($indices) || count(array_diff($indices, array_keys($payload['headers']))) > 0) {
                throw ValidationException::withMessages(['mapping' => 'Pemetaan kolom harus unik dan tersedia di berkas.']);
            }$payload['mapping'] = $d['mapping'];
            $payload['result'] = $reader->validate($payload, $offering, $r->user(), $roster);
            DB::table('import_previews')->where('id', $token)->update(['payload' => Crypt::encryptString(json_encode($payload)), 'version' => $p->version + 1, 'updated_at' => now()]);
        });

        return redirect()->route('imports.preview', [$offering, $token]);
    }

    public function commit(Request $r, int $offering, string $token, Roster $roster, StudentImport $reader)
    {
        $d = $r->validate(['version' => 'required|integer', 'confirmed' => 'required|accepted']);
        $roster->require($r->user(), $offering, 'students.manage');
        $count = DB::transaction(function () use ($r, $offering, $token, $roster, $reader, $d) {
            $roster->writable($offering);
            $p = $this->get($r, $offering, $token, true);
            abort_if($p->version != (int) $d['version'], 409);
            $payload = json_decode(Crypt::decryptString($p->payload), true);
            abort_unless($payload['type'] === 'import' && $payload['result'] !== null, 422);
            $result = $reader->validate($payload, $offering, $r->user(), $roster);
            if (! $result || collect($result)->contains(fn ($row) => count($row['errors']) > 0)) {
                throw ValidationException::withMessages(['file' => 'Data/izin berubah atau ada baris invalid. Ulangi pratinjau; tidak ada praktikan yang diimpor.']);
            }foreach ($result as $row) {
                $roster->add($row['data'], $offering);
            }DB::table('import_previews')->where('id', $token)->update(['committed_at' => now(), 'updated_at' => now()]);

            return count($result);
        });

        return redirect()->route('students.index', $offering)->with('success', "Impor berhasil: $count praktikan ditambahkan.");
    }

    public function template(Request $r, int $offering, Roster $roster)
    {
        $roster->require($r->user(), $offering, 'students.manage');

        return response("\xEF\xBB\xBFNBI,Nama,Simpraktikum,Kelas,Sesi,Dosen Pembimbing\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="template-praktikan.csv"']);
    }
}
