<?php

namespace App\Support;

/** 捨てアド（一時メール）ドメイン判定。申請の自動拒否に使う。 */
final class DisposableEmail
{
    public static function isDisposable(string $email): bool
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return true;
        }

        $domain = substr($email, $at + 1);
        if ($domain === '' || ! str_contains($domain, '.')) {
            return true;
        }

        /** @var list<string> $blocked */
        $blocked = array_map('strtolower', (array) config('registration.disposable_email_domains', []));

        if (in_array($domain, $blocked, true)) {
            return true;
        }

        // サブドメイン付き（例: a.mailinator.com）も拒否
        foreach ($blocked as $blockedDomain) {
            if ($blockedDomain !== '' && str_ends_with($domain, '.'.$blockedDomain)) {
                return true;
            }
        }

        return false;
    }
}
