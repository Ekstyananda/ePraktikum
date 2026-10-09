<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapAdmin extends Command
{
    protected $signature = 'portal:admin {email} {--name=Administrator}';

    protected $description = 'Buat admin pertama melalui kata sandi tersembunyi, tanpa kredensial default';

    public function handle(): int
    {
        if (User::where('role', 'admin')->exists()) {
            $this->error('Admin sudah tersedia. Perintah bootstrap dinonaktifkan.');

            return self::FAILURE;
        }
        $password = $this->secret('Kata sandi (minimal 12 karakter, huruf besar/kecil dan angka)');
        $confirmation = $this->secret('Konfirmasi kata sandi');
        $data = ['email' => $this->argument('email'), 'name' => $this->option('name'), 'password' => $password, 'password_confirmation' => $confirmation];
        $v = Validator::make($data, ['email' => 'required|email|unique:users', 'name' => 'required|string|max:150', 'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()]]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        User::create(['email' => $data['email'], 'name' => $data['name'], 'password' => $password, 'role' => 'admin', 'active' => true]);
        $this->info('Admin dibuat.');

        return self::SUCCESS;
    }
}
