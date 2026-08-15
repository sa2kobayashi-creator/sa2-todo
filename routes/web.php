<?php

use App\Http\Controllers\AiLlmSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\GroupController as AdminGroupController;
use App\Http\Controllers\Api\HolidayDatesController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\AppContextController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\EnhanceSettingsController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GoogleCalendarSettingsController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\LineWebhookController;
use App\Http\Controllers\MessengerWebhookController;
use App\Http\Controllers\LiveKitSettingsController;
use App\Http\Controllers\MessagingSettingsController;
use App\Http\Controllers\MediaStorageSettingsController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MusicController;
use App\Http\Controllers\MyPageController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\TransitController;
use App\Http\Controllers\TranslateController;
use App\Http\Controllers\TravelController;
use App\Http\Controllers\TravelpayoutsSettingsController;
use App\Http\Controllers\TranslationApiKeyController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\YoutubeSettingsController;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\ShareViewData;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

Route::get('/terms', [LegalController::class, 'terms']);
Route::get('/privacy', [LegalController::class, 'privacy']);

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

// フォームを開いたまま放置したときの 419 を避けるため、CSRF トークンを取り直せるようにする
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:auth-login');
    Route::get('/register', [RegisterController::class, 'show']);
    Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:auth-register');
});

// パスワード再設定はログイン前（パスワード忘れ）とログイン後（マイページ）で同じ導線を使う
Route::get('/password/forgot', [PasswordResetController::class, 'showForgot']);
Route::post('/password/forgot', [PasswordResetController::class, 'sendCode'])->middleware('throttle:auth-password');
Route::get('/password/reset', [PasswordResetController::class, 'showReset']);
Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-password');

Route::middleware('auth')->group(function () {
    Route::get('/password/setup', [PasswordSetupController::class, 'show']);
    Route::post('/password/setup', [PasswordSetupController::class, 'store']);
    Route::post('/mypage/password/request-code', [PasswordResetController::class, 'requestForCurrentUser']);
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth');

Route::post('/app-context', [AppContextController::class, 'update'])
    ->middleware(['auth'])
    ->name('app-context.update');

// LINE / Messenger Webhook（CSRF 除外は bootstrap/app.php）
Route::post('/webhooks/line', LineWebhookController::class);
Route::get('/webhooks/messenger', [MessengerWebhookController::class, 'verify']);
Route::post('/webhooks/messenger', [MessengerWebhookController::class, 'receive']);

// Digital Asset Links（TWA）。静的ファイルでも可だが Content-Type を明示
Route::get('/.well-known/assetlinks.json', function () {
    $path = public_path('.well-known/assetlinks.json');
    abort_unless(is_file($path), 404);
    return response()->file($path, [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'public, max-age=300',
    ]);
});

// Google Calendar OAuth callback（ログイン連携ではない。セッションのログインユーザーに紐付ける）
Route::get('/auth/google/calendar/callback', [GoogleCalendarSettingsController::class, 'callback'])
    ->middleware(['auth', ShareViewData::class]);

Route::middleware(['auth', ShareViewData::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/calendar', [DashboardController::class, 'calendarRedirect']);
    Route::get('/api/holiday-dates', HolidayDatesController::class);

    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::post('/todos/voice/parse', [TodoController::class, 'parseVoice'])->middleware('throttle:ai-voice');
    Route::post('/todos/bulk/complete', [TodoController::class, 'bulkComplete']);
    Route::post('/todos/bulk/uncomplete', [TodoController::class, 'bulkUncomplete']);
    Route::post('/todos/bulk/delete', [TodoController::class, 'bulkDelete']);
    Route::post('/todos/bulk/duplicate', [TodoController::class, 'bulkDuplicate']);
    Route::post('/todos/{id}/update', [TodoController::class, 'update'])->whereNumber('id');
    Route::post('/todos/{id}/toggle', [TodoController::class, 'toggle'])->whereNumber('id');
    Route::post('/todos/{id}/delete', [TodoController::class, 'destroy'])->whereNumber('id');
    Route::post('/todos/{id}/duplicate', [TodoController::class, 'duplicate'])->whereNumber('id');
    Route::post('/todos/{id}/reschedule', [TodoController::class, 'reschedule'])->whereNumber('id');

    Route::get('/notes', [NoteController::class, 'index']);
    Route::post('/notes', [NoteController::class, 'store']);
    Route::post('/notes/voice/parse', [NoteController::class, 'parseVoice'])->middleware('throttle:ai-voice');
    Route::post('/notes/bulk/archive', [NoteController::class, 'bulkArchive']);
    Route::post('/notes/bulk/delete', [NoteController::class, 'bulkDelete']);
    Route::post('/notes/bulk/append', [NoteController::class, 'bulkAppend']);
    Route::post('/notes/reorder', [NoteController::class, 'reorder']);
    Route::get('/notes/attachments/{id}/file', [NoteController::class, 'attachmentFile'])->whereNumber('id');
    Route::get('/notes/attachments/{id}/download', [NoteController::class, 'attachmentDownload'])->whereNumber('id');
    Route::post('/notes/{id}/update', [NoteController::class, 'update'])->whereNumber('id');
    Route::post('/notes/{id}/translate', [NoteController::class, 'translate'])->middleware('throttle:ai-translate')->whereNumber('id');
    Route::post('/notes/{id}/pin', [NoteController::class, 'pin'])->whereNumber('id');
    Route::post('/notes/{id}/complete', [NoteController::class, 'complete'])->whereNumber('id');
    Route::post('/notes/{id}/archive', [NoteController::class, 'archive'])->whereNumber('id');
    Route::post('/notes/{id}/reschedule', [NoteController::class, 'reschedule'])->whereNumber('id');
    Route::post('/notes/{id}/delete', [NoteController::class, 'destroy'])->whereNumber('id');

    Route::get('/photos', [PhotoController::class, 'index']);
    Route::post('/photos', [PhotoController::class, 'store'])->middleware('throttle:media-upload');
    Route::post('/photos/check-duplicates', [PhotoController::class, 'checkDuplicates']);
    Route::post('/photos/duplicates/scan', [PhotoController::class, 'scanDuplicates']);
    Route::post('/photos/{id}/rename', [PhotoController::class, 'rename'])->whereNumber('id');
    Route::post('/photos/upload/chunk', [PhotoController::class, 'uploadChunk'])->middleware('throttle:media-upload');
    Route::post('/photos/upload/complete', [PhotoController::class, 'uploadComplete'])->middleware('throttle:media-upload');
    Route::post('/photos/archive-cold', [PhotoController::class, 'archiveCold']);
    Route::post('/photos/archive-cold/start', [PhotoController::class, 'archiveColdStart']);
    Route::get('/photos/archive-cold/status', [PhotoController::class, 'archiveColdStatus']);
    Route::post('/photos/archive-cold/cancel', [PhotoController::class, 'archiveColdCancel']);
    Route::post('/photos/albums', [PhotoController::class, 'storeAlbum']);
    Route::post('/photos/albums/for-folder', [PhotoController::class, 'storeFolderAlbum']);
    Route::post('/photos/albums/reveal-hidden', [PhotoController::class, 'toggleRevealHiddenAlbums']);
    Route::post('/photos/albums/{id}/update', [PhotoController::class, 'updateAlbum'])->whereNumber('id');
    Route::post('/photos/albums/{id}/unlock', [PhotoController::class, 'unlockAlbum'])->whereNumber('id');
    Route::post('/photos/albums/{id}/cover', [PhotoController::class, 'setCover'])->whereNumber('id');
    Route::post('/photos/albums/{id}/delete', [PhotoController::class, 'destroyAlbum'])->whereNumber('id');
    Route::post('/photos/{id}/edit-image', [PhotoController::class, 'editImage'])->whereNumber('id');
    Route::post('/photos/{id}/stability-enhance', [PhotoController::class, 'stabilityEnhance'])->middleware('throttle:ai-enhance')->whereNumber('id');
    Route::post('/photos/{id}/stability-enhance/cancel', [PhotoController::class, 'stabilityEnhanceCancel'])->whereNumber('id');
    Route::post('/photos/{id}/cloudinary-edit/start', [PhotoController::class, 'cloudinaryEditStart'])->whereNumber('id');
    Route::post('/photos/{id}/cloudinary-edit/commit', [PhotoController::class, 'cloudinaryEditCommit'])->whereNumber('id');
    Route::post('/photos/{id}/cloudinary-edit/cancel', [PhotoController::class, 'cloudinaryEditCancel'])->whereNumber('id');
    Route::post('/photos/{id}/trim-video', [PhotoController::class, 'trimVideo'])->whereNumber('id');
    Route::post('/photos/{id}/taken-at', [PhotoController::class, 'updateTakenAt'])->whereNumber('id');
    Route::post('/photos/{id}/dashboard', [PhotoController::class, 'updateDashboardVisibility'])->whereNumber('id');
    Route::get('/photos/{id}/file', [PhotoController::class, 'file'])->whereNumber('id');
    Route::post('/photos/{id}/delete', [PhotoController::class, 'destroy'])->whereNumber('id');
    Route::post('/photos/bulk/delete', [PhotoController::class, 'bulkDestroy']);
    Route::post('/photos/bulk/update', [PhotoController::class, 'bulkUpdate']);
    Route::post('/photos/bulk/move', [PhotoController::class, 'bulkMove']);
    Route::post('/photos/bulk/archive', [PhotoController::class, 'bulkArchive']);
    Route::post('/photos/bulk/restore', [PhotoController::class, 'bulkRestore']);

    Route::middleware(EnsureFeature::class.':music')->group(function () {
        Route::get('/music', [MusicController::class, 'index']);
        Route::post('/music', [MusicController::class, 'store']);
        Route::get('/music/{id}/file', [MusicController::class, 'file'])->whereNumber('id');
        Route::post('/music/{id}/delete', [MusicController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':video')->group(function () {
        Route::get('/video', [VideoController::class, 'index']);
        Route::post('/video', [VideoController::class, 'store']);
        Route::get('/video/youtube/search', [VideoController::class, 'searchYoutube']);
        Route::post('/video/youtube', [VideoController::class, 'storeYoutube']);
        Route::post('/video/youtube/{id}/delete', [VideoController::class, 'destroyYoutube'])->whereNumber('id');
        Route::post('/video/youtube/{id}/move', [VideoController::class, 'moveYoutube'])->whereNumber('id');
        Route::post('/video/libraries', [VideoController::class, 'storeLibrary']);
        Route::post('/video/libraries/{id}/update', [VideoController::class, 'updateLibrary'])->whereNumber('id');
        Route::post('/video/libraries/{id}/delete', [VideoController::class, 'destroyLibrary'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':messages')->group(function () {
        Route::get('/messages', [MessageController::class, 'index']);
        Route::get('/messages/incoming-call', [MessageController::class, 'incomingCall'])
            ->middleware('throttle:30,1');
        Route::get('/messages/attachments/{id}/file', [MessageController::class, 'attachmentFile'])->whereNumber('id');
        Route::get('/messages/attachments/{id}/download', [MessageController::class, 'attachmentDownload'])->whereNumber('id');
        Route::post('/messages/attachments/{id}/to-photos', [MessageController::class, 'attachmentSaveToPhotos'])->whereNumber('id');
        Route::post('/messages/items/{id}/update', [MessageController::class, 'update'])->whereNumber('id');
        Route::post('/messages/items/{id}/delete', [MessageController::class, 'destroy'])->whereNumber('id');
        Route::post('/messages/items/{id}/react', [MessageController::class, 'react'])->whereNumber('id');
        Route::post('/messages/items/{id}/forward', [MessageController::class, 'forward'])->whereNumber('id');
        Route::post('/messages/items/{id}/translate', [MessageController::class, 'translate'])->middleware('throttle:ai-translate')->whereNumber('id');
        Route::get('/messages/{groupId}/wallpaper', [MessageController::class, 'wallpaperFile'])->whereNumber('groupId');
        Route::post('/messages/{groupId}/wallpaper', [MessageController::class, 'updateWallpaper'])->whereNumber('groupId');
        Route::post('/messages/{groupId}/dm/{userId}/call-token', [MessageController::class, 'callToken'])
            ->middleware('throttle:30,1')
            ->whereNumber('groupId')
            ->whereNumber('userId');
        Route::post('/messages/{groupId}/dm/{userId}/call-cancel', [MessageController::class, 'callCancel'])
            ->middleware('throttle:60,1')
            ->whereNumber('groupId')
            ->whereNumber('userId');
        Route::post('/messages/call-decline', [MessageController::class, 'callDecline'])
            ->middleware('throttle:60,1');
        Route::get('/messages/{groupId}/dm/{userId}', [MessageController::class, 'showDm'])->whereNumber('groupId')->whereNumber('userId');
        Route::get('/messages/{groupId}', [MessageController::class, 'show'])->whereNumber('groupId');
        Route::post('/messages/{groupId}', [MessageController::class, 'store'])->whereNumber('groupId');
        Route::get('/messages/{groupId}/poll', [MessageController::class, 'poll'])->whereNumber('groupId');
    });

    Route::middleware([\App\Http\Middleware\EnsureFeature::class.':translate', \App\Http\Middleware\RequireSuperAdmin::class])->group(function () {
        Route::get('/translate', [TranslateController::class, 'index']);
        Route::post('/translate', [TranslateController::class, 'translate'])->middleware('throttle:ai-translate');
        Route::post('/translate/document', [TranslateController::class, 'document'])->middleware('throttle:ai-translate');
        Route::post('/translate/website', [TranslateController::class, 'website'])->middleware('throttle:ai-translate');
        Route::get('/translate/history', [TranslateController::class, 'history']);
        Route::post('/translate/history/{id}/saved', [TranslateController::class, 'toggleSaved'])->whereNumber('id');
        Route::delete('/translate/history/{id}', [TranslateController::class, 'destroyHistory'])->whereNumber('id');
    });

    // 招待の承諾・辞退はライト（グループ画面なし）でもダッシュボードから行う
    Route::post('/group-invitations/{id}/accept', [GroupController::class, 'acceptInvitation'])->whereNumber('id');
    Route::post('/group-invitations/{id}/decline', [GroupController::class, 'declineInvitation'])->whereNumber('id');

    // グループはスタンダード以上。ライトは一覧も作成もできない
    Route::middleware(EnsureFeature::class.':groups')->group(function () {
        Route::get('/groups', [GroupController::class, 'index']);
        Route::post('/groups', [GroupController::class, 'store']);
        Route::post('/groups/{id}/members', [GroupController::class, 'inviteMember'])->whereNumber('id');
        Route::post('/groups/{id}/members/remove', [GroupController::class, 'removeMember'])->whereNumber('id');
        Route::post('/groups/{id}/delete', [GroupController::class, 'destroy'])->whereNumber('id');
    });

    Route::get('/mypage', [MyPageController::class, 'show']);
    Route::post('/mypage', [MyPageController::class, 'update']);
    Route::get('/mypage/export', [MyPageController::class, 'export']);
    Route::post('/mypage/delete', [MyPageController::class, 'destroy']);
    Route::get('/help', [HelpController::class, 'index']);
    Route::get('/about', [HelpController::class, 'about']);

    // Google Calendar 個人連携（設定画面不要。Standard / Light も利用可）
    Route::get('/mypage/google-calendar/connect', [GoogleCalendarSettingsController::class, 'connect']);
    Route::post('/mypage/google-calendar/disconnect', [GoogleCalendarSettingsController::class, 'disconnect']);
    Route::post('/mypage/google-calendar/probe', [GoogleCalendarSettingsController::class, 'probe']);
    Route::post('/mypage/google-calendar/calendars', [GoogleCalendarSettingsController::class, 'updateCalendars']);
    Route::post('/mypage/google-calendar/import', [GoogleCalendarSettingsController::class, 'import']);
    Route::post('/mypage/messaging/{provider}/code', [MessagingSettingsController::class, 'issueCode'])
        ->where('provider', 'line|messenger');
    Route::post('/mypage/messaging/{provider}/disconnect', [MessagingSettingsController::class, 'disconnect'])
        ->where('provider', 'line|messenger');
    Route::post('/mypage/messaging/{provider}/test', [MessagingSettingsController::class, 'test'])
        ->where('provider', 'line|messenger');
    // 旧 URL 互換
    Route::get('/settings/google-calendar/connect', [GoogleCalendarSettingsController::class, 'connect']);
    Route::post('/settings/google-calendar/disconnect', [GoogleCalendarSettingsController::class, 'disconnect']);
    Route::post('/settings/google-calendar/probe', [GoogleCalendarSettingsController::class, 'probe']);
    Route::post('/settings/google-calendar/calendars', [GoogleCalendarSettingsController::class, 'updateCalendars']);
    Route::post('/settings/google-calendar/import', [GoogleCalendarSettingsController::class, 'import']);

    // メールアドレス変更は新しい宛先に届いたコードを確認してから反映する
    Route::get('/mypage/email/verify', [EmailChangeController::class, 'showVerify']);
    Route::post('/mypage/email/verify', [EmailChangeController::class, 'verify']);
    Route::post('/mypage/email/resend', [EmailChangeController::class, 'resend']);
    Route::post('/mypage/email/cancel', [EmailChangeController::class, 'cancel']);

    Route::middleware(EnsureFeature::class.':finance')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index']);
        Route::get('/finance/report', [FinanceController::class, 'report']);
        Route::get('/finance/export', [FinanceController::class, 'exportCsv']);
        Route::post('/finance/import', [FinanceController::class, 'importCsv']);
        Route::post('/finance/bulk/delete', [FinanceController::class, 'bulkDestroy']);
        Route::post('/finance', [FinanceController::class, 'store']);
        Route::post('/finance/voice/parse', [FinanceController::class, 'parseVoice'])->middleware('throttle:ai-voice');
        Route::post('/finance/categories', [FinanceController::class, 'storeExpenseCategory']);
        Route::post('/finance/categories/{slug}/delete', [FinanceController::class, 'destroyExpenseCategory'])->where('slug', '[A-Za-z0-9_\-]+');
        Route::post('/finance/{id}/update', [FinanceController::class, 'update'])->whereNumber('id');
        Route::post('/finance/{id}/delete', [FinanceController::class, 'destroy'])->whereNumber('id');
        Route::post('/finance/accounts', [FinanceController::class, 'storeAccount']);
        Route::post('/finance/accounts/{id}/overview', [FinanceController::class, 'updateAccountOverview'])->whereNumber('id');
        Route::post('/finance/accounts/{id}/schedules', [FinanceController::class, 'storeAccountSchedule'])->whereNumber('id');
        Route::post('/finance/accounts/{id}/schedules/upsert', [FinanceController::class, 'upsertAccountSchedule'])->whereNumber('id');
        Route::post('/finance/schedules/{id}/delete', [FinanceController::class, 'destroyAccountSchedule'])->whereNumber('id');
        Route::post('/finance/schedules/{id}/update', [FinanceController::class, 'updateAccountSchedule'])->whereNumber('id');
        Route::post('/finance/accounts/reorder', [FinanceController::class, 'reorderAccounts']);
        Route::post('/finance/accounts/{id}/update', [FinanceController::class, 'updateAccount'])->whereNumber('id');
        Route::post('/finance/accounts/{id}/delete', [FinanceController::class, 'destroyAccount'])->whereNumber('id');
        Route::post('/finance/accounts/{id}/balance', [FinanceController::class, 'updateAccountBalance'])->whereNumber('id');
        Route::post('/finance/accounts/{id}/balance/reset', [FinanceController::class, 'resetAccountBalance'])->whereNumber('id');
        Route::post('/finance/accounts/{id}/linked-bank', [FinanceController::class, 'updateLinkedBank'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':transit')->group(function () {
        Route::get('/transit', [TransitController::class, 'index']);
        Route::post('/transit/search', [TransitController::class, 'search']);
        Route::post('/transit', [TransitController::class, 'store']);
        Route::post('/transit/{id}/update', [TransitController::class, 'update'])->whereNumber('id');
        Route::post('/transit/{id}/delete', [TransitController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':travel')->group(function () {
        Route::get('/travel', [TravelController::class, 'index']);
        Route::post('/travel/profile', [TravelController::class, 'updateProfile']);
        Route::post('/travel/trips', [TravelController::class, 'storeTrip']);
        Route::post('/travel/trips/quote', [TravelController::class, 'quoteTrip']);
        Route::post('/travel/trips/draft/clear', [TravelController::class, 'clearTripDraft']);
        Route::post('/travel/fares/table', [TravelController::class, 'fareTable']);
        Route::post('/travel/fares/table/clear', [TravelController::class, 'clearFareTable']);
        Route::post('/travel/trips/{id}/update', [TravelController::class, 'updateTrip'])->whereNumber('id');
        Route::post('/travel/trips/{id}/delete', [TravelController::class, 'destroyTrip'])->whereNumber('id');
        Route::post('/travel/promos', [TravelController::class, 'storePromo']);
        Route::post('/travel/promos/fetch', [TravelController::class, 'fetchPromos']);
        Route::post('/travel/promos/{id}/update', [TravelController::class, 'updatePromo'])->whereNumber('id');
        Route::post('/travel/promos/{id}/delete', [TravelController::class, 'destroyPromo'])->whereNumber('id');
        Route::post('/travel/alerts/{id}/read', [TravelController::class, 'markAlertRead'])->whereNumber('id');
        Route::post('/travel/alerts/read-all', [TravelController::class, 'markAllAlertsRead']);
    });

    Route::middleware(EnsureFeature::class.':map')->group(function () {
        Route::get('/map', [MapController::class, 'index']);
        Route::post('/map', [MapController::class, 'store']);
        Route::post('/map/{id}/update', [MapController::class, 'update'])->whereNumber('id');
        Route::post('/map/{id}/delete', [MapController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':settings')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings/nav', [SettingsController::class, 'updateFooterNav']);
        Route::post('/settings/holidays/import', [SettingsController::class, 'importHolidays']);
        Route::post('/settings/holidays/add', [SettingsController::class, 'addHoliday']);
        Route::post('/settings/holidays/{id}/delete', [SettingsController::class, 'deleteHoliday'])->whereNumber('id');
        Route::post('/settings/weekday-rules/add', [SettingsController::class, 'addWeekdayRule']);
        Route::post('/settings/weekday-rules/{id}/delete', [SettingsController::class, 'deleteWeekdayRule'])->whereNumber('id');
        Route::post('/settings/weekday-rules/{id}/exceptions/add', [SettingsController::class, 'addWeekdayException'])->whereNumber('id');
        Route::post('/settings/weekday-rules/{id}/exceptions/delete', [SettingsController::class, 'deleteWeekdayException'])->whereNumber('id');

        Route::post('/settings/translation-keys', [TranslationApiKeyController::class, 'store']);
        Route::post('/settings/translation-keys/test', [TranslationApiKeyController::class, 'test']);
        Route::post('/settings/translation-keys/fetch-usage-all', [TranslationApiKeyController::class, 'fetchAllUsageFromDeepL']);
        Route::post('/settings/translation-keys/pricing', [TranslationApiKeyController::class, 'updatePricing']);
        Route::get('/settings/translation-keys/{id}/edit', [TranslationApiKeyController::class, 'edit'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/update', [TranslationApiKeyController::class, 'update'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/delete', [TranslationApiKeyController::class, 'destroy'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/reset-usage', [TranslationApiKeyController::class, 'resetUsage'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/fetch-usage', [TranslationApiKeyController::class, 'fetchUsageFromDeepL'])->whereNumber('id');

        Route::post('/settings/ai/llm', [AiLlmSettingsController::class, 'update']);
        Route::post('/settings/ai/llm/test', [AiLlmSettingsController::class, 'test']);
        Route::post('/settings/ai/youtube', [YoutubeSettingsController::class, 'update']);
        Route::post('/settings/ai/youtube/test', [YoutubeSettingsController::class, 'test']);

        Route::post('/settings/api/travelpayouts', [TravelpayoutsSettingsController::class, 'update']);
        Route::post('/settings/api/travelpayouts/test', [TravelpayoutsSettingsController::class, 'test']);
        Route::post('/settings/api/livekit', [LiveKitSettingsController::class, 'update']);
        Route::post('/settings/api/livekit/test', [LiveKitSettingsController::class, 'test']);

        Route::post('/settings/storage/{provider}', [MediaStorageSettingsController::class, 'update'])
            ->where('provider', 'r2|cloudinary|backblaze|pipeline');
        Route::post('/settings/storage/{provider}/test', [MediaStorageSettingsController::class, 'test'])
            ->where('provider', 'r2|cloudinary|backblaze|pipeline');

        Route::post('/settings/messaging/{provider}/channel', [MessagingSettingsController::class, 'saveChannel'])
            ->where('provider', 'line|messenger');
        Route::post('/settings/messaging/{provider}/channel/test', [MessagingSettingsController::class, 'testChannel'])
            ->where('provider', 'line|messenger');
        Route::post('/settings/messaging/line/disable', [MessagingSettingsController::class, 'disableLine']);
        Route::post('/settings/messaging/line/qr/delete', [MessagingSettingsController::class, 'deleteLineQr']);
        Route::post('/settings/messaging/{provider}/code', [MessagingSettingsController::class, 'issueCode'])
            ->where('provider', 'line|messenger');
        Route::post('/settings/messaging/{provider}/disconnect', [MessagingSettingsController::class, 'disconnect'])
            ->where('provider', 'line|messenger');
        Route::post('/settings/messaging/{provider}/test', [MessagingSettingsController::class, 'test'])
            ->where('provider', 'line|messenger');

        Route::post('/settings/enhance/active', [EnhanceSettingsController::class, 'updateActive'])
            ->middleware(\App\Http\Middleware\RequireSuperAdmin::class);
        Route::post('/settings/enhance/{provider}', [EnhanceSettingsController::class, 'updateProvider'])
            ->middleware(\App\Http\Middleware\RequireSuperAdmin::class)
            ->where('provider', 'stability|realesrgan|swinir');
        Route::post('/settings/enhance/{provider}/test', [EnhanceSettingsController::class, 'testProvider'])
            ->middleware(\App\Http\Middleware\RequireSuperAdmin::class)
            ->where('provider', 'stability|realesrgan|swinir');
    });

    Route::middleware(RequireAdmin::class)->group(function () {
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::post('/admin/users/registration', [AdminUserController::class, 'updateRegistration']);
        Route::get('/admin/users/{id}', [AdminUserController::class, 'show'])->whereNumber('id');
        Route::get('/admin/users/{id}/edit', [AdminUserController::class, 'edit'])->whereNumber('id');
        Route::post('/admin/users/{id}/update', [AdminUserController::class, 'update'])->whereNumber('id');
        Route::post('/admin/users/{id}/delete', [AdminUserController::class, 'destroy'])->whereNumber('id');

        Route::get('/admin/groups', [AdminGroupController::class, 'index']);
        Route::post('/admin/groups', [AdminGroupController::class, 'store']);
        Route::post('/admin/groups/{id}/approve', [AdminGroupController::class, 'approve'])->whereNumber('id');
        Route::post('/admin/groups/{id}/reject', [AdminGroupController::class, 'reject'])->whereNumber('id');
        Route::post('/admin/groups/{id}/menus', [AdminGroupController::class, 'updateMenus'])->whereNumber('id');
        Route::post('/admin/groups/{id}/delete', [AdminGroupController::class, 'destroy'])->whereNumber('id');
    });
});
