<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationApplicationStatus;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Models\RegistrationApplication;
use App\Services\RegistrationApplicationService;
use Illuminate\Http\Request;

class RegistrationApplicationController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private readonly RegistrationApplicationService $applications) {}

    public function index(Request $request)
    {
        if (! $request->user()->isPlatformStaff()) {
            abort(403, __('利用申請の処理は運営のみが行います。'));
        }

        $pending = RegistrationApplication::query()
            ->where('status', RegistrationApplicationStatus::Pending)
            ->orderBy('created_at')
            ->get();

        $recent = RegistrationApplication::query()
            ->with(['reviewer', 'user'])
            ->where('status', '!=', RegistrationApplicationStatus::Pending)
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        return view('admin.applications.index', array_merge($this->flashFromQuery($request), [
            'pending' => $pending,
            'recent' => $recent,
        ]));
    }

    public function approve(Request $request, int $id)
    {
        if (! $request->user()->isPlatformStaff()) {
            abort(403, __('利用申請の処理は運営のみが行います。'));
        }

        $application = RegistrationApplication::query()->findOrFail($id);
        $note = trim((string) $request->input('admin_note', ''));

        try {
            $this->applications->approve($application, $request->user(), $note !== '' ? $note : null);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage('/admin/applications', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/admin/applications',
            __('申請を承認し、登録用のメールを送信しました。')
        );
    }

    public function resend(Request $request, int $id)
    {
        if (! $request->user()->isPlatformStaff()) {
            abort(403, __('利用申請の処理は運営のみが行います。'));
        }

        $application = RegistrationApplication::query()->findOrFail($id);

        try {
            $this->applications->resendActivation($application);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage('/admin/applications', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/admin/applications',
            __('登録用メールを再送しました。')
        );
    }

    public function reject(Request $request, int $id)
    {
        if (! $request->user()->isPlatformStaff()) {
            abort(403, __('利用申請の処理は運営のみが行います。'));
        }

        $application = RegistrationApplication::query()->findOrFail($id);
        $note = trim((string) $request->input('admin_note', ''));

        try {
            $this->applications->reject($application, $request->user(), $note !== '' ? $note : null);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage('/admin/applications', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/admin/applications', __('申請を却下し、申請者へメールを送りました。'));
    }
}
