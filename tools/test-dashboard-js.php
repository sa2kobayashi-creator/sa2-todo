<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

auth()->login(App\Models\User::first());
$html = view('dashboard', app(App\Http\Controllers\DashboardController::class)->index(
    Illuminate\Http\Request::create('/dashboard', 'GET', ['year' => 2026, 'month' => 7])
)->getData())->render();

preg_match('/const TODO_ITEMS = (\[.*?\])\s*\n/s', $html, $m);
$todos = json_decode($m[1] ?? '[]', true);
echo 'todos: '.count($todos)."\n";
echo 'has timeToMinutes in script: '.(str_contains($html, 'function timeToMinutes') ? 'yes' : 'NO')."\n";
echo 'sample id type: '.gettype($todos[0]['id'] ?? null)."\n";

if (preg_match('/<script>(.*?)<\/script>/s', $html, $script)) {
    $js = $script[1];
    // basic syntax check - look for obvious issues
    if (str_contains($js, 'timeToMinutes(a.startTime)') && !str_contains($js, 'function timeToMinutes')) {
        echo "BUG: timeToMinutes used but not defined\n";
    }
}
