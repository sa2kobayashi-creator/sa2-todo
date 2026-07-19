<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['ja', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');
        if (! is_string($locale) || ! in_array($locale, self::SUPPORTED, true)) {
            $locale = $request->cookie('locale');
        }
        if (! is_string($locale) || ! in_array($locale, self::SUPPORTED, true)) {
            $locale = config('app.locale', 'ja');
        }
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'ja';
        }

        App::setLocale($locale);
        View::share('appLocale', $locale);
        View::share('htmlLang', $locale === 'ja' ? 'ja' : 'en');

        return $next($request);
    }
}
