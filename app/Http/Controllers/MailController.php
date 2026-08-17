<?php

namespace App\Http\Controllers;

use App\Models\MailFolder;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\MailAccountService;
use App\Services\MailActionService;
use App\Services\MailClientService;
use App\Services\MailFolderService;
use App\Services\MailLabelService;
use App\Services\MailMetadataSyncService;
use App\Services\Sa2PlusMailboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MailController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private MailAccountService $accounts,
        private MailClientService $client,
        private MailMetadataSyncService $metadataSync,
        private MailLabelService $labels,
        private MailFolderService $folders,
        private MailActionService $actions,
        private Sa2PlusMailboxService $domainMail,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = (int) $request->query('account', 0);
        $folder = (string) $request->query('folder', 'INBOX');
        $uid = (int) $request->query('uid', 0);
        $page = max(1, (int) $request->query('page', 1));
        $tab = (string) $request->query('tab', 'inbox');
        if (! in_array($tab, ['inbox', 'accounts', 'domain'], true)) {
            $tab = 'inbox';
        }

        $accountList = $this->accounts->listForUser($user);
        $selected = $this->accounts->preferDefault($user, $accountId);
        $accountArrays = array_map(fn ($a) => $a->toClientArray(), $accountList);

        $labelsByAccount = [];
        $userFoldersByAccount = [];
        foreach ($accountList as $acc) {
            if (! $acc->is_sa2_plus_mailbox) {
                continue;
            }
            $labelsByAccount[$acc->id] = array_map(
                fn ($label) => $label->toClientArray(),
                $this->labels->listForAccount($acc)
            );
            $userFoldersByAccount[$acc->id] = array_map(
                fn ($folder) => $folder->toClientArray(),
                $this->folders->listForAccount($acc)
            );
        }

        return view('mail.index', [
            'accounts' => $accountArrays,
            'primaryAccounts' => array_values(array_filter(
                $accountArrays,
                fn ($a) => ! empty($a['isSa2PlusMailbox'])
            )),
            'otherAccounts' => array_values(array_filter(
                $accountArrays,
                fn ($a) => empty($a['isSa2PlusMailbox'])
            )),
            'labelsByAccount' => $labelsByAccount,
            'userFoldersByAccount' => $userFoldersByAccount,
            'selectedAccountId' => $selected?->id,
            'editAccountId' => $accountId > 0 ? $accountId : ($selected?->id),
            'folders' => [],
            'folder' => $folder !== '' ? $folder : 'INBOX',
            'messages' => [],
            'message' => null,
            'page' => $page,
            'uid' => $uid,
            'mailError' => null,
            'loadMailboxAsync' => $tab === 'inbox' && $selected !== null && ! $request->boolean('compose'),
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
            'tab' => $tab,
            ...$this->flashFromQuery($request),
        ]);
    }

    public function mailbox(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $folder = (string) $request->query('folder', 'INBOX');
            $folder = $folder !== '' ? $folder : 'INBOX';
            $uid = (int) $request->query('uid', 0);
            $page = max(1, (int) $request->query('page', 1));
            $refresh = $request->boolean('refresh');

            if ($refresh) {
                $this->metadataSync->syncInbox($account);
            }

            $folders = [];
            try {
                $folders = $this->client->listFolders($account, $refresh);
            } catch (\Throwable $e) {
                Log::warning('mail.folders_for_mailbox_failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
                $folders = [['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0, 'kind' => 'inbox']];
            }

            // 独自ドメイン: アプリ管理フォルダ（Sa2.Sent 等）を必ず用意
            if ($this->client->isDomainMailbox($account)) {
                try {
                    $this->client->ensureAppSystemFolders($account);
                    $folders = $this->client->listFolders($account, true);
                } catch (\Throwable $e) {
                    Log::warning('mail.ensure_app_system_folders_failed', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $folder = $this->client->resolveFolderPath($folders, $folder);

            $labels = [];
            $userFolders = [];
            if ($account->is_sa2_plus_mailbox) {
                $labels = array_map(
                    fn ($label) => $label->toClientArray(),
                    $this->labels->listForAccount($account)
                );
                $userFolders = array_map(
                    fn ($folder) => $folder->toClientArray(),
                    $this->folders->listForAccount($account)
                );
            }

            $systemFolders = $this->client->systemFolderMenu($account, $folders);

            // ラベルなど、まだ無いアプリフォルダを開くときに作成
            if ($this->client->isDomainMailbox($account) && $folder !== 'INBOX') {
                $kind = $this->client->folderKind($folder, $folder);
                if (in_array($kind, ['sent', 'drafts', 'spam', 'trash', 'label', 'folder'], true)
                    || str_starts_with($folder, 'Sa2.')
                    || str_starts_with($folder, 'INBOX.Sa2.')
                    || str_starts_with($folder, 'Labels.')
                    || str_starts_with($folder, 'INBOX.Labels.')
                    || str_starts_with($folder, 'Folders.')
                    || str_starts_with($folder, 'INBOX.Folders.')) {
                    try {
                        $this->client->ensureFolder($account, $folder);
                        $folders = $this->client->listFolders($account, true);
                        $systemFolders = $this->client->systemFolderMenu($account, $folders);
                        $folder = $this->client->resolveFolderPath($folders, $folder);
                    } catch (\Throwable $e) {
                        Log::warning('mail.ensure_folder_failed', [
                            'account_id' => $account->id,
                            'folder' => $folder,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // メタデータ同期は INBOX のみ。他フォルダは IMAP を直接読む
            if ($folder === 'INBOX') {
                $storedMessages = $this->metadataSync->page($account, $folder, $page);
                if ($storedMessages !== []) {
                    $count = $this->metadataSync->count($account, $folder);
                    foreach ($folders as $i => $folderRow) {
                        if (($folderRow['path'] ?? '') === $folder) {
                            $folders[$i]['messages'] = $count;
                            break;
                        }
                    }
                    foreach ($systemFolders as $i => $folderRow) {
                        if (($folderRow['path'] ?? '') === $folder) {
                            $systemFolders[$i]['messages'] = $count;
                            break;
                        }
                    }

                    return response()->json([
                        'ok' => true,
                        'folders' => $folders,
                        'systemFolders' => $systemFolders,
                        'labels' => $labels,
                        'userFolders' => $userFolders,
                        'isSa2Plus' => true,
                        'archivePath' => $this->client->appSystemFolderPath('archive'),
                        'folder' => $folder,
                        'messages' => $storedMessages,
                        'message' => null,
                        'page' => $page,
                        'cached' => true,
                        'syncedAt' => $account->fresh()->last_synced_at?->toIso8601String(),
                        'syncStatus' => $account->last_sync_status,
                    ]);
                }
            }

            $snapshot = $this->client->mailboxSnapshot($account, $folder, $page, $uid, 30, $refresh);

            if ($folder === 'INBOX' && $snapshot['messages'] !== []) {
                $this->metadataSync->syncInbox($account);
            }

            $responseFolders = $snapshot['folders'] !== [] ? $snapshot['folders'] : $folders;

            return response()->json([
                'ok' => true,
                'folders' => $responseFolders,
                'systemFolders' => $this->client->systemFolderMenu($account, $responseFolders),
                'labels' => $labels,
                'userFolders' => $userFolders,
                'isSa2Plus' => (bool) $account->is_sa2_plus_mailbox,
                'archivePath' => $account->is_sa2_plus_mailbox ? $this->client->appSystemFolderPath('archive') : null,
                'folder' => $snapshot['folder'],
                'messages' => $snapshot['messages'],
                'message' => $snapshot['message'],
                'page' => $page,
                'cached' => (bool) ($snapshot['cached'] ?? false),
            ]);
        } catch (\Throwable $e) {
            Log::warning('mail.mailbox_failed', [
                'account_id' => $id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            $msg = $e->getMessage();
            $lower = strtolower($msg);
            if (str_contains($lower, 'empty response') || str_contains($lower, 'command failed to process')) {
                return response()->json([
                    'ok' => true,
                    'folders' => [['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0, 'kind' => 'inbox']],
                    'labels' => [],
                    'folder' => 'INBOX',
                    'messages' => [],
                    'message' => null,
                    'page' => 1,
                    'notice' => __('一覧を取得できませんでした。しばらくして再読み込みしてください。'),
                ]);
            }

            return response()->json([
                'ok' => false,
                'message' => $msg !== '' ? $msg : __('メールの読み込みに失敗しました。'),
            ], 422);
        }
    }

    public function message(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $folder = (string) $request->query('folder', 'INBOX');
            $folder = $folder !== '' ? $folder : 'INBOX';
            $uid = (int) $request->query('uid', 0);
            $refresh = $request->boolean('refresh');
            if ($uid < 1) {
                return response()->json([
                    'ok' => false,
                    'message' => __('メールが選ばれていません。'),
                ], 422);
            }

            try {
                $folders = $this->client->listFolders($account, false);
                $folder = $this->client->resolveFolderPath($folders, $folder);
            } catch (\Throwable) {
                // keep requested folder
            }

            $message = $this->client->readMessage($account, $folder, $uid, $refresh);

            return response()->json([
                'ok' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            Log::warning('mail.message_failed', [
                'account_id' => $id,
                'user_id' => $request->user()?->id,
                'uid' => (int) $request->query('uid', 0),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('メールの読み込みに失敗しました。'),
            ], 422);
        }
    }

    public function storeAccount(Request $request)
    {
        try {
            $account = $this->accounts->create($request->user(), $request->all());
            $result = $this->accounts->testConnection($request->user(), (int) $account->id, $this->client);
            if (! $result['ok']) {
                return $this->redirectWithMessage(
                    '/mail?tab=accounts&account='.$account->id,
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

        return $this->redirectWithMessage('/mail?tab=accounts', __('メールアカウントを追加しました。'));
    }

    public function updateAccount(Request $request, int $id)
    {
        $returnTo = '/mail?tab=accounts&account='.$id;
        try {
            $account = $this->accounts->update($request->user(), $id, $request->all());
            $this->client->forgetAccountCache($account);
            if (filled($request->input('password')) || $request->boolean('test_after_save')) {
                $result = $this->accounts->testConnection($request->user(), $id, $this->client);
                if (! $result['ok']) {
                    return $this->redirectWithMessage($returnTo, $result['message'], 'error');
                }

                return $this->redirectWithMessage($returnTo, __('アカウントを更新し、接続に成功しました。'));
            }
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('入力内容を確認してください。');

            return $this->redirectWithMessage($returnTo, $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('メールアカウントを更新しました。'));
    }

    public function destroyAccount(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $this->client->forgetAccountCache($account);
            $this->accounts->delete($request->user(), $id);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?tab=accounts', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/mail?tab=accounts', __('メールアカウントを削除しました。'));
    }

    public function testAccount(Request $request, int $id)
    {
        $result = $this->accounts->testConnection($request->user(), $id, $this->client);
        $returnTo = '/mail?tab=accounts&account='.$id;
        if ($result['ok']) {
            return $this->redirectWithMessage($returnTo, $result['message']);
        }

        return $this->redirectWithMessage($returnTo, $result['message'], 'error');
    }

    public function send(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $this->client->send($account, $request->only(['to', 'subject', 'body']));
            $this->client->forgetAccountCache($account);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?account='.$id.'&compose=1', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/mail?account='.$id, __('送信しました。'));
    }

    public function storeLabel(Request $request, int $id)
    {
        $returnTo = '/mail?tab=accounts&account='.$id;
        try {
            $this->labels->create($request->user(), $id, $request->all());
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('入力内容を確認してください。');

            return $this->redirectWithMessage($returnTo, $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ラベルを作成しました。'));
    }

    public function destroyLabel(Request $request, int $id, int $labelId)
    {
        $returnTo = '/mail?tab=accounts&account='.$id;
        try {
            $this->labels->delete($request->user(), $id, $labelId);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ラベルを削除しました。'));
    }

    public function storeLabelRule(Request $request, int $id, int $labelId)
    {
        $returnTo = '/mail?tab=accounts&account='.$id;
        try {
            $this->labels->addRule($request->user(), $id, $labelId, $request->all());
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('入力内容を確認してください。');

            return $this->redirectWithMessage($returnTo, $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('振り分けルールを追加しました。'));
    }

    public function destroyLabelRule(Request $request, int $id, int $ruleId)
    {
        $returnTo = '/mail?tab=accounts&account='.$id;
        try {
            $this->labels->deleteRule($request->user(), $id, $ruleId);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('振り分けルールを削除しました。'));
    }

    public function bulkMessages(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $validated = $request->validate([
                'folder' => ['nullable', 'string', 'max:255'],
                'uids' => ['required', 'array', 'min:1', 'max:50'],
                'uids.*' => ['integer', 'min:1'],
                'action' => ['required', 'string', 'in:read,unread,delete,archive,spam,not_spam,move'],
                'targetFolder' => ['nullable', 'string', 'max:255'],
                'registerSender' => ['sometimes', 'boolean'],
            ]);

            $folder = (string) ($validated['folder'] ?? 'INBOX');
            $result = $this->actions->bulk(
                $account,
                $folder !== '' ? $folder : 'INBOX',
                $validated['uids'],
                $validated['action'],
                $validated['targetFolder'] ?? null,
                (bool) ($validated['registerSender'] ?? false),
            );

            if ($folder === 'INBOX' && $account->is_sa2_plus_mailbox) {
                try {
                    $this->metadataSync->syncInbox($account);
                } catch (\Throwable) {
                    // 操作自体は成功している場合がある
                }
            }

            return response()->json([
                'ok' => true,
                'processed' => $result['ok'],
                'failed' => $result['failed'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('操作に失敗しました。'),
            ], 422);
        }
    }

    public function starMessage(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $validated = $request->validate([
                'folder' => ['nullable', 'string', 'max:255'],
                'uid' => ['required', 'integer', 'min:1'],
                'flagged' => ['required', 'boolean'],
            ]);

            $folder = (string) ($validated['folder'] ?? 'INBOX');
            $this->actions->toggleStar(
                $account,
                $folder !== '' ? $folder : 'INBOX',
                (int) $validated['uid'],
                (bool) $validated['flagged'],
            );

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('操作に失敗しました。'),
            ], 422);
        }
    }

    public function storeFolder(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
            ]);
            $folder = $this->folders->create($account, $validated['name']);

            return response()->json([
                'ok' => true,
                'folder' => $folder->toClientArray(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('フォルダの作成に失敗しました。'),
            ], 422);
        }
    }

    public function destroyFolder(Request $request, int $id, int $folderId)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $folder = MailFolder::query()
                ->where('mail_account_id', $account->id)
                ->whereKey($folderId)
                ->first();
            if (! $folder) {
                throw new \InvalidArgumentException(__('フォルダが見つかりません。'));
            }
            $this->folders->delete($account, $folder);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('フォルダの削除に失敗しました。'),
            ], 422);
        }
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
            __('申請を受け付けました。管理者の承認後、メールボックスが準備されます。')
        );
    }
}
