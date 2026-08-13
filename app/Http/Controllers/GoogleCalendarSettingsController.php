<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleCalendarSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const MYPAGE_PATH = '/mypage#google-calendar';

    private const SESSION_RETURN_KEY = 'google_calendar_return_to';

    public function __construct(
        private GoogleCalendarOAuthService $oauth,
        private GoogleCalendarService $calendar,
    ) {}

    public function connect(Request $request)
    {
        $returnTo = $this->resolveReturnTo($request, true);

        if (! $this->oauth->isConfigured()) {
            return $this->redirectWithMessage(
                $returnTo,
                __('Google Calendar OAuth が設定されていません。管理者に GOOGLE_CLIENT_ID 等の設定を依頼してください。'),
                'error'
            );
        }

        try {
            $auth = $this->oauth->beginAuthorization();
        } catch (\Throwable $e) {
            Log::warning('Google Calendar connect failed to start', ['message' => $e->getMessage()]);

            return $this->redirectWithMessage($returnTo, __('連携の開始に失敗しました。'), 'error');
        }

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request)
    {
        $returnTo = $this->consumeStoredReturnTo();

        if ($request->filled('error')) {
            $error = (string) $request->query('error');
            $message = $error === 'access_denied'
                ? __('Google カレンダー連携がキャンセルされました。')
                : __('Google 認証でエラーが発生しました。');

            return $this->redirectWithMessage($returnTo, $message, 'error');
        }

        if (! $this->oauth->validateState($request->query('state'))) {
            return $this->redirectWithMessage(
                $returnTo,
                __('不正なリクエストです（state 検証に失敗）。もう一度連携してください。'),
                'error'
            );
        }

        $code = $request->query('code');
        if (! is_string($code) || trim($code) === '') {
            return $this->redirectWithMessage(
                $returnTo,
                __('認証コードを取得できませんでした。もう一度連携してください。'),
                'error'
            );
        }

        $user = $request->user();
        if (! $user) {
            return redirect('/login?returnTo='.urlencode($returnTo));
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
                $returnTo,
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

        return $this->redirectWithMessage($returnTo, $notice);
    }

    public function disconnect(Request $request)
    {
        $returnTo = $this->resolveReturnTo($request);
        $user = $request->user();
        $connection = $this->calendar->connectionFor($user);
        if (! $connection) {
            return $this->redirectWithMessage($returnTo, __('連携情報はありません。'));
        }

        try {
            $this->oauth->revokeAndDelete($connection);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar disconnect failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return $this->redirectWithMessage($returnTo, __('連携解除に失敗しました。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('Google カレンダーの連携を解除しました。'));
    }

    public function probe(Request $request)
    {
        $returnTo = $this->resolveReturnTo($request);
        $probe = $this->calendar->probePrimaryCalendar($request->user());
        $type = ! empty($probe['ok']) ? 'notice' : 'error';
        $message = (string) ($probe['message'] ?? '');
        if (! empty($probe['event']['summary'])) {
            $message .= ' — '.$probe['event']['summary'];
            if (! empty($probe['event']['start'])) {
                $message .= ' ('.$probe['event']['start'].')';
            }
        }

        return $this->redirectWithMessage($returnTo, $message !== '' ? $message : __('結果なし'), $type);
    }

    public function updateCalendars(Request $request)
    {
        $returnTo = $this->resolveReturnTo($request);
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
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('表示・同期カレンダーを保存しました。'));
    }

    public function import(Request $request)
    {
        $returnTo = $this->resolveReturnTo($request);
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
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            $returnTo,
            __('取込完了: 新規 :created 件 / 更新 :updated 件', [
                'created' => $result['created'],
                'updated' => $result['updated'],
            ])
        );
    }

    private function resolveReturnTo(Request $request, bool $persistForOauth = false): string
    {
        $candidate = $request->input('returnTo') ?? $request->query('returnTo');
        $path = $this->sanitizeReturnTo(is_string($candidate) ? $candidate : null) ?? self::MYPAGE_PATH;

        if ($persistForOauth) {
            session([self::SESSION_RETURN_KEY => $path]);
        }

        return $path;
    }

    private function consumeStoredReturnTo(): string
    {
        $stored = session()->pull(self::SESSION_RETURN_KEY);

        return $this->sanitizeReturnTo(is_string($stored) ? $stored : null) ?? self::MYPAGE_PATH;
    }

    private function sanitizeReturnTo(?string $value): ?string
    {
        if (! is_string($value) || $value === '' || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || ! preg_match('#^/(mypage|settings|todos)(/|$)#', $path)) {
            return null;
        }

        return $this->urlWithoutFlashParams($value);
    }
}
