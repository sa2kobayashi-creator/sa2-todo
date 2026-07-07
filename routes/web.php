<?php

use App\Http\Controllers\Api\HolidayDatesController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TodoController;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\ShareViewData;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

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

    Route::get('/notes', [NoteController::class, 'index']);
    Route::post('/notes', [NoteController::class, 'store']);
    Route::post('/notes/bulk/archive', [NoteController::class, 'bulkArchive']);
    Route::post('/notes/bulk/delete', [NoteController::class, 'bulkDelete']);
    Route::post('/notes/bulk/append', [NoteController::class, 'bulkAppend']);
    Route::post('/notes/{id}/update', [NoteController::class, 'update'])->whereNumber('id');
    Route::post('/notes/{id}/pin', [NoteController::class, 'pin'])->whereNumber('id');
    Route::post('/notes/{id}/archive', [NoteController::class, 'archive'])->whereNumber('id');
    Route::post('/notes/{id}/delete', [NoteController::class, 'destroy'])->whereNumber('id');

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/holidays/import', [SettingsController::class, 'importHolidays']);
    Route::post('/settings/holidays/add', [SettingsController::class, 'addHoliday']);
    Route::post('/settings/holidays/{id}/delete', [SettingsController::class, 'deleteHoliday'])->whereNumber('id');
    Route::post('/settings/weekday-rules/add', [SettingsController::class, 'addWeekdayRule']);
    Route::post('/settings/weekday-rules/{id}/delete', [SettingsController::class, 'deleteWeekdayRule'])->whereNumber('id');
    Route::post('/settings/weekday-rules/{id}/exceptions/add', [SettingsController::class, 'addWeekdayException'])->whereNumber('id');
    Route::post('/settings/weekday-rules/{id}/exceptions/delete', [SettingsController::class, 'deleteWeekdayException'])->whereNumber('id');

    Route::view('/mypage', 'mypage.stub');

    Route::middleware(RequireAdmin::class)->group(function () {
        Route::view('/admin/users', 'admin.users.stub');
    });
});
