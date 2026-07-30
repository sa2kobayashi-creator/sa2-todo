<?php

namespace App\Services;

use App\Enums\AppContext;
use App\Models\User;
use Illuminate\Http\Request;

class AppContextService
{
    public const SESSION_KEY = 'app_context';

    public function current(?User $user = null, ?Request $request = null): AppContext
    {
        $request ??= request();
        if ($request && $request->hasSession()) {
            $sessionValue = $request->session()->get(self::SESSION_KEY);
            if (is_string($sessionValue) && AppContext::tryFrom($sessionValue)) {
                return AppContext::from($sessionValue);
            }
        }

        if ($user && is_string($user->app_context) && AppContext::tryFrom($user->app_context)) {
            return AppContext::from($user->app_context);
        }

        return AppContext::Personal;
    }

    public function set(User $user, AppContext $context, ?Request $request = null): void
    {
        $request ??= request();
        if ($request && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $context->value);
        }
        $user->app_context = $context->value;
        $user->save();
    }

    public function isWork(?User $user = null): bool
    {
        return $this->current($user) === AppContext::Work;
    }

    public function isPersonal(?User $user = null): bool
    {
        return $this->current($user) === AppContext::Personal;
    }
}
