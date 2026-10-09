<?php

// Creates secrets only in ignored local test files. Never modifies production .env.
$root = dirname(__DIR__);
$file = $root.'/.env.testing';
if (file_exists($file)) {
    fwrite(STDERR, ".env.testing sudah tersedia; tidak ditimpa.\n");
    exit(1);
}
$env = file_get_contents($root.'/.env.testing.example');
$env = str_replace('APP_KEY=', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $env);
$env = str_replace('DB_PASSWORD=', 'DB_PASSWORD='.bin2hex(random_bytes(24)), $env);
$env = str_replace('MYSQL_TEST_ROOT_PASSWORD=', 'MYSQL_TEST_ROOT_PASSWORD='.bin2hex(random_bytes(24)), $env);
file_put_contents($file, $env);
chmod($file, 0600);
echo "Konfigurasi pengujian terisolasi dibuat.\n";
