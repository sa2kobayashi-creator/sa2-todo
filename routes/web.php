<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\GroupController as AdminGroupController;
use App\Http\Controllers\Api\HolidayDatesController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MyPageController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\TransitController;
use App\Http\Controllers\TranslationApiKeyController;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\ShareViewData;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/register', [RegisterController::class, 'show']);
    Route::post('/register', [RegisterController::class, 'register']);
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth');

Route::middleware(['auth', ShareViewData::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/calendar', [DashboardController::class, 'calendarRedirect']);
    Route::get('/api/holiday-dates', HolidayDatesController::class);

    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
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
    Route::post('/notes/bulk/archive', [NoteController::class, 'bulkArchive']);
    Route::post('/notes/bulk/delete', [NoteController::class, 'bulkDelete']);
    Route::post('/notes/bulk/append', [NoteController::class, 'bulkAppend']);
    Route::post('/notes/reorder', [NoteController::class, 'reorder']);
    Route::post('/notes/{id}/update', [NoteController::class, 'update'])->whereNumber('id');
    Route::post('/notes/{id}/translate', [NoteController::class, 'translate'])->whereNumber('id');
    Route::post('/notes/{id}/pin', [NoteController::class, 'pin'])->whereNumber('id');
    Route::post('/notes/{id}/archive', [NoteController::class, 'archive'])->whereNumber('id');
    Route::post('/notes/{id}/reschedule', [NoteController::class, 'reschedule'])->whereNumber('id');
    Route::post('/notes/{id}/delete', [NoteController::class, 'destroy'])->whereNumber('id');

    Route::get('/photos', [PhotoController::class, 'index']);
    Route::post('/photos', [PhotoController::class, 'store']);
    Route::post('/photos/albums', [PhotoController::class, 'storeAlbum']);
    Route::post('/photos/albums/{id}/update', [PhotoController::class, 'updateAlbum'])->whereNumber('id');
    Route::post('/photos/albums/{id}/cover', [PhotoController::class, 'setCover'])->whereNumber('id');
    Route::post('/photos/albums/{id}/delete', [PhotoController::class, 'destroyAlbum'])->whereNumber('id');
    Route::post('/photos/{id}/edit-image', [PhotoController::class, 'editImage'])->whereNumber('id');
    Route::post('/photos/{id}/trim-video', [PhotoController::class, 'trimVideo'])->whereNumber('id');
    Route::get('/photos/{id}/file', [PhotoController::class, 'file'])->whereNumber('id');
    Route::post('/photos/{id}/delete', [PhotoController::class, 'destroy'])->whereNumber('id');
    Route::post('/photos/bulk/delete', [PhotoController::class, 'bulkDestroy']);
    Route::post('/photos/bulk/move', [PhotoController::class, 'bulkMove']);

    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);
    Route::post('/groups/{id}/members', [GroupController::class, 'addMember'])->whereNumber('id');
    Route::post('/groups/{id}/members/remove', [GroupController::class, 'removeMember'])->whereNumber('id');

    Route::get('/mypage', [MyPageController::class, 'show']);
    Route::post('/mypage', [MyPageController::class, 'update']);

    Route::middleware(EnsureFeature::class.':finance')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index']);
        Route::get('/finance/report', [FinanceController::class, 'report']);
        Route::get('/finance/export', [FinanceController::class, 'exportCsv']);
        Route::post('/finance/import', [FinanceController::class, 'importCsv']);
        Route::post('/finance/bulk/delete', [FinanceController::class, 'bulkDestroy']);
        Route::post('/finance', [FinanceController::class, 'store']);
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
        Route::post('/finance/accounts/{id}/linked-bank', [FinanceController::class, 'updateLinkedBank'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':transit')->group(function () {
        Route::get('/transit', [TransitController::class, 'index']);
        Route::post('/transit/search', [TransitController::class, 'search']);
        Route::post('/transit', [TransitController::class, 'store']);
        Route::post('/transit/{id}/update', [TransitController::class, 'update'])->whereNumber('id');
        Route::post('/transit/{id}/delete', [TransitController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':map')->group(function () {
        Route::get('/map', [MapController::class, 'index']);
        Route::post('/map', [MapController::class, 'store']);
        Route::post('/map/{id}/update', [MapController::class, 'update'])->whereNumber('id');
        Route::post('/map/{id}/delete', [MapController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware(EnsureFeature::class.':settings')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings/holidays/import', [SettingsController::class, 'importHolidays']);
        Route::post('/settings/holidays/add', [SettingsController::class, 'addHoliday']);
        Route::post('/settings/holidays/{id}/delete', [SettingsController::class, 'deleteHoliday'])->whereNumber('id');
        Route::post('/settings/weekday-rules/add', [SettingsController::class, 'addWeekdayRule']);
        Route::post('/settings/weekday-rules/{id}/delete', [SettingsController::class, 'deleteWeekdayRule'])->whereNumber('id');
        Route::post('/settings/weekday-rules/{id}/exceptions/add', [SettingsController::class, 'addWeekdayException'])->whereNumber('id');
        Route::post('/settings/weekday-rules/{id}/exceptions/delete', [SettingsController::class, 'deleteWeekdayException'])->whereNumber('id');

        Route::post('/settings/translation-keys', [TranslationApiKeyController::class, 'store']);
        Route::post('/settings/translation-keys/test', [TranslationApiKeyController::class, 'test']);
        Route::get('/settings/translation-keys/{id}/edit', [TranslationApiKeyController::class, 'edit'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/update', [TranslationApiKeyController::class, 'update'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/delete', [TranslationApiKeyController::class, 'destroy'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/reset-usage', [TranslationApiKeyController::class, 'resetUsage'])->whereNumber('id');
        Route::post('/settings/translation-keys/{id}/fetch-usage', [TranslationApiKeyController::class, 'fetchUsageFromDeepL'])->whereNumber('id');
    });

    Route::middleware(RequireAdmin::class)->group(function () {
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::post('/admin/users/{id}/update', [AdminUserController::class, 'update'])->whereNumber('id');
        Route::post('/admin/users/{id}/password', [AdminUserController::class, 'updatePassword'])->whereNumber('id');
        Route::post('/admin/users/{id}/delete', [AdminUserController::class, 'destroy'])->whereNumber('id');

        Route::get('/admin/groups', [AdminGroupController::class, 'index']);
        Route::post('/admin/groups/{id}/approve', [AdminGroupController::class, 'approve'])->whereNumber('id');
        Route::post('/admin/groups/{id}/reject', [AdminGroupController::class, 'reject'])->whereNumber('id');
    });
});
