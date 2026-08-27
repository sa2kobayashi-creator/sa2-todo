<?php

namespace App\Http\Controllers;

use App\Services\AiProviderUsageService;
use Illuminate\Http\RedirectResponse;

class AiProviderUsageController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private AiProviderUsageService $usage) {}

    public function refresh(): RedirectResponse
    {
        $result = $this->usage->refreshAll();
        $ok = (int) $result['ok'] > 0 || (int) $result['failed'] === 0;

        return $this->redirectWithMessage(
            '/settings?section=usage#official-ai-usage',
            $result['message'],
            $ok ? 'notice' : 'error'
        );
    }
}
