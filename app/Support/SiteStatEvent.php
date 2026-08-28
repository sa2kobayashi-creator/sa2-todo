<?php

namespace App\Support;

/** アクセス統計のイベントキー（個人情報を持たない日次件数）。 */
final class SiteStatEvent
{
    public const TOP_VIEW = 'top.view';

    public const TOP_LOCALE_JA = 'top.locale.ja';

    public const TOP_LOCALE_EN = 'top.locale.en';

    public const TOP_REFERRER_DIRECT = 'top.referrer.direct';

    public const TOP_REFERRER_SEARCH = 'top.referrer.search';

    public const TOP_REFERRER_OTHER = 'top.referrer.other';

    public const CTA_PLAN_STANDARD = 'cta.plan.standard';

    public const CTA_PLAN_TENANT = 'cta.plan.tenant';

    public const CTA_PLAN_LIGHT = 'cta.plan.light';

    public const CTA_APPLY = 'cta.apply';

    public const CTA_REGISTER_INVITE = 'cta.register_invite';

    public const APPLY_VIEW = 'apply.view';

    public const APPLY_VIEW_LIGHT = 'apply.view.light';

    public const APPLY_VIEW_STANDARD = 'apply.view.standard';

    public const APPLY_VIEW_TENANT = 'apply.view.tenant';

    public const APPLY_SUBMIT_LIGHT = 'apply.submit.light';

    public const APPLY_SUBMIT_STANDARD = 'apply.submit.standard';

    public const APPLY_SUBMIT_TENANT = 'apply.submit.tenant';

    public const APPLY_REJECT_PURPOSE = 'apply.reject.purpose';

    public const APPLY_REJECT_DISPOSABLE = 'apply.reject.disposable';

    public const APPLY_REJECT_WEEKLY_CAP = 'apply.reject.weekly_cap';

    public const ACTIVATE_COMPLETE_LIGHT = 'activate.complete.light';

    public const ACTIVATE_COMPLETE_STANDARD = 'activate.complete.standard';

    public const ACTIVATE_COMPLETE_TENANT = 'activate.complete.tenant';

    public const LOGIN = 'login';

    public const CHECKOUT_START = 'checkout.start';

    public const CHECKOUT_COMPLETE = 'checkout.complete';

    public const REGISTER_INVITE = 'register.invite';

    /** @return list<string> */
    public static function allowedClientEvents(): array
    {
        return [
            self::CTA_PLAN_STANDARD,
            self::CTA_PLAN_TENANT,
            self::CTA_PLAN_LIGHT,
            self::CTA_APPLY,
            self::CTA_REGISTER_INVITE,
        ];
    }
}
