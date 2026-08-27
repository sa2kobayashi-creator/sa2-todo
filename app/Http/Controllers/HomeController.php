<?php

namespace App\Http\Controllers;

use App\Services\LegalConfigService;
use App\Support\CommercialOffer;
use App\Support\Registration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(private readonly LegalConfigService $legal) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect('/dashboard');
        }

        return view('home', [
            'appName' => config('app.name'),
            'registrationOpen' => Registration::isOpen(),
            'tenantMonthlyYen' => CommercialOffer::tenantMonthlyYen(),
            'tenantYearlyYen' => CommercialOffer::tenantYearlyYen(),
            'tenantTrialDays' => CommercialOffer::tenantTrialDays(),
            'tenantExtraUserYen' => CommercialOffer::extraUserYen(),
            'includedUsers' => CommercialOffer::includedUsers(),
            'yearlyMonthsCharged' => CommercialOffer::yearlyMonthsCharged(),
            'standardMonthlyYen' => (int) config('commercial.standard_yen_monthly', 980),
            'standardYearlyYen' => (int) config('commercial.standard_yen_yearly', 9800),
            // 未ログインの人が運営に連絡できる唯一の手段。特商法表記と同じ値を使う
            'contactEmail' => $this->legal->get('contact_email'),
        ]);
    }
}
