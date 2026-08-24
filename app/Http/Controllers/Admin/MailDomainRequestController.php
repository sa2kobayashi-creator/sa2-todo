<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Services\Sa2PlusMailboxService;
use Illuminate\Http\Request;

class MailDomainRequestController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private Sa2PlusMailboxService $domainMail,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if ($actor->isTenantAdmin()) {
            abort(403, __('メール申請の処理は運営のみが行います。'));
        }
        return view('admin.mail-requests.index', [
            'pending' => array_map(fn ($r) => $r->toPublicArray(), $this->domainMail->listPendingForAdmin()),
            'recent' => array_map(fn ($r) => $r->toPublicArray(), $this->domainMail->listRecentForAdmin()),
            'mailDomain' => $this->domainMail->domain(),
            'apiComingSoon' => $this->domainMail->apiComingSoon(),
            'mailboxLimit' => $this->domainMail->mailboxesPerUser(),
            'addonPriceMonthly' => $this->domainMail->addonPriceYenMonthly(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $this->assertPlatformMailAdmin($request);
        try {
            $this->domainMail->approve($request->user(), $id, $request->input('admin_note'));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/admin/mail-requests', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/admin/mail-requests',
            __('申請を承認しました。ホスティング管理画面で手動作成してください（自動作成APIは近日公開予定）。')
        );
    }

    public function reject(Request $request, int $id)
    {
        $this->assertPlatformMailAdmin($request);
        try {
            $this->domainMail->reject($request->user(), $id, $request->input('admin_note'));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/admin/mail-requests', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/admin/mail-requests', __('申請を却下しました。'));
    }

    public function provision(Request $request, int $id)
    {
        $this->assertPlatformMailAdmin($request);
        try {
            $this->domainMail->markProvisioned($request->user(), $id, $request->input('admin_note'));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/admin/mail-requests', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/admin/mail-requests',
            __('作成済みにしました。利用者にパスワードを伝え、メーラー設定を案内してください。')
        );
    }

    public function suspend(Request $request, int $id)
    {
        $this->assertPlatformMailAdmin($request);
        try {
            $this->domainMail->suspend($request->user(), $id, $request->input('admin_note'));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/admin/mail-requests', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/admin/mail-requests', __('停止扱いにしました。'));
    }

    private function assertPlatformMailAdmin(Request $request): void
    {
        if ($request->user()?->isTenantAdmin()) {
            abort(403, __('メール申請の処理は運営のみが行います。'));
        }
    }
}
