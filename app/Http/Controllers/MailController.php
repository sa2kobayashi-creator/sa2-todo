<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\MailAccountService;
use App\Services\MailClientService;
use App\Services\Sa2PlusMailboxService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MailController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private MailAccountService $accounts,
        private MailClientService $client,
        private Sa2PlusMailboxService $domainMail,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = (int) $request->query('account', 0);
        $folder = (string) $request->query('folder', 'INBOX');
        $uid = (int) $request->query('uid', 0);
        $page = max(1, (int) $request->query('page', 1));

        $accountList = $this->accounts->listForUser($user);
        $selected = null;
        foreach ($accountList as $acc) {
            if ($accountId === 0 || $acc->id === $accountId) {
                $selected = $acc;
                break;
            }
        }

        $folders = [];
        $messages = [];
        $message = null;
        $mailError = null;

        if ($selected) {
            try {
                $folders = $this->client->folders($selected);
                if ($folder === '' && $folders !== []) {
                    $folder = $folders[0]['path'];
                }
                $messages = $this->client->messages($selected, $folder, $page);
                if ($uid > 0) {
                    $message = $this->client->message($selected, $folder, $uid);
                }
            } catch (\Throwable $e) {
                $mailError = $e->getMessage();
            }
        }

        return view('mail.index', [
            'accounts' => array_map(fn ($a) => $a->toClientArray(), $accountList),
            'selectedAccountId' => $selected?->id,
            'folders' => $folders,
            'folder' => $folder,
            'messages' => $messages,
            'message' => $message,
            'page' => $page,
            'mailError' => $mailError,
            'domainRequests' => array_map(
                fn ($r) => $r->toPublicArray(),
                $this->domainMail->listForUser($user)
            ),
            'canRequestDomainMail' => $this->domainMail->userCanRequest($user),
            'mailDomain' => $this->domainMail->domain(),
            'freeQuota' => $this->domainMail->freeQuotaPerUser(),
            'apiComingSoon' => $this->domainMail->apiComingSoon(),
            'lolipopWebmailUrl' => (string) config('mail_domain.lolipop.webmail_url'),
            'gmailHelpUrl' => (string) config('mail_domain.gmail.help_url'),
            'compose' => $request->boolean('compose'),
            'tab' => (string) $request->query('tab', 'inbox'),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function storeAccount(Request $request)
    {
        try {
            $account = $this->accounts->create($request->user(), $request->all());
            $result = $this->accounts->testConnection($request->user(), (int) $account->id, $this->client);
            if (! $result['ok']) {
                return $this->redirectWithMessage(
                    '/mail?account='.$account->id.'&tab=accounts',
                    $result['message'],
                    'error'
                );
            }
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('入力内容を確認してください。');

            return $this->redirectWithMessage('/mail?tab=accounts', $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?tab=accounts', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/mail', __('メールアカウントを追加しました。'));
    }

    public function updateAccount(Request $request, int $id)
    {
        try {
            $this->accounts->update($request->user(), $id, $request->all());
            if (filled($request->input('password'))) {
                $result = $this->accounts->testConnection($request->user(), $id, $this->client);
                if (! $result['ok']) {
                    return $this->redirectWithMessage('/mail?account='.$id.'&tab=accounts', $result['message'], 'error');
                }

                return $this->redirectWithMessage('/mail?account='.$id.'&tab=accounts', __('パスワードを更新し、接続に成功しました。'));
            }
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('入力内容を確認してください。');

            return $this->redirectWithMessage('/mail?tab=accounts', $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?tab=accounts', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/mail?account='.$id, __('メールアカウントを更新しました。'));
    }

    public function destroyAccount(Request $request, int $id)
    {
        try {
            $this->accounts->delete($request->user(), $id);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?tab=accounts', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/mail', __('メールアカウントを削除しました。'));
    }

    public function testAccount(Request $request, int $id)
    {
        $result = $this->accounts->testConnection($request->user(), $id, $this->client);
        if ($result['ok']) {
            return $this->redirectWithMessage('/mail?account='.$id.'&tab=accounts', $result['message']);
        }

        return $this->redirectWithMessage('/mail?account='.$id.'&tab=accounts', $result['message'], 'error');
    }

    public function send(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $this->client->send($account, $request->only(['to', 'subject', 'body']));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?account='.$id.'&compose=1', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/mail?account='.$id, __('送信しました。'));
    }

    public function requestDomain(Request $request)
    {
        try {
            $this->domainMail->request($request->user(), $request->all());
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('申請に失敗しました。');

            return $this->redirectWithMessage('/mail?tab=domain', $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?tab=domain', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/mail?tab=domain',
            __('申請を受け付けました。管理者の承認後、手動でメールボックスが作成されます。')
        );
    }
}
