#!/usr/bin/env php
<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Service\Auth;

function prompt(string $label): string {
    fwrite(STDOUT, $label.': ');
    $value = fgets(STDIN);
    if ($value === false) throw new RuntimeException('Input cancelled.');
    return rtrim($value, "\r\n");
}
try {
    App\Service\Store::db();
    $name = prompt('Admin display name');
    $email = prompt('Login email');
    if (!stream_isatty(STDIN)) throw new RuntimeException('Run interactively in a terminal to enter the password securely.');
    $terminalMode = trim(shell_exec('stty -g') ?? '');
    if ($terminalMode === '') throw new RuntimeException('Unable to secure password input.');
    system('stty -echo');
    try {
        $password = prompt('Password (6–72 bytes; a long unique password is recommended)');
        fwrite(STDOUT, "\n");
        $confirm = prompt('Confirm password');
        fwrite(STDOUT, "\n");
    } finally { system('stty '.escapeshellarg($terminalMode)); }
    if ($password !== $confirm) throw new RuntimeException('Passwords do not match.');
    Auth::create(['name'=>$name,'email'=>$email,'password'=>$password], true);
    fwrite(STDOUT, "Admin created. Sign in with your email and password.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
