<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'admin@example.com')->first();
$plain = 'admin12345';

$hashCheck = Illuminate\Support\Facades\Hash::check($plain, $user->password);
echo "Hash::check: ".($hashCheck ? 'OK' : 'fail')."\n";
echo "prefix: ".substr($user->password, 0, 7)."\n";
