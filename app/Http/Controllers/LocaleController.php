<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $locale = (string) $request->input('locale', 'ja');
        if (! in_array($locale, SetLocale::SUPPORTED, true)) {
            $locale = 'ja';
        }

        $request->session()->put('locale', $locale);

        $redirect = $request->input('redirect');
        if (! is_string($redirect) || $redirect === '' || ! str_starts_with($redirect, '/')) {
            $redirect = url()->previous() ?: '/dashboard';
            $path = parse_url($redirect, PHP_URL_PATH) ?: '/dashboard';
            $query = parse_url($redirect, PHP_URL_QUERY);
            $redirect = $query ? $path.'?'.$query : $path;
        }

        return redirect($redirect)
            ->withCookie(cookie('locale', $locale, 60 * 24 * 365));
    }
}
