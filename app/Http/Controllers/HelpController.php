<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Mail\OperatorInquiryMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class HelpController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function index(): View
    {
        return view('help.index');
    }

    public function about(): View
    {
        $version = (string) config('app.version', '1.0.0');
        $path = base_path('VERSION');
        if (is_readable($path)) {
            $line = trim((string) File::get($path));
            if ($line !== '') {
                $version = $line;
            }
        }

        return view('help.about', [
            'appName' => config('app.name'),
            'appVersion' => $version,
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
            'environment' => config('app.env'),
            'locale' => app()->getLocale(),
            'timezone' => config('app.timezone'),
            'showEnvironment' => auth()->user()?->isAdmin() ?? false,
        ]);
    }

    public function overview(): View
    {
        return view('help.overview');
    }

    public function guide(): View
    {
        return view('help.guide');
    }

    public function contact(Request $request): View
    {
        return view('help.contact', $this->flashFromQuery($request));
    }

    public function sendInquiry(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();
        $recipients = User::query()
            ->where('role', UserRole::SuperAdmin)
            ->pluck('email')
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            return $this->redirectWithMessage('/contact', __('運営者のメールアドレスが見つかりません。'), 'error');
        }

        try {
            Mail::to($recipients)->send(new OperatorInquiryMail(
                senderName: (string) ($user->display_name ?: $user->email),
                senderEmail: (string) $user->email,
                subjectLine: $data['subject'],
                bodyText: $data['body'],
            ));
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithMessage('/contact', __('送信に失敗しました。時間をおいて再度お試しください。'), 'error');
        }

        return $this->redirectWithMessage('/contact', __('お問い合わせを送信しました。運営者から返信します。'));
    }
}
