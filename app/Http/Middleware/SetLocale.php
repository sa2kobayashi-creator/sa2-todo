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

    /** Cookie の有効期間（分）。言語切替と同じく 1 年。 */
    public const COOKIE_MINUTES = 60 * 24 * 365;

    public function handle(Request $request, Closure $next): Response
    {
        $fromPreference = $this->preferredLocale($request);
        $locale = $fromPreference ?? self::detectFromAcceptLanguage($request);
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'ja';
        }

        if ($fromPreference === null) {
            $request->session()->put('locale', $locale);
        }

        App::setLocale($locale);
        View::share('appLocale', $locale);
        View::share('htmlLang', $locale === 'ja' ? 'ja' : 'en');

        $response = $next($request);

        if ($fromPreference === null) {
            $response->headers->setCookie(cookie('locale', $locale, self::COOKIE_MINUTES));
        }

        return $response;
    }

    private function preferredLocale(Request $request): ?string
    {
        $locale = $request->session()->get('locale');
        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            return $locale;
        }

        $locale = $request->cookie('locale');
        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            return $locale;
        }

        return null;
    }

    /**
     * Accept-Language の優先順で ja / en を拾う。どちらも無ければ日本語。
     */
    public static function detectFromAcceptLanguage(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $code = strtolower(str_replace('_', '-', (string) $language));
            if (str_starts_with($code, 'ja')) {
                return 'ja';
            }
            if (str_starts_with($code, 'en')) {
                return 'en';
            }
        }

        return 'ja';
    }
}
