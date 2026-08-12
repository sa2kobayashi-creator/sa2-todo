<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (app()->environment('production') && ! getenv('FORCE_RESET_ADMIN')) {
    fwrite(STDERR, "refusing to reset admin password in production. Set FORCE_RESET_ADMIN=1 to override.\n");
    exit(1);
}

$user = App\Models\User::where('email', 'admin@example.com')->first();
if (! $user) {
    echo "user not found\n";
    exit(1);
}

$user->password = Illuminate\Support\Facades\Hash::make('admin12345');
$user->saveQuietly();

echo "password reset for {$user->email}\n";
echo 'verify: '.(Illuminate\Support\Facades\Hash::check('admin12345', $user->password) ? 'OK' : 'fail')."\n";
