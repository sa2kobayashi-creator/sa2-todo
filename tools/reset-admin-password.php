<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'admin@example.com')->first();
if (! $user) {
    echo "user not found\n";
    exit(1);
}

$user->password = Illuminate\Support\Facades\Hash::make('admin12345');
$user->saveQuietly();

echo "password reset for {$user->email}\n";
echo 'verify: '.(Illuminate\Support\Facades\Hash::check('admin12345', $user->password) ? 'OK' : 'fail')."\n";
