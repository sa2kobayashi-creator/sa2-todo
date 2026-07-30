<?php

namespace App\Http\Controllers;

use App\Enums\AppContext;
use App\Services\AppContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppContextController extends Controller
{
    public function __construct(private AppContextService $contexts) {}

    public function update(Request $request): RedirectResponse
    {
        $context = AppContext::tryFromInput($request->input('context'));
        $this->contexts->set($request->user(), $context, $request);

        $redirect = $request->input('redirect');
        if (! is_string($redirect) || $redirect === '' || ! str_starts_with($redirect, '/')) {
            $redirect = url()->previous() ?: '/dashboard';
            $path = parse_url($redirect, PHP_URL_PATH) ?: '/dashboard';
            $query = parse_url($redirect, PHP_URL_QUERY);
            $redirect = $query ? $path.'?'.$query : $path;
        }

        return redirect($redirect);
    }
}
