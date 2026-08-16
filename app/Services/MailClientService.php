<?php

namespace App\Services;

use App\Models\MailAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

class MailClientService
{
    private const LIST_CACHE_SECONDS = 180;

    private const MESSAGE_CACHE_SECONDS = 300;

    public function ping(MailAccount $account): void
    {
        $client = $this->connect($account);
        try {
            $client->getFolder('INBOX');
        } finally {
            $client->disconnect();
        }
    }

    public function forgetAccountCache(MailAccount $account): void
    {
        $prefix = 'mail:mb:'.$account->user_id.':'.$account->id.':';
        Cache::forget($prefix.'folders');
        foreach (['INBOX', 'Sent', '[Gmail]/Sent Mail', 'Drafts', '[Gmail]/Drafts', 'Trash', '[Gmail]/Trash'] as $folder) {
            for ($page = 1; $page <= 5; $page++) {
                Cache::forget($prefix.'list:'.md5($folder.'|'.$page.'|30'));
            }
        }
    }

    /**
     * 1接続でフォルダ一覧＋メッセージを取得（Gmail 向けに軽量）。
     * 2回目以降は短時間キャッシュで高速化。
     *
     * @return array{
     *   folders: list<array{name: string, path: string, messages: int}>,
     *   folder: string,
     *   messages: list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>,
     *   message: ?array{uid: int, subject: string, from: string, to: string, date: ?string, bodyHtml: string, bodyText: string},
     *   cached: bool
     * }
     */
    public function mailboxSnapshot(MailAccount $account, string $folderPath = 'INBOX', int $page = 1, int $uid = 0, int $perPage = 30, bool $refresh = false): array
    {
        $folderPath = $folderPath !== '' ? $folderPath : 'INBOX';
        $page = max(1, $page);
        $cacheRoot = 'mail:mb:'.$account->user_id.':'.$account->id.':';
        $listKey = $cacheRoot.'list:'.md5($folderPath.'|'.$page.'|'.$perPage);
        $foldersKey = $cacheRoot.'folders';

        if ($refresh) {
            $this->forgetAccountCache($account);
        }

        $cachedList = $refresh ? null : Cache::get($listKey);
        $cachedFolders = $refresh ? null : Cache::get($foldersKey);

        if (is_array($cachedList) && is_array($cachedFolders) && isset($cachedList['messages'])) {
            $resolvedFolder = (string) ($cachedList['folder'] ?? $folderPath);
            $message = null;
            if ($uid > 0) {
                $message = $this->cachedMessage($account, $resolvedFolder, $uid, false);
            }

            return [
                'folders' => $cachedFolders,
                'folder' => $resolvedFolder,
                'messages' => $cachedList['messages'],
                'message' => $message,
                'cached' => true,
            ];
        }

        $client = $this->connect($account);
        try {
            $folders = [];
            try {
                $folders = $this->listFoldersLight($client, $account);
            } catch (\Throwable $e) {
                Log::warning('mail.folders_list_failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
                // Gmail 等で LIST が重い／失敗しても INBOX だけは読む
                $folders = [['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0]];
            }

            if ($folders !== []) {
                $paths = array_column($folders, 'path');
                if (! in_array($folderPath, $paths, true)) {
                    $folderPath = in_array('INBOX', $paths, true) ? 'INBOX' : ($paths[0] ?? 'INBOX');
                }
            }

            $messages = $this->messagesWithClient($client, $account, $folderPath, $page, $perPage);
            $message = null;
            if ($uid > 0) {
                try {
                    $message = $this->messageWithClient($client, $folderPath, $uid);
                    Cache::put(
                        $cacheRoot.'msg:'.md5($folderPath.'|'.$uid),
                        $message,
                        now()->addSeconds(self::MESSAGE_CACHE_SECONDS)
                    );
                } catch (\Throwable $e) {
                    Log::warning('mail.message_fetch_failed', [
                        'account_id' => $account->id,
                        'uid' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Cache::put($foldersKey, $folders, now()->addSeconds(self::LIST_CACHE_SECONDS));
            Cache::put($listKey, [
                'folder' => $folderPath,
                'messages' => $messages,
            ], now()->addSeconds(self::LIST_CACHE_SECONDS));

            return [
                'folders' => $folders,
                'folder' => $folderPath,
                'messages' => $messages,
                'message' => $message,
                'cached' => false,
            ];
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array{uid: int, subject: string, from: string, to: string, date: ?string, bodyHtml: string, bodyText: string}|null
     */
    private function cachedMessage(MailAccount $account, string $folderPath, int $uid, bool $refresh = false): ?array
    {
        $key = 'mail:mb:'.$account->user_id.':'.$account->id.':msg:'.md5($folderPath.'|'.$uid);
        if (! $refresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $client = $this->connect($account);
        try {
            $message = $this->messageWithClient($client, $folderPath, $uid);
            Cache::put($key, $message, now()->addSeconds(self::MESSAGE_CACHE_SECONDS));

            return $message;
        } catch (\Throwable $e) {
            Log::warning('mail.message_fetch_failed', [
                'account_id' => $account->id,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return list<array{name: string, path: string, messages: int}>
     */
    public function folders(MailAccount $account): array
    {
        $client = $this->connect($account);
        try {
            return $this->listFoldersLight($client, $account);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>
     */
    public function messages(MailAccount $account, string $folderPath = 'INBOX', int $page = 1, int $perPage = 30): array
    {
        $client = $this->connect($account);
        try {
            return $this->messagesWithClient($client, $account, $folderPath, $page, $perPage);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array{uid: int, subject: string, from: string, to: string, date: ?string, bodyHtml: string, bodyText: string}
     */
    public function message(MailAccount $account, string $folderPath, int $uid): array
    {
        $client = $this->connect($account);
        try {
            return $this->messageWithClient($client, $folderPath, $uid);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param  array{to: string, subject: string, body: string}  $payload
     */
    public function send(MailAccount $account, array $payload): void
    {
        $to = trim((string) ($payload['to'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = (string) ($payload['body'] ?? '');

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(__('宛先のメールアドレスが不正です。'));
        }
        if ($subject === '') {
            throw new \InvalidArgumentException(__('件名を入力してください。'));
        }

        try {
            $dsn = $this->smtpDsn($account);
            $transport = Transport::fromDsn($dsn);
            $email = (new Email())
                ->from(new Address($account->email, $account->label ?: $account->email))
                ->to($to)
                ->subject($subject)
                ->text($body);

            $transport->send($email);
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->friendlyConnectError($account, $e), 0, $e);
        }
    }

    /**
     * @return list<array{name: string, path: string, messages: int}>
     */
    private function listFoldersLight($client, MailAccount $account): array
    {
        $out = [];
        try {
            // examine せず一覧だけ（Gmail のラベル大量時のタイムアウト防止）
            $folderList = $client->getFolders(false);
        } catch (\Throwable $e) {
            if ($this->isEmptyImapResponse($e)) {
                return [['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0]];
            }
            throw new \RuntimeException($this->friendlyConnectError($account, $e), 0, $e);
        }

        foreach ($folderList as $folder) {
            /** @var Folder $folder */
            $name = (string) $folder->name;
            $path = (string) $folder->path;
            if ($path === '' && $name === '') {
                continue;
            }
            // Gmail の深いラベルは省略して主要フォルダを優先
            if ($this->isGmailAccount($account) && str_contains($path, '/') && ! str_starts_with($path, '[Gmail]')) {
                continue;
            }
            $out[] = [
                'name' => $name !== '' ? $name : $path,
                'path' => $path !== '' ? $path : $name,
                'messages' => 0,
            ];
        }

        if ($out === []) {
            $out[] = ['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0];
        }

        usort($out, function (array $a, array $b) {
            $priority = [
                'INBOX' => 0,
                'Sent' => 1,
                'Sent Mail' => 1,
                '[Gmail]/Sent Mail' => 1,
                'Drafts' => 2,
                '[Gmail]/Drafts' => 2,
                'Trash' => 3,
                '[Gmail]/Trash' => 3,
            ];
            $pa = $priority[$a['name']] ?? $priority[$a['path']] ?? 50;
            $pb = $priority[$b['name']] ?? $priority[$b['path']] ?? 50;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return array_slice($out, 0, 40);
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>
     */
    private function messagesWithClient($client, MailAccount $account, string $folderPath, int $page, int $perPage): array
    {
        $folder = $this->openFolder($client, $folderPath);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        try {
            $status = $folder->examine();
            if ((int) ($status['exists'] ?? 0) === 0) {
                return [];
            }
        } catch (\Throwable $e) {
            if ($this->isEmptyImapResponse($e)) {
                return [];
            }
            // examine 失敗でも取得を試す
        }

        try {
            $query = $folder->messages()
                ->all()
                ->setFetchBody(false)
                ->setFetchFlags(true)
                ->leaveUnread()
                ->setFetchOrder('desc')
                ->limit($perPage, $page);
            $collection = $query->get();
        } catch (\Throwable $e) {
            if ($this->isEmptyImapResponse($e)) {
                return [];
            }
            throw new \RuntimeException($this->friendlyConnectError($account, $e), 0, $e);
        }

        $rows = [];
        foreach ($collection as $message) {
            /** @var Message $message */
            try {
                $from = $message->getFrom();
                $fromText = $from && count($from) > 0 ? (string) $from[0]->mail : '';
                $date = $message->getDate();
                $flags = $message->getFlags();
                $rows[] = [
                    'uid' => (int) $message->getUid(),
                    'subject' => (string) ($message->getSubject() ?: __('(件名なし)')),
                    'from' => $fromText,
                    'date' => $date ? $date->toDate()->format('c') : null,
                    'seen' => (bool) ($flags?->get('seen') ?? false),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $rows;
    }

    /**
     * @return array{uid: int, subject: string, from: string, to: string, date: ?string, bodyHtml: string, bodyText: string}
     */
    private function messageWithClient($client, string $folderPath, int $uid): array
    {
        $folder = $this->openFolder($client, $folderPath);
        try {
            $message = $folder->messages()->getMessageByUid($uid);
        } catch (\Throwable $e) {
            if ($this->isEmptyImapResponse($e)) {
                throw new \InvalidArgumentException(__('メールが見つかりません。'));
            }
            throw $e;
        }
        if (! $message) {
            throw new \InvalidArgumentException(__('メールが見つかりません。'));
        }

        $from = $message->getFrom();
        $to = $message->getTo();
        $date = $message->getDate();
        $html = (string) ($message->getHTMLBody() ?: '');
        $text = (string) ($message->getTextBody() ?: '');
        if ($html === '' && $text !== '') {
            $html = nl2br(e($text));
        }

        return [
            'uid' => (int) $message->getUid(),
            'subject' => (string) ($message->getSubject() ?: __('(件名なし)')),
            'from' => $from && count($from) > 0 ? (string) $from[0]->mail : '',
            'to' => $to && count($to) > 0 ? (string) $to[0]->mail : '',
            'date' => $date ? $date->toDate()->format('c') : null,
            'bodyHtml' => $html,
            'bodyText' => $text,
        ];
    }

    private function connect(MailAccount $account)
    {
        if (! class_exists(ClientManager::class)) {
            throw new \RuntimeException(
                __('メール用ライブラリ（webklex/php-imap）が未インストールです。サーバで composer install を実行してください。')
            );
        }

        $creds = $this->credentials($account);
        $cm = new ClientManager([
            'options' => [
                'fetch' => IMAP::FT_PEEK,
                'fetch_body' => false,
                'fetch_flags' => true,
                'fetch_order' => 'desc',
                'soft_fail' => true,
                'message_key' => 'list',
            ],
        ]);
        $encryption = $account->imap_encryption === 'none' ? false : $account->imap_encryption;
        $isGmail = $this->isGmailAccount($account);

        $client = $cm->make([
            'host' => $isGmail ? 'imap.gmail.com' : $account->imap_host,
            'port' => $isGmail ? 993 : $account->imap_port,
            'encryption' => $isGmail ? 'ssl' : $encryption,
            'validate_cert' => true,
            'username' => $creds['username'],
            'password' => $creds['password'],
            'protocol' => 'imap',
            'authentication' => null,
            'timeout' => 20,
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            // 共有サーバで証明書検証が落ちる場合のフォールバック
            try {
                $client = $cm->make([
                    'host' => $isGmail ? 'imap.gmail.com' : $account->imap_host,
                    'port' => $isGmail ? 993 : $account->imap_port,
                    'encryption' => $isGmail ? 'ssl' : $encryption,
                    'validate_cert' => false,
                    'username' => $creds['username'],
                    'password' => $creds['password'],
                    'protocol' => 'imap',
                    'authentication' => null,
                    'timeout' => 20,
                ]);
                $client->connect();
            } catch (\Throwable $e2) {
                Log::warning('mail.imap.connect_failed', [
                    'account_id' => $account->id,
                    'email' => $account->email,
                    'provider' => $account->provider,
                    'error' => $e2->getMessage(),
                ]);
                throw new \RuntimeException($this->friendlyConnectError($account, $e2), 0, $e2);
            }
        }

        return $client;
    }

    /**
     * @return array{username: string, password: string}
     */
    private function credentials(MailAccount $account): array
    {
        $username = trim((string) $account->username);
        $password = preg_replace('/\s+/u', '', (string) $account->password) ?? (string) $account->password;
        $email = strtolower(trim((string) $account->email));

        if ($this->isGmailAccount($account)) {
            $username = $email;
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    private function isGmailAccount(MailAccount $account): bool
    {
        $email = strtolower(trim((string) $account->email));

        return $account->provider === 'gmail'
            || str_ends_with($email, '@gmail.com')
            || str_ends_with($email, '@googlemail.com');
    }

    private function isEmptyImapResponse(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'empty response')
            || (str_contains($msg, 'command failed to process') && str_contains($msg, 'empty'));
    }

    private function friendlyConnectError(MailAccount $account, \Throwable $e): string
    {
        $raw = $e->getMessage();
        $lower = strtolower($raw);
        $isGmail = $this->isGmailAccount($account);

        if ($this->isEmptyImapResponse($e)) {
            return __('メールフォルダの読み込みに失敗しました。空のフォルダか、サーバ応答が不完全な可能性があります。接続テスト後にもう一度開いてください。');
        }

        if (str_contains($lower, 'connection timed out')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')) {
            return $isGmail
                ? __('Gmailへの接続がタイムアウトしました。サーバから imap.gmail.com:993 へ出られるか、アプリパスワードを確認してください。')
                : __('メールサーバへの接続がタイムアウトしました。');
        }

        if (str_contains($lower, 'connection refused')
            || str_contains($lower, 'network is unreachable')
            || str_contains($lower, 'failed to connect')
            || str_contains($lower, 'could not connect')) {
            return $isGmail
                ? __('Gmail IMAP（imap.gmail.com:993）に接続できません。レンタルサーバの外向き通信制限の可能性があります。')
                : __('メールサーバに接続できません。ホスト・ポート・ファイアウォールを確認してください。');
        }

        if (str_contains($lower, 'authenticationfailed')
            || str_contains($lower, 'invalid credentials')
            || str_contains($lower, 'badcredentials')
            || str_contains($lower, 'username and password not accepted')
            || str_contains($lower, '535')
            || (str_contains($lower, 'auth') && ! str_contains($lower, 'empty'))) {
            if ($isGmail) {
                return __('Gmailの認証に失敗しました。通常のログインパスワードではなく「アプリパスワード」（16桁）を使い、2段階認証を有効にしてください。アカウント設定でパスワードを再設定し、接続テスト後に送信してください。');
            }
            if ($account->provider === 'lolipop' || $account->is_sa2_plus_mailbox) {
                return __('ロリポップ／@sa2-plus.com の認証に失敗しました。メールアドレス全体をユーザー名にし、メールボックス作成時のパスワードを確認してください。');
            }

            return __('メール認証に失敗しました。メールアドレス・パスワード・IMAP/SMTP設定を確認してください。');
        }

        return $raw !== '' ? $raw : __('メールサーバへの接続に失敗しました。');
    }

    private function openFolder($client, string $folderPath): Folder
    {
        $path = $folderPath !== '' ? $folderPath : 'INBOX';
        try {
            return $client->getFolderByPath($path);
        } catch (\Throwable) {
            return $client->getFolder('INBOX');
        }
    }

    private function smtpDsn(MailAccount $account): string
    {
        $creds = $this->credentials($account);
        $user = rawurlencode($creds['username']);
        $pass = rawurlencode($creds['password']);

        if ($this->isGmailAccount($account)) {
            return sprintf(
                'smtp://%s:%s@smtp.gmail.com:587?encryption=tls&auth_mode=login',
                $user,
                $pass
            );
        }

        $host = $account->smtp_host;
        $port = $account->smtp_port;
        $enc = $account->smtp_encryption;

        $scheme = match ($enc) {
            'ssl' => 'smtps',
            'tls' => 'smtp',
            default => 'smtp',
        };

        $dsn = sprintf('%s://%s:%s@%s:%d', $scheme, $user, $pass, $host, $port);
        $query = ['auth_mode' => 'login'];
        if ($enc === 'tls') {
            $query['encryption'] = 'tls';
        } elseif ($enc === 'none') {
            $query['verify_peer'] = '0';
        }
        $dsn .= '?'.http_build_query($query);

        return $dsn;
    }
}
