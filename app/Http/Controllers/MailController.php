<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\MailAccountService;
use App\Services\MailClientService;
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
        $selected = null;
        foreach ($accountList as $acc) {
            if ($accountId > 0 && $acc->id === $accountId) {
                $selected = $acc;
                break;
            }
            if ($accountId === 0 && $selected === null) {
                $selected = $acc;
            }
        }
        if ($accountId > 0 && $selected === null && $accountList !== []) {
            $selected = $accountList[0];
        }

        return view('mail.index', [
            'accounts' => array_map(fn ($a) => $a->toClientArray(), $accountList),
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
            $uid = (int) $request->query('uid', 0);
            $page = max(1, (int) $request->query('page', 1));
            $refresh = $request->boolean('refresh');

            if ($refresh) {
                $this->metadataSync->syncInbox($account);
            }

            $storedMessages = $this->metadataSync->page($account, $folder, $page);
            if ($storedMessages !== []) {
                $count = $this->metadataSync->count($account, $folder);

                return response()->json([
                    'ok' => true,
                    'folders' => [['name' => $folder, 'path' => $folder, 'messages' => $count]],
                    'folder' => $folder,
                    'messages' => $storedMessages,
                    'message' => null,
                    'page' => $page,
                    'cached' => true,
                    'syncedAt' => $account->fresh()->last_synced_at?->toIso8601String(),
                    'syncStatus' => $account->last_sync_status,
                ]);
            }

            $snapshot = $this->client->mailboxSnapshot($account, $folder, $page, $uid, 30, $refresh);

            if ($folder === 'INBOX' && $snapshot['messages'] !== []) {
                $this->metadataSync->syncInbox($account);
            }

            return response()->json([
                'ok' => true,
                'folders' => $snapshot['folders'],
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
                    'folders' => [['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0]],
                    'folder' => 'INBOX',
                    'messages' => [],
                    'message' => null,
                    'page' => 1,
                    'notice' => __('ăĄăźăŤăĺĺžă§ăăžăăă§ăăďźçŠşăŽĺżç­ďźăăă°ăăăăŚĺčŞ­ăżčžźăżăăŚăă ăăă'),
                ]);
            }

            return response()->json([
                'ok' => false,
                'message' => $msg !== '' ? $msg : __('ăĄăźăŤăŽčŞ­ăżčžźăżăŤĺ¤ąćăăžăăă'),
            ], 422);
        }
    }

    public function message(Request $request, int $id)
    {
        try {
            $account = $this->accounts->findOwned($request->user(), $id);
            $folder = (string) $request->query('folder', 'INBOX');
            $uid = (int) $request->query('uid', 0);
            $refresh = $request->boolean('refresh');
            if ($uid < 1) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ăĄăźăŤăčŚă¤ăăăžăăă'),
                ], 422);
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
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('ăĄăźăŤăŽčŞ­ăżčžźăżăŤĺ¤ąćăăžăăă'),
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
            $msg = collect($e->errors())->flatten()->first() ?: __('ĺĽĺĺĺŽšăç˘şčŞăăŚăă ăăă');

            return $this->redirectWithMessage('/mail?tab=accounts', $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?tab=accounts', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/mail?tab=accounts', __('ăĄăźăŤă˘ăŤăŚăłăăčż˝ĺ ăăžăăă'));
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

                return $this->redirectWithMessage($returnTo, __('ă˘ăŤăŚăłăăäżĺ­ăăćĽçśăŤćĺăăžăăă'));
            }
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('ĺĽĺĺĺŽšăç˘şčŞăăŚăă ăăă');

            return $this->redirectWithMessage($returnTo, $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ăĄăźăŤă˘ăŤăŚăłăăć´ć°ăăžăăă'));
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

        return $this->redirectWithMessage('/mail?tab=accounts', __('ăĄăźăŤă˘ăŤăŚăłăăĺé¤ăăžăăă'));
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

        return $this->redirectWithMessage('/mail?account='.$id, __('éäżĄăăžăăă'));
    }

    public function requestDomain(Request $request)
    {
        try {
            $this->domainMail->request($request->user(), $request->all());
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: __('çłčŤăŤĺ¤ąćăăžăăă');

            return $this->redirectWithMessage('/mail?tab=domain', $msg, 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/mail?tab=domain', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/mail?tab=domain',
            __('çłčŤăĺăäťăăžăăăçŽĄçčăŽćżčŞĺžăćĺă§ăĄăźăŤăăăŻăšăä˝ćăăăžăă')
        );
    }
}
