<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RedirectsWithFlash
{
    protected function redirectWithMessage(string $target, string $message, string $type = 'notice'): RedirectResponse
    {
        $key = $type === 'error' ? 'error' : 'notice';
        $cleanTarget = $this->urlWithoutFlashParams($target);

        return redirect($cleanTarget)->with($key, $message);
    }

    protected function safeReturnTo(?string $value, string $fallback = '/todos'): string
    {
        if ($value && str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        return $fallback;
    }

    /** @return array{notice: ?string, error: ?string, linkCode: ?string, linkCodeMinutes: int, linkCodeProvider: ?string} */
    protected function flashFromQuery(Request $request): array
    {
        $notice = $request->session()->pull('notice');
        $error = $request->session()->pull('error');

        // 旧 ?notice= / ?error= リンク互換（表示後は JS で URL から除去）
        if (! is_string($notice) || $notice === '') {
            $notice = is_string($request->query('notice')) ? $request->query('notice') : null;
        }
        if (! is_string($error) || $error === '') {
            $error = is_string($request->query('error')) ? $request->query('error') : null;
        }

        $linkCode = $request->session()->pull('link_code');
        $linkCodeMinutes = $request->session()->pull('link_code_minutes');
        $linkCodeProvider = $request->session()->pull('link_code_provider');

        return [
            'notice' => $notice,
            'error' => $error,
            'linkCode' => is_string($linkCode) && $linkCode !== '' ? $linkCode : null,
            'linkCodeMinutes' => is_numeric($linkCodeMinutes) ? (int) $linkCodeMinutes : 15,
            'linkCodeProvider' => is_string($linkCodeProvider) ? $linkCodeProvider : null,
        ];
    }

    protected function urlWithoutFlashParams(string $target): string
    {
        $hashIndex = strpos($target, '#');
        $pathAndQuery = $hashIndex !== false ? substr($target, 0, $hashIndex) : $target;
        $hash = $hashIndex !== false ? substr($target, $hashIndex) : '';

        $parts = parse_url($pathAndQuery);
        $path = $parts['path'] ?? $pathAndQuery;
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            unset($query['notice'], $query['error']);
        }

        $qs = http_build_query($query);

        return $path.($qs !== '' ? '?'.$qs : '').$hash;
    }
}
