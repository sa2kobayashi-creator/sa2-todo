<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(): View
    {
        return view('help.index');
    }

    public function about(): View
    {
        $version = (string) config('app.version', '1.0.0');
        $path = base_path('VERSION');
        if (is_readable($path)) {
            $line = trim((string) File::get($path));
            if ($line !== '') {
                $version = $line;
            }
        }

        return view('help.about', [
            'appName' => config('app.name'),
            'appVersion' => $version,
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
            'environment' => config('app.env'),
            'locale' => app()->getLocale(),
            'timezone' => config('app.timezone'),
            'showEnvironment' => auth()->user()?->isAdmin() ?? false,
        ]);
    }
}
