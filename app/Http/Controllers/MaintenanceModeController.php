<?php

namespace App\Http\Controllers;

use App\Services\MaintenanceModeService;
use Illuminate\Http\Request;

class MaintenanceModeController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private MaintenanceModeService $maintenance) {}

    public function update(Request $request)
    {
        $enabled = $request->boolean('enabled');
        $message = trim((string) $request->input('message', ''));
        if (mb_strlen($message) > 400) {
            return $this->redirectWithMessage(
                '/settings?section=limits',
                __('表示メッセージは400文字以内にしてください。'),
                'error'
            );
        }

        if ($enabled) {
            $this->maintenance->enable($message, (int) $request->user()?->id);
            $notice = __('メンテナンスを開始しました。運営者以外はアプリを使えません。');
        } else {
            $this->maintenance->disable();
            $notice = __('メンテナンスを終了しました。');
        }

        return $this->redirectWithMessage('/settings?section=limits', $notice);
    }
}
