<?php

namespace App\Http\Controllers;

use App\Services\LegalConfigService;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function __construct(private readonly LegalConfigService $legal) {}

    public function terms(): View
    {
        return view('legal.terms');
    }

    public function privacy(): View
    {
        return view('legal.privacy', [
            'processors' => (array) config('legal.processors', []),
            'privacyContactEmail' => $this->legal->privacyContactEmail(),
            'operatorName' => $this->legal->get('operator_name'),
        ]);
    }

    /** 特定商取引法に基づく表記。有料販売する以上、未ログインから到達できる必要がある */
    public function tokushoho(): View
    {
        $monthly = (int) config('commercial.standard_yen_monthly', 980);
        $tenantYearly = (int) config('commercial.tenant_yearly_yen', 0);
        if ($tenantYearly <= 0) {
            $tenantYearly = (int) config('commercial.tenant_monthly_yen', 3980)
                * (int) config('commercial.yearly_maintenance_months_charged', 11);
        }

        $legal = $this->legal->values();

        return view('legal.tokushoho', [
            'operatorName' => $legal['operator_name'],
            'operatorTradeName' => $legal['operator_trade_name'],
            'operatorManager' => $legal['operator_manager'],
            'address' => $legal['address'],
            'phone' => $legal['phone'],
            'phoneHours' => $legal['phone_hours'],
            'contactEmail' => $legal['contact_email'],
            'invoiceNumber' => $legal['invoice_registration_number'],
            'pricesIncludeTax' => (bool) config('commercial.prices_include_tax', false),
            'standardMonthlyYen' => $monthly,
            'standardYearlyYen' => (int) config('commercial.standard_yen_yearly', 9800),
            'standardTrialDays' => (int) config('commercial.standard_trial_days', 14),
            'tenantMonthlyYen' => (int) config('commercial.tenant_monthly_yen', 3980),
            'tenantYearlyYen' => $tenantYearly,
            'tenantTrialDays' => (int) config('commercial.tenant_trial_days', 30),
            'mailboxYenMonthly' => (int) config('commercial.mailbox_yen_monthly', 300),
            'storageOverageYen' => (int) config('commercial.storage_overage_yen_per_100gb', 300),
            'setupFeeYen' => (int) config('commercial.setup_fee_yen', 50000),
            'monthlyBaseYen' => (int) config('commercial.monthly_base_yen', 8000),
        ]);
    }
}
