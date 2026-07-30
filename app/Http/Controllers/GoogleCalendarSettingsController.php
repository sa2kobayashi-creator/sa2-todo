<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleCalendarSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const SETTINGS_PATH = '/settings?section=integration#google-calendar';

    public function __construct(
        private GoogleCalendarOAuthService $oauth,
        private GoogleCalendarService $calendar,
    ) {}

    public function connect(Request $request)
    {
        if (! $this->oauth->isConfigured()) {
            return $this->redirectWithMessage(
                self::SETTINGS_PATH,
                __('Google Calendar OAuth が設定されていません。管理者に GOOGLE_CLIENT_ID 等の設定を依頼してください。'),
                'error'
            );
        }

        try {
            $auth = $this->oauth->beginAuthorization();
        } catch (\Throwable $e) {
            Log::warning('Google Calendar connect failed to start', ['message' => $e->getMessage()]);

            return $this->redirectWithMessage(self::SETTINGS_PATH, __('連携の開始に失敗しました。'), 'error');
        }

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request)
    {
        if ($request->filled('error')) {
            $error = (string) $request->query('error');
            $message = $error === 'access_denied'
                ? __('Google カレンダー連携がキャンセルされました。')
                : __('Google 認証でエラーが発生しました。');

            return $this->redirectWithMessage(self::SETTINGS_PATH, $message, 'error');
        }

        if (! $this->oauth->validateState($request->query('state'))) {
            return $this->redirectWithMessage(
                self::SETTINGS_PATH,
                __('不正なリクエストです（state 検証に失敗）。もう一度連携してください。'),
                'error'
            );
        }

        $code = $request->query('code');
        if (! is_string($code) || trim($code) === '') {
            return $this->redirectWithMessage(
                self::SETTINGS_PATH,
                __('認証コードを取得できませんでした。もう一度連携してください。'),
                'error'
            );
        }

        $user = $request->user();
        if (! $user) {
            return redirect('/login?returnTo='.urlencode(self::SETTINGS_PATH));
        }

        try {
            $token = $this->oauth->exchangeAuthorizationCode(trim($code));
            $googleUser = $this->oauth->fetchGoogleUser($token['access_token']);
            $this->oauth->saveConnection($user, $token, $googleUser);
            $probe = $this->calendar->probePrimaryCalendar($user);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar callback failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return $this->redirectWithMessage(
                self::SETTINGS_PATH,
                __('連携に失敗しました: :msg', ['msg' => $e->getMessage()]),
                'error'
            );
        }

        $notice = __('Google カレンダーを連携しました。');
        if (! empty($probe['ok'])) {
            if (! empty($probe['event']['summary'])) {
                $notice .= ' '.__('疎通確認: 「:title」', ['title' => $probe['event']['summary']]);
            } else {
                $notice .= ' '.__('疎通確認: 成功');
            }
        } else {
            $notice .= ' '.__('（API疎通: :msg）', ['msg' => $probe['message'] ?? __('失敗')]);
        }

        return $this->redirectWithMessage(self::SETTINGS_PATH, $notice);
    }

    public function disconnect(Request $request)
    {
        $user = $request->user();
        $connection = $this->calendar->connectionFor($user);
        if (! $connection) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, __('連携情報はありません。'));
        }

        try {
            $this->oauth->revokeAndDelete($connection);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar disconnect failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return $this->redirectWithMessage(self::SETTINGS_PATH, __('連携解除に失敗しました。'), 'error');
        }

        return $this->redirectWithMessage(self::SETTINGS_PATH, __('Google カレンダーの連携を解除しました。'));
    }

    public function probe(Request $request)
    {
        $probe = $this->calendar->probePrimaryCalendar($request->user());
        $type = ! empty($probe['ok']) ? 'notice' : 'error';
        $message = (string) ($probe['message'] ?? '');
        if (! empty($probe['event']['summary'])) {
            $message .= ' — '.$probe['event']['summary'];
            if (! empty($probe['event']['start'])) {
                $message .= ' ('.$probe['event']['start'].')';
            }
        }

        return $this->redirectWithMessage(self::SETTINGS_PATH, $message !== '' ? $message : __('結果なし'), $type);
    }

    public function updateCalendars(Request $request)
    {
        $selected = $request->input('calendar_ids', []);
        if (! is_array($selected)) {
            $selected = [];
        }

        try {
            $this->calendar->saveCalendarSelection(
                $request->user(),
                $selected,
                $request->input('sync_calendar_id')
            );
        } catch (\Throwable $e) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(self::SETTINGS_PATH, __('表示・同期カレンダーを保存しました。'));
    }

    public function import(Request $request)
    {
        $user = $request->user();
        $from = $request->input('from');
        $to = $request->input('to');
        $tz = config('app.timezone', 'Asia/Tokyo');
        $timeMin = is_string($from) && $from !== ''
            ? $from.' 00:00:00'
            : now($tz)->subDays(30)->format('Y-m-d').' 00:00:00';
        $timeMax = is_string($to) && $to !== ''
            ? $to.' 23:59:59'
            : now($tz)->addDays(90)->format('Y-m-d').' 23:59:59';

        try {
            $result = $this->calendar->importEventsToWorkTodos($user, $timeMin, $timeMax);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            self::SETTINGS_PATH,
            __('取込完了: 新規 :created 件 / 更新 :updated 件', [
                'created' => $result['created'],
                'updated' => $result['updated'],
            ])
        );
    }
}
