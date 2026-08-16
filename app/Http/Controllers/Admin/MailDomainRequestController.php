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
        return view('admin.mail-requests.index', [
            'pending' => array_map(fn ($r) => $r->toPublicArray(), $this->domainMail->listPendingForAdmin()),
            'recent' => array_map(fn ($r) => $r->toPublicArray(), $this->domainMail->listRecentForAdmin()),
            'mailDomain' => $this->domainMail->domain(),
            'apiComingSoon' => $this->domainMail->apiComingSoon(),
            'freeQuota' => $this->domainMail->freeQuotaPerUser(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        try {
            $this->domainMail->approve($request->user(), $id, $request->input('admin_note'));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/admin/mail-requests', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/admin/mail-requests',
            __('申請を承認しました。ロリポップ管理画面で手動作成してください（APIは近日公開予定）。')
        );
    }

    public function reject(Request $request, int $id)
    {
        try {
            $this->domainMail->reject($request->user(), $id, $request->input('admin_note'));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/admin/mail-requests', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/admin/mail-requests', __('申請を却下しました。'));
    }

    public function provision(Request $request, int $id)
    {
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
        try {
            $this->domainMail->suspend($request->user(), $id, $request->input('admin_note'));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/admin/mail-requests', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/admin/mail-requests', __('停止扱いにしました。'));
    }
}
