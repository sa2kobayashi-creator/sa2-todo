<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\User::all() as $user) {
    if (str_starts_with($user->password, '$2b$') || str_starts_with($user->password, '$2a$')) {
        $user->password = '$2y$'.substr($user->password, 4);
        $user->saveQuietly();
        echo "fixed {$user->email}\n";
    }
}

echo "done\n";
