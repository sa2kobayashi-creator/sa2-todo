<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RedirectsWithFlash
{
    protected function redirectWithMessage(string $target, string $message, string $type = 'notice'): RedirectResponse
    {
        $key = $type === 'error' ? 'error' : 'notice';
        $hashIndex = strpos($target, '#');
        $pathAndQuery = $hashIndex !== false ? substr($target, 0, $hashIndex) : $target;
        $hash = $hashIndex !== false ? substr($target, $hashIndex) : '';
        $join = str_contains($pathAndQuery, '?') ? '&' : '?';

        return redirect("{$pathAndQuery}{$join}{$key}=".urlencode($message).$hash);
    }

    protected function safeReturnTo(?string $value, string $fallback = '/todos'): string
    {
        if ($value && str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        return $fallback;
    }

    /** @return array{notice: ?string, error: ?string} */
    protected function flashFromQuery(Request $request): array
    {
        return [
            'notice' => is_string($request->query('notice')) ? $request->query('notice') : null,
            'error' => is_string($request->query('error')) ? $request->query('error') : null,
        ];
    }
}
