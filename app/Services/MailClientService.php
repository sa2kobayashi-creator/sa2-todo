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
    private const LIST_CACHE_SECONDS = 1800;

    private const MESSAGE_CACHE_SECONDS = 3600;

    private ?MailHtmlSanitizer $htmlSanitizer = null;

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
        foreach ([
            'INBOX',
            'INBOX.Sa2.Sent', 'INBOX.Sa2.Drafts', 'INBOX.Sa2.Junk', 'INBOX.Sa2.Trash', 'INBOX.Sa2.Archive',
            'Sa2.Sent', 'Sa2.Drafts', 'Sa2.Spam', 'Sa2.Junk', 'Sa2.Trash', 'Sa2.Archive',
            'Sent', '[Gmail]/Sent Mail',
            'Drafts', '[Gmail]/Drafts',
            'Junk', 'Spam', '[Gmail]/Spam',
            'Trash', '[Gmail]/Trash',
        ] as $folder) {
            for ($page = 1; $page <= 5; $page++) {
                Cache::forget($prefix.'list:'.md5($folder.'|'.$page.'|30'));
            }
        }
    }

    /**
     * キャッシュ優先のフォルダ一覧（なければ軽量 LIST）。
     *
     * @return list<array{name: string, path: string, messages: int, kind?: string}>
     */
    public function listFolders(MailAccount $account, bool $refresh = false): array
    {
        $foldersKey = 'mail:mb:'.$account->user_id.':'.$account->id.':folders';
        if (! $refresh) {
            $cached = Cache::get($foldersKey);
            if (is_array($cached) && $cached !== []) {
                return array_map(fn (array $row) => $this->withFolderKind($row), $cached);
            }
        }

        $client = $this->connect($account);
        try {
            $folders = $this->listFoldersLight($client, $account);
            Cache::put($foldersKey, $folders, now()->addSeconds(self::LIST_CACHE_SECONDS));

            return array_map(fn (array $row) => $this->withFolderKind($row), $folders);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * IMAP 上にフォルダを確保する。実際に使えたパスを返す。
     */
    public function ensureFolder(MailAccount $account, string $folderPath): string
    {
        $folderPath = trim($folderPath);
        if ($folderPath === '' || strcasecmp($folderPath, 'INBOX') === 0) {
            return $folderPath !== '' ? $folderPath : 'INBOX';
        }

        $folderPath = $this->encodeImapMailboxPath($folderPath);

        $client = $this->connect($account);
        try {
            if ($this->folderExistsOnClient($client, $folderPath)) {
                return $folderPath;
            }

            $delimiter = $this->imapDelimiter($client);
            $candidates = [$folderPath];
            // 共有サーバではルート直下より INBOX 配下が作れることが多い
            if (! str_starts_with(strtoupper($folderPath), 'INBOX'.$delimiter)
                && strcasecmp($folderPath, 'INBOX') !== 0
                && ! str_starts_with(strtoupper($folderPath), 'INBOX.')) {
                $candidates[] = 'INBOX'.$delimiter.ltrim(str_replace(['.', '/'], $delimiter, $folderPath), $delimiter);
            }
            // ドット／スラッシュ両対応
            $alt = str_replace('.', '/', $folderPath);
            if ($alt !== $folderPath) {
                $candidates[] = $alt;
            }
            $altDot = str_replace('/', '.', $folderPath);
            if ($altDot !== $folderPath) {
                $candidates[] = $altDot;
            }

            // INBOX 付き候補を先に試す（Lolipop 等）
            usort($candidates, static function (string $a, string $b): int {
                $aInbox = str_starts_with(strtoupper($a), 'INBOX.') || str_starts_with(strtoupper($a), 'INBOX/');
                $bInbox = str_starts_with(strtoupper($b), 'INBOX.') || str_starts_with(strtoupper($b), 'INBOX/');
                if ($aInbox === $bInbox) {
                    return 0;
                }

                return $aInbox ? -1 : 1;
            });

            $lastError = null;
            foreach (array_values(array_unique($candidates)) as $candidate) {
                try {
                    $this->createFolderPathOnClient($client, $candidate, $delimiter);
                    if ($this->folderExistsOnClient($client, $candidate)) {
                        $this->forgetAccountCache($account);

                        return $this->encodeImapMailboxPath($candidate);
                    }
                } catch (\Throwable $e) {
                    $lastError = $e;
                }
            }

            throw new \RuntimeException(
                __('フォルダ「:folder」を作成できませんでした。', ['folder' => $this->decodeImapMailboxPath($folderPath)])
                    .($lastError ? ' '.$lastError->getMessage() : ''),
                0,
                $lastError
            );
        } finally {
            $client->disconnect();
        }
    }

    private function imapDelimiter($client): string
    {
        try {
            if (method_exists($client, 'getDelimiter')) {
                $d = $client->getDelimiter();
                if (is_string($d) && $d !== '') {
                    return $d;
                }
            }
        } catch (\Throwable) {
        }

        return '.';
    }

    private function folderExistsOnClient($client, string $folderPath): bool
    {
        foreach (array_unique(array_filter([$folderPath, $this->encodeImapMailboxPath($folderPath), $this->decodeImapMailboxPath($folderPath)])) as $candidate) {
            try {
                if ($client->getFolderByPath($candidate)) {
                    return true;
                }
            } catch (\Throwable) {
            }
            try {
                if ($client->getFolder($candidate)) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }

    private function createFolderPathOnClient($client, string $folderPath, string $delimiter): void
    {
        $folderPath = $this->encodeImapMailboxPath($folderPath);
        $parts = preg_split('/[\\.\\/]/', $folderPath) ?: [];
        $built = '';
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $built = $built === '' ? $part : $built.$delimiter.$part;
            if ($this->folderExistsOnClient($client, $built)) {
                continue;
            }
            $client->createFolder($built, true);
        }
    }

    /**
     * IMAP mailbox パスの各セグメントを modified UTF-7 にエンコードする。
     */
    public function encodeImapMailboxPath(string $path): string
    {
        $path = trim(str_replace('/', '.', $path));
        if ($path === '') {
            return $path;
        }
        $parts = explode('.', $path);
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out[] = $this->encodeImapMailboxSegment($part);
        }

        return implode('.', $out);
    }

    public function encodeImapMailboxSegment(string $segment): string
    {
        $segment = trim($segment);
        if ($segment === '' || strcasecmp($segment, 'INBOX') === 0) {
            return $segment === '' ? '' : 'INBOX';
        }
        // 既に modified UTF-7
        if (preg_match('/^&[A-Za-z0-9+,]+-$/', $segment) === 1) {
            return $segment;
        }
        // ASCII（& 以外）はそのまま。& は UTF-7 のエスケープが必要
        if (preg_match('/^[\x20-\x7e]+$/', $segment) === 1 && ! str_contains($segment, '&')) {
            return $segment;
        }
        if (function_exists('imap_utf7_encode')) {
            $encoded = imap_utf7_encode($segment);

            return is_string($encoded) && $encoded !== '' ? $encoded : $segment;
        }

        return $segment;
    }

    public function decodeImapMailboxPath(string $path): string
    {
        $path = trim(str_replace('/', '.', $path));
        if ($path === '') {
            return $path;
        }
        $parts = explode('.', $path);
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out[] = $this->decodeImapMailboxSegment($part);
        }

        return implode('.', $out);
    }

    public function decodeImapMailboxSegment(string $segment): string
    {
        $segment = trim($segment);
        if ($segment === '' || strcasecmp($segment, 'INBOX') === 0) {
            return $segment === '' ? '' : 'INBOX';
        }
        if (function_exists('imap_utf7_decode') && str_contains($segment, '&')) {
            $decoded = @imap_utf7_decode($segment);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $segment;
    }

    /** 親ネームスペースだけの行（INBOX.Folders / INBOX.Sa2 等）か */
    public function isNamespaceOnlyFolder(string $path): bool
    {
        $p = strtolower(str_replace('/', '.', trim($path)));

        return in_array($p, [
            'folders',
            'inbox.folders',
            'sa2',
            'inbox.sa2',
            'labels',
            'inbox.labels',
        ], true);
    }

    public function moveMessage(MailAccount $account, string $fromFolder, int $uid, string $toFolder): void
    {
        if ($uid < 1 || $fromFolder === '' || $toFolder === '') {
            throw new \InvalidArgumentException(__('移動先が不正です。'));
        }
        if ($fromFolder === $toFolder) {
            return;
        }

        $toFolder = $this->ensureFolder($account, $toFolder);
        $client = $this->connect($account);
        try {
            $folder = $this->openFolder($client, $fromFolder);
            $message = $folder->query()->getMessageByUid($uid);
            if (! $message) {
                throw new \RuntimeException(__('メールが見つかりません。'));
            }
            $message->move($toFolder);
            $this->forgetAccountCache($account);
        } finally {
            $client->disconnect();
        }
    }

    public function setMessageFlag(MailAccount $account, string $folderPath, int $uid, string $flag, bool $set): void
    {
        if ($uid < 1 || $folderPath === '') {
            throw new \InvalidArgumentException(__('メールが不正です。'));
        }

        $client = $this->connect($account);
        try {
            $folder = $this->openFolder($client, $folderPath);
            $message = $folder->messages()
                ->setFetchBody(false)
                ->setFetchFlags(true)
                ->getMessageByUid($uid);
            if (! $message) {
                throw new \RuntimeException(__('メールが見つかりません。'));
            }
            if ($set) {
                $message->setFlag($flag);
            } else {
                $message->unsetFlag($flag);
            }
            $this->forgetAccountCache($account);
        } finally {
            $client->disconnect();
        }
    }

    public function getMessageFromEmail(MailAccount $account, string $folderPath, int $uid): string
    {
        if ($uid < 1 || $folderPath === '') {
            return '';
        }

        $client = $this->connect($account);
        try {
            $folder = $this->openFolder($client, $folderPath);
            $message = $folder->messages()
                ->setFetchBody(false)
                ->setFetchFlags(false)
                ->getMessageByUid($uid);
            if (! $message) {
                return '';
            }

            return $this->firstAddressEmail($message->getFrom());
        } catch (\Throwable) {
            return '';
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param  array{name: string, path: string, messages: int}  $row
     * @return array{name: string, path: string, messages: int, kind: string}
     */
    private function withFolderKind(array $row): array
    {
        $row['kind'] = $this->folderKind((string) ($row['path'] ?? ''), (string) ($row['name'] ?? ''));

        return $row;
    }

    public function folderKind(string $path, string $name = ''): string
    {
        $rawPath = trim($path);
        $lowerPath = mb_strtolower(str_replace('/', '.', $rawPath));
        // アプリ管理: Sa2.* / INBOX.Sa2.*
        if (preg_match('/(?:^|[.])sa2[.]/i', $lowerPath) === 1) {
            return match (true) {
                str_ends_with($lowerPath, '.sent') => 'sent',
                str_ends_with($lowerPath, '.drafts'), str_ends_with($lowerPath, '.draft') => 'drafts',
                str_ends_with($lowerPath, '.junk'), str_ends_with($lowerPath, '.spam'), str_ends_with($lowerPath, '.meiwaku') => 'spam',
                str_ends_with($lowerPath, '.trash') => 'trash',
                str_ends_with($lowerPath, '.archive') => 'archive',
                default => 'other',
            };
        }

        $lowerPathFull = mb_strtolower(trim($path));
        if (str_starts_with($lowerPathFull, 'folders.')
            || str_starts_with($lowerPathFull, 'folders/')
            || str_starts_with($lowerPathFull, 'inbox.folders.')
            || str_starts_with($lowerPathFull, 'inbox.folders/')
            || str_starts_with($lowerPathFull, 'inbox/folders/')) {
            return 'folder';
        }

        $probe = mb_strtolower(trim(($path !== '' ? $path : '').' '.($name !== '' ? $name : '')));
        $probe = str_replace(['＿', '　'], ['', ''], $probe);
        if ($probe === 'inbox' || str_contains($probe, '受信')) {
            return 'inbox';
        }
        if (str_contains($probe, 'sent') || str_contains($probe, '送信')) {
            return 'sent';
        }
        if (str_contains($probe, 'draft') || str_contains($probe, '下書き')) {
            return 'drafts';
        }
        if (str_contains($probe, 'junk') || str_contains($probe, 'spam') || str_contains($probe, '迷惑')) {
            return 'spam';
        }
        if (str_contains($probe, 'trash') || str_contains($probe, 'bin') || str_contains($probe, 'deleted') || str_contains($probe, 'ごみ') || str_contains($probe, 'ゴミ箱') || str_contains($probe, '削除')) {
            return 'trash';
        }
        if (str_starts_with($lowerPathFull, 'labels.')
            || str_starts_with($lowerPathFull, 'labels/')
            || str_starts_with($lowerPathFull, 'inbox.labels.')
            || str_starts_with($lowerPathFull, 'inbox.labels/')
            || str_starts_with($lowerPathFull, 'inbox/labels/')) {
            return 'label';
        }

        return 'other';
    }

    public function isDomainMailbox(MailAccount $account): bool
    {
        return $account->provider === 'lolipop' || (bool) $account->is_sa2_plus_mailbox;
    }

    /**
     * アプリが管理する標準フォルダ（独自ドメイン専用）。
     * INBOX 配下に置き、迷惑メール名は予約語になりやすい Spam を避けて Junk にする。
     */
    public function appSystemFolderPath(string $kind): ?string
    {
        return match ($kind) {
            'sent' => 'INBOX.Sa2.Sent',
            'drafts' => 'INBOX.Sa2.Drafts',
            'spam' => 'INBOX.Sa2.Junk',
            'trash' => 'INBOX.Sa2.Trash',
            'archive' => 'INBOX.Sa2.Archive',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function ensureAppSystemFolders(MailAccount $account): array
    {
        if (! $this->isDomainMailbox($account)) {
            return [];
        }

        $paths = [];
        foreach (['sent', 'drafts', 'spam', 'trash', 'archive'] as $kind) {
            $path = $this->appSystemFolderPath($kind);
            if ($path === null) {
                continue;
            }
            try {
                $this->ensureFolder($account, $path);
                $paths[] = $path;
            } catch (\Throwable $e) {
                Log::warning('mail.ensure_app_folder_failed', [
                    'account_id' => $account->id,
                    'folder' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->forgetAccountCache($account);

        return $paths;
    }

    /**
     * ホスティング側の標準フォルダか（アプリの Sa2.* とは別物）。
     */
    public function isHostManagedSystemFolder(string $path, string $name = ''): bool
    {
        $probe = mb_strtolower(trim($path !== '' ? $path : $name));
        if ($probe === '' || str_starts_with($probe, 'sa2.') || str_starts_with($probe, 'inbox.sa2.') || str_starts_with($probe, 'labels.')) {
            return false;
        }
        if ($probe === 'inbox') {
            return false;
        }

        $hostNames = [
            'sent', 'sent messages', 'sent mail', 'inbox.sent', 'inbox.sent messages',
            'drafts', 'draft', 'inbox.drafts', 'inbox.draft',
            'junk', 'spam', 'bulk mail', 'inbox.junk', 'inbox.spam',
            'trash', 'deleted messages', 'inbox.trash', 'inbox.deleted messages',
            '[gmail]/sent mail', '[gmail]/drafts', '[gmail]/spam', '[gmail]/trash',
        ];

        return in_array($probe, $hostNames, true);
    }

    /**
     * 要求されたフォルダを実在パスへ解決（なければ kind / 別名で探す。安易に INBOX へ落とさない）。
     *
     * @param  list<array{name?: string, path?: string, kind?: string}>  $folders
     */
    public function resolveFolderPath(array $folders, string $requested): string
    {
        $requested = trim($requested);
        if ($requested === '') {
            return 'INBOX';
        }

        foreach ($folders as $folder) {
            $path = (string) ($folder['path'] ?? '');
            $name = (string) ($folder['name'] ?? '');
            if ($path === $requested || $name === $requested) {
                return $path !== '' ? $path : $name;
            }
        }

        foreach ($folders as $folder) {
            $path = (string) ($folder['path'] ?? '');
            $name = (string) ($folder['name'] ?? '');
            if (strcasecmp($path, $requested) === 0 || strcasecmp($name, $requested) === 0) {
                return $path !== '' ? $path : $name;
            }
        }

        $wantedKind = $this->folderKind($requested, $requested);
        if ($wantedKind !== 'other' && $wantedKind !== 'label') {
            foreach ($folders as $folder) {
                $path = (string) ($folder['path'] ?? '');
                $name = (string) ($folder['name'] ?? '');
                $kind = (string) ($folder['kind'] ?? $this->folderKind($path, $name));
                if ($kind === $wantedKind && $path !== '') {
                    return $path;
                }
            }
        }

        foreach ($this->folderAliases($wantedKind, $requested) as $alias) {
            foreach ($folders as $folder) {
                $path = (string) ($folder['path'] ?? '');
                $name = (string) ($folder['name'] ?? '');
                if ($path === $alias || $name === $alias || strcasecmp($path, $alias) === 0) {
                    return $path !== '' ? $path : $name;
                }
            }
        }

        return $requested;
    }

    /**
     * @return list<string>
     */
    private function folderAliases(string $kind, string $requested): array
    {
        $aliases = match ($kind) {
            'inbox' => ['INBOX'],
            'sent' => ['INBOX.Sa2.Sent', 'Sa2.Sent', 'INBOX.Sent', 'Sent', 'Sent Messages', 'Sent Mail', '[Gmail]/Sent Mail'],
            'drafts' => ['INBOX.Sa2.Drafts', 'Sa2.Drafts', 'INBOX.Drafts', 'Drafts', 'Draft', '[Gmail]/Drafts'],
            'spam' => ['INBOX.Sa2.Junk', 'Sa2.Junk', 'Sa2.Spam', 'INBOX.Junk', 'INBOX.Spam', 'Junk', 'Spam', '[Gmail]/Spam'],
            'trash' => ['INBOX.Sa2.Trash', 'Sa2.Trash', 'INBOX.Trash', 'Trash', 'Deleted Messages', '[Gmail]/Trash'],
            default => [],
        };
        if ($requested !== '' && ! in_array($requested, $aliases, true)) {
            array_unshift($aliases, $requested);
        }

        return $aliases;
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
                $folderPath = $this->resolveFolderPath($folders, $folderPath);
            }

            $listed = $this->messagesWithClient($client, $account, $folderPath, $page, $perPage);
            $messages = $listed['messages'];
            $exists = $listed['exists'];

            foreach ($folders as $i => $folderRow) {
                if (($folderRow['path'] ?? '') === $folderPath) {
                    $folders[$i]['messages'] = $exists;
                    break;
                }
            }

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

            // 件数があるのに空一覧だった場合はキャッシュしない（失敗の固定化を防ぐ）
            if (! ($exists > 0 && $messages === [])) {
                Cache::put($foldersKey, $folders, now()->addSeconds(self::LIST_CACHE_SECONDS));
                Cache::put($listKey, [
                    'folder' => $folderPath,
                    'messages' => $messages,
                ], now()->addSeconds(self::LIST_CACHE_SECONDS));
            }

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
            return $this->messagesWithClient($client, $account, $folderPath, $page, $perPage)['messages'];
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
     * 本文取得（キャッシュ付き）。UI のメールクリック用。
     *
     * @return array{uid: int, subject: string, from: string, to: string, date: ?string, bodyHtml: string, bodyText: string}
     */
    public function readMessage(MailAccount $account, string $folderPath, int $uid, bool $refresh = false): array
    {
        $folderPath = $folderPath !== '' ? $folderPath : 'INBOX';
        $key = 'mail:mb:'.$account->user_id.':'.$account->id.':msg:'.md5($folderPath.'|'.$uid);
        if (! $refresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $message = $this->message($account, $folderPath, $uid);
        Cache::put($key, $message, now()->addSeconds(self::MESSAGE_CACHE_SECONDS));

        return $message;
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

            if ($this->isDomainMailbox($account)) {
                try {
                    $this->appendToAppSent($account, $email);
                } catch (\Throwable $e) {
                    Log::warning('mail.append_sent_failed', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->friendlyConnectError($account, $e), 0, $e);
        }
    }

    private function appendToAppSent(MailAccount $account, Email $email): void
    {
        $path = $this->appSystemFolderPath('sent');
        if ($path === null) {
            return;
        }
        $this->ensureFolder($account, $path);
        $client = $this->connect($account);
        try {
            $folder = $this->openFolder($client, $path);
            $raw = $email->toString();
            if (method_exists($folder, 'append')) {
                $folder->append($raw, ['\Seen']);
            } else {
                $client->getConnection()->appendMessage($folder->path, $raw, ['\Seen']);
            }
            $this->forgetAccountCache($account);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return list<array{name: string, path: string, messages: int, kind: string}>
     */
    private function listFoldersLight($client, MailAccount $account): array
    {
        $out = [];
        try {
            // examine せず一覧だけ（Gmail のラベル大量時のタイムアウト防止）
            $folderList = $client->getFolders(false);
        } catch (\Throwable $e) {
            if ($this->isEmptyImapResponse($e)) {
                return [$this->withFolderKind(['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0])];
            }
            throw new \RuntimeException($this->friendlyConnectError($account, $e), 0, $e);
        }

        $this->collectFoldersRecursive($folderList, $account, $out);

        if ($out === []) {
            $out[] = $this->withFolderKind(['name' => 'INBOX', 'path' => 'INBOX', 'messages' => 0]);
        }

        if ($this->isDomainMailbox($account)) {
            // ロリポップ側の Sent/Junk 等はサイドバー標準枠に使わない（アプリの Sa2.* を正とする）
            $out = array_values(array_filter(
                $out,
                fn (array $row) => ! $this->isHostManagedSystemFolder(
                    (string) ($row['path'] ?? ''),
                    (string) ($row['name'] ?? '')
                )
            ));
            foreach (['sent', 'drafts', 'spam', 'trash', 'archive'] as $kind) {
                $appPath = $this->appSystemFolderPath($kind);
                if ($appPath === null) {
                    continue;
                }
                $exists = false;
                foreach ($out as $row) {
                    if (($row['path'] ?? '') === $appPath) {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $out[] = $this->withFolderKind([
                        'name' => match ($kind) {
                            'sent' => 'Sent',
                            'drafts' => 'Drafts',
                            'spam' => 'Spam',
                            'trash' => 'Trash',
                            'archive' => 'Archive',
                            default => $appPath,
                        },
                        'path' => $appPath,
                        'messages' => 0,
                    ]);
                }
            }
        } else {
            $out = $this->enrichMissingSystemFolders($client, $account, $out);
        }

        $out = array_values(array_filter(
            $out,
            fn (array $row) => ! $this->isNamespaceOnlyFolder((string) ($row['path'] ?? ''))
        ));

        usort($out, function (array $a, array $b) {
            $kindOrder = [
                'inbox' => 0,
                'sent' => 1,
                'drafts' => 2,
                'archive' => 3,
                'spam' => 4,
                'trash' => 5,
                'folder' => 6,
                'label' => 7,
                'other' => 8,
            ];
            $pa = $kindOrder[$a['kind'] ?? 'other'] ?? 8;
            $pb = $kindOrder[$b['kind'] ?? 'other'] ?? 8;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return array_slice($out, 0, 60);
    }

    /**
     * @param  mixed  $folderList
     * @param  list<array{name: string, path: string, messages: int, kind: string}>  $out
     */
    private function collectFoldersRecursive($folderList, MailAccount $account, array &$out, int $depth = 0): void
    {
        if ($depth > 8 || $folderList === null) {
            return;
        }

        foreach ($folderList as $folder) {
            if (! is_object($folder)) {
                continue;
            }
            $name = (string) ($folder->name ?? '');
            $path = (string) ($folder->path ?? '');
            if ($path === '' && $name === '') {
                continue;
            }
            // Gmail の深いユーザーラベルは省略（[Gmail]/* は残す）
            $skipDeepGmail = $this->isGmailAccount($account)
                && str_contains($path, '/')
                && ! str_starts_with($path, '[Gmail]');

            if (! $skipDeepGmail) {
                $displayPath = $path !== '' ? $path : $name;
                $displayName = $name !== '' ? $name : $path;
                $decodedPath = $this->decodeImapMailboxPath($displayPath);
                $decodedName = $this->decodeImapMailboxSegment(
                    str_contains($displayName, '.')
                        ? (basename(str_replace('.', '/', $displayName)) ?: $displayName)
                        : $displayName
                );
                // パス全体が名前になっている場合は末尾だけ表示用にする
                if (strcasecmp($displayName, $displayPath) === 0 || str_contains($displayName, 'INBOX.Folders') || str_contains($displayName, 'INBOX.Labels')) {
                    $leaf = basename(str_replace('.', '/', $decodedPath));
                    $decodedName = $leaf !== '' ? $leaf : $decodedName;
                }
                $row = [
                    'name' => $decodedName !== '' ? $decodedName : $displayName,
                    'path' => $displayPath,
                    'messages' => 0,
                ];
                $pathKey = $row['path'];
                $exists = false;
                foreach ($out as $existing) {
                    if (($existing['path'] ?? '') === $pathKey) {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists && ! $this->isNamespaceOnlyFolder($pathKey)) {
                    $out[] = $this->withFolderKind($row);
                }
            }

            $children = $folder->children ?? null;
            if ($children) {
                $this->collectFoldersRecursive($children, $account, $out, $depth + 1);
            }
        }
    }

    /**
     * LIST に載らない標準フォルダを別名で探して補完する。
     *
     * @param  list<array{name: string, path: string, messages: int, kind: string}>  $out
     * @return list<array{name: string, path: string, messages: int, kind: string}>
     */
    private function enrichMissingSystemFolders($client, MailAccount $account, array $out): array
    {
        $have = [];
        foreach ($out as $row) {
            $kind = (string) ($row['kind'] ?? 'other');
            if ($kind !== 'other' && $kind !== 'label') {
                $have[$kind] = true;
            }
        }

        foreach (['sent', 'drafts', 'spam', 'trash'] as $kind) {
            if (! empty($have[$kind])) {
                continue;
            }
            foreach ($this->preferredSystemPaths($account, $kind) as $candidate) {
                try {
                    $folder = $client->getFolderByPath($candidate);
                    if (! $folder) {
                        $folder = $client->getFolder($candidate);
                    }
                    if (! $folder) {
                        continue;
                    }
                    $name = (string) ($folder->name ?? $candidate);
                    $path = (string) ($folder->path ?? $candidate);
                    $out[] = $this->withFolderKind([
                        'name' => $name !== '' ? $name : $path,
                        'path' => $path !== '' ? $path : $candidate,
                        'messages' => 0,
                    ]);
                    $have[$kind] = true;
                    break;
                } catch (\Throwable) {
                    // try next alias
                }
            }
        }

        return $out;
    }

    /**
     * サイドバー用の標準5フォルダ（実在パス優先、なければ推奨パス）。
     *
     * @param  list<array{name?: string, path?: string, kind?: string, messages?: int}>  $listed
     * @return list<array{name: string, path: string, messages: int, kind: string}>
     */
    public function systemFolderMenu(MailAccount $account, array $listed): array
    {
        $labels = [
            'inbox' => 'INBOX',
            'sent' => 'Sent',
            'drafts' => 'Drafts',
            'spam' => 'Junk',
            'trash' => 'Trash',
        ];
        $menu = [];
        $isDomain = $this->isDomainMailbox($account);

        foreach (['inbox', 'sent', 'drafts', 'spam', 'trash'] as $kind) {
            if ($kind === 'inbox') {
                $menu[] = [
                    'name' => 'INBOX',
                    'path' => 'INBOX',
                    'messages' => (int) ($this->findListedPath($listed, 'INBOX')['messages'] ?? 0),
                    'kind' => 'inbox',
                ];
                continue;
            }

            if ($isDomain) {
                $path = $this->appSystemFolderPath($kind) ?? ($labels[$kind] ?? $kind);
                $hit = $this->findListedPath($listed, $path);
                $menu[] = [
                    'name' => $labels[$kind],
                    'path' => $path,
                    'messages' => (int) ($hit['messages'] ?? 0),
                    'kind' => $kind,
                ];
                continue;
            }

            $found = null;
            foreach ($listed as $row) {
                $rowKind = (string) ($row['kind'] ?? $this->folderKind((string) ($row['path'] ?? ''), (string) ($row['name'] ?? '')));
                if ($rowKind === $kind) {
                    $found = $row;
                    break;
                }
            }
            if ($found) {
                $menu[] = [
                    'name' => (string) ($found['name'] ?? $labels[$kind]),
                    'path' => (string) ($found['path'] ?? $labels[$kind]),
                    'messages' => (int) ($found['messages'] ?? 0),
                    'kind' => $kind,
                ];
                continue;
            }
            $path = $this->preferredSystemPaths($account, $kind)[0] ?? $labels[$kind];
            $menu[] = [
                'name' => $labels[$kind],
                'path' => $path,
                'messages' => 0,
                'kind' => $kind,
            ];
        }

        return $menu;
    }

    /**
     * @param  list<array{name?: string, path?: string, messages?: int}>  $listed
     * @return array{name?: string, path?: string, messages?: int}|null
     */
    private function findListedPath(array $listed, string $path): ?array
    {
        foreach ($listed as $row) {
            if (($row['path'] ?? '') === $path) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function preferredSystemPaths(MailAccount $account, string $kind): array
    {
        $isDomain = $this->isDomainMailbox($account);
        $isGmail = $this->isGmailAccount($account);
        $appPath = $this->appSystemFolderPath($kind);

        return match ($kind) {
            'inbox' => ['INBOX'],
            'sent' => $isDomain
                ? array_values(array_filter([$appPath, 'INBOX.Sent', 'Sent']))
                : ($isGmail
                    ? ['[Gmail]/Sent Mail', 'Sent Mail', 'Sent']
                    : ['Sent', 'INBOX.Sent', 'Sent Messages', '[Gmail]/Sent Mail']),
            'drafts' => $isDomain
                ? array_values(array_filter([$appPath, 'INBOX.Drafts', 'Drafts']))
                : ($isGmail
                    ? ['[Gmail]/Drafts', 'Drafts', 'Draft']
                    : ['Drafts', 'INBOX.Drafts', '[Gmail]/Drafts']),
            'spam' => $isDomain
                ? array_values(array_filter([$appPath, 'INBOX.Sa2.Junk', 'Sa2.Junk']))
                : ($isGmail
                    ? ['[Gmail]/Spam', 'Spam', 'Junk']
                    : ['Junk', 'Spam', 'INBOX.Junk', '[Gmail]/Spam']),
            'trash' => $isDomain
                ? array_values(array_filter([$appPath, 'INBOX.Trash', 'Trash']))
                : ($isGmail
                    ? ['[Gmail]/Trash', 'Trash']
                    : ['Trash', 'INBOX.Trash', '[Gmail]/Trash']),
            default => [$kind],
        };
    }

    /**
     * @return array{
     *   messages: list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>,
     *   exists: int
     * }
     */
    private function messagesWithClient($client, MailAccount $account, string $folderPath, int $page, int $perPage): array
    {
        $folder = $this->openFolder($client, $folderPath);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $exists = 0;

        try {
            $status = $folder->examine();
            $exists = (int) ($status['exists'] ?? 0);
            if ($exists === 0) {
                return ['messages' => [], 'exists' => 0];
            }
        } catch (\Throwable $e) {
            if (! $this->isEmptyImapResponse($e)) {
                Log::warning('mail.examine_failed', [
                    'account_id' => $account->id,
                    'folder' => $folderPath,
                    'error' => $e->getMessage(),
                ]);
            }
            // examine 失敗時は exists 不明のまま取得を試す
        }

        if ($exists > 0) {
            $rows = $this->messagesViaMsgn($folder, $page, $perPage, $exists);
            if ($rows !== []) {
                return ['messages' => $rows, 'exists' => max($exists, count($rows))];
            }
        }

        $rows = $this->messagesViaQuery($folder, $account, $page, $perPage);
        if ($rows !== []) {
            return ['messages' => $rows, 'exists' => max($exists, count($rows))];
        }

        if ($exists > 0) {
            $rows = $this->messagesViaOverview($folder, $page, $perPage, $exists);
            if ($rows !== []) {
                return ['messages' => $rows, 'exists' => max($exists, count($rows))];
            }

            Log::warning('mail.messages_empty_despite_exists', [
                'account_id' => $account->id,
                'folder' => $folderPath,
                'exists' => $exists,
            ]);
        }

        return ['messages' => [], 'exists' => $exists];
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>
     */
    private function messagesViaOverview(Folder $folder, int $page, int $perPage, int $exists): array
    {
        if ($exists < 1) {
            return [];
        }

        $end = $exists - (($page - 1) * $perPage);
        $start = max(1, $end - $perPage + 1);
        if ($end < 1) {
            return [];
        }

        try {
            $overview = $folder->overview($start.':'.$end);
        } catch (\Throwable $e) {
            Log::warning('mail.overview_failed', [
                'folder' => (string) $folder->path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($overview) || $overview === []) {
            return [];
        }

        $uidMap = $this->msgnToUidMap($folder);
        $rows = [];
        foreach (array_reverse($overview, true) as $msgn => $attrs) {
            if (! is_array($attrs)) {
                continue;
            }
            $msgn = (int) $msgn;
            $uid = (int) ($this->attrValue($attrs, 'uid') ?: ($uidMap[$msgn] ?? 0));
            if ($uid < 1) {
                $uid = $msgn;
            }
            $from = $this->attrValue($attrs, 'from') ?: $this->attrValue($attrs, 'fromaddress');
            $dateRaw = $this->attrValue($attrs, 'date');
            $flags = strtolower($this->attrValue($attrs, 'flags').' '.$this->attrValue($attrs, 'flag'));
            $subject = $this->attrValue($attrs, 'subject');
            $rows[] = [
                'uid' => $uid,
                'subject' => $subject !== '' ? $subject : __('(件名なし)'),
                'from' => $this->extractEmail($from),
                'date' => $this->normalizeDateString($dateRaw),
                'seen' => str_contains($flags, 'seen'),
                'flagged' => str_contains($flags, 'flagged'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, int> msgn => uid
     */
    private function msgnToUidMap(Folder $folder): array
    {
        try {
            $folder->getClient()->openFolder($folder->path);
            $data = $folder->getClient()->getConnection()->getUid()->data();
            if (! is_array($data)) {
                return [];
            }
            $map = [];
            foreach ($data as $msgn => $uid) {
                $map[(int) $msgn] = (int) $uid;
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>
     */
    private function messagesViaQuery(Folder $folder, MailAccount $account, int $page, int $perPage): array
    {
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
            Log::warning('mail.messages_query_failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $rows = [];
        foreach ($collection as $message) {
            /** @var Message $message */
            $rows[] = $this->messageToListRow($message);
        }

        return $rows;
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>
     */
    private function messagesViaMsgn(Folder $folder, int $page, int $perPage, int $exists): array
    {
        if ($exists < 1) {
            return [];
        }

        $end = $exists - (($page - 1) * $perPage);
        $start = max(1, $end - $perPage + 1);
        if ($end < 1) {
            return [];
        }

        $rows = [];
        for ($msgn = $end; $msgn >= $start; $msgn--) {
            try {
                $message = $folder->messages()
                    ->setFetchBody(false)
                    ->setFetchFlags(true)
                    ->leaveUnread()
                    ->getMessageByMsgn($msgn);
                if ($message) {
                    $row = $this->messageToListRow($message);
                    if ($row['uid'] > 0) {
                        $rows[] = $row;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('mail.message_msgn_failed', [
                    'msgn' => $msgn,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $rows;
    }

    /**
     * @return array{uid: int, subject: string, from: string, date: ?string, seen: bool, flagged: bool}
     */
    private function messageToListRow(Message $message): array
    {
        $fromText = '';
        $subject = __('(件名なし)');
        $date = null;
        $seen = false;
        $flagged = false;
        $uid = 0;

        try {
            $uid = (int) $message->getUid();
        } catch (\Throwable) {
        }

        try {
            $fromText = $this->firstAddressEmail($message->getFrom());
        } catch (\Throwable) {
        }

        try {
            $rawSubject = $message->getSubject();
            $subjectText = is_object($rawSubject) && method_exists($rawSubject, '__toString')
                ? trim((string) $rawSubject)
                : trim((string) $rawSubject);
            if ($subjectText !== '') {
                $subject = $subjectText;
            }
        } catch (\Throwable) {
        }

        try {
            $dateObj = $message->getDate();
            if ($dateObj) {
                $date = $dateObj->toDate()->format('c');
            }
        } catch (\Throwable) {
        }

        try {
            $flags = $message->getFlags();
            $seen = (bool) ($flags?->get('seen') ?? false);
            $flagged = (bool) ($flags?->get('flagged') ?? false);
        } catch (\Throwable) {
        }

        return [
            'uid' => $uid,
            'subject' => $subject,
            'from' => $fromText,
            'date' => $date,
            'seen' => $seen,
            'flagged' => $flagged,
        ];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function attrValue(array $attrs, string $key): string
    {
        if (! array_key_exists($key, $attrs)) {
            return '';
        }
        $value = $attrs[$key];
        if (is_object($value) && method_exists($value, '__toString')) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            $first = $value[0] ?? null;
            if (is_object($first) && isset($first->mail)) {
                return trim((string) $first->mail);
            }
            if (is_scalar($first)) {
                return trim((string) $first);
            }

            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function extractEmail(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            return trim($m[1]);
        }

        return $raw;
    }

    private function normalizeDateString(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($raw)->format('c');
        } catch (\Throwable) {
            return $raw;
        }
    }

    /**
     * @return array{uid: int, subject: string, from: string, to: string, date: ?string, bodyHtml: string, bodyText: string}
     */
    private function messageWithClient($client, string $folderPath, int $uid): array
    {
        $folder = $this->openFolder($client, $folderPath);
        $message = null;
        $lastError = null;

        foreach ([
            fn () => $folder->messages()->setFetchBody(true)->setFetchFlags(true)->leaveUnread()->getMessageByUid($uid),
            fn () => $folder->messages()->setFetchBody(true)->setFetchFlags(true)->leaveUnread()->getMessageByMsgn($uid),
        ] as $loader) {
            try {
                $message = $loader();
                if ($message) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if (! $message) {
            if ($lastError && $this->isEmptyImapResponse($lastError)) {
                throw new \InvalidArgumentException(__('メールが見つかりません。'));
            }
            if ($lastError) {
                throw new \RuntimeException($lastError->getMessage() !== '' ? $lastError->getMessage() : __('メールの読み込みに失敗しました。'), 0, $lastError);
            }
            throw new \InvalidArgumentException(__('メールが見つかりません。'));
        }

        // 一覧取得時に本文なしで作られたインスタンス向けに再取得を試みる
        try {
            if (method_exists($message, 'parseBody') && $message->getHTMLBody() === '' && $message->getTextBody() === '') {
                $message->setFetchBodyOption(true);
                $message->parseBody();
            }
        } catch (\Throwable) {
            // ヘッダのみでも返す
        }

        $bodies = $this->extractMessageBodies($message);
        $from = null;
        $to = null;
        $date = null;
        try {
            $from = $message->getFrom();
        } catch (\Throwable) {
        }
        try {
            $to = $message->getTo();
        } catch (\Throwable) {
        }
        try {
            $date = $message->getDate();
        } catch (\Throwable) {
        }

        $subject = __('(件名なし)');
        try {
            $rawSubject = $message->getSubject();
            $subjectText = trim((string) $rawSubject);
            if ($subjectText !== '') {
                $subject = $subjectText;
            }
        } catch (\Throwable) {
        }

        return [
            'uid' => (int) $message->getUid(),
            'subject' => $subject,
            'from' => $this->firstAddressEmail($from),
            'to' => $this->firstAddressEmail($to),
            'date' => $date ? $date->toDate()->format('c') : null,
            'bodyHtml' => $bodies['html'],
            'bodyText' => $bodies['text'],
        ];
    }

    private function firstAddressEmail(mixed $addresses): string
    {
        if ($addresses === null || $addresses === false || $addresses === '') {
            return '';
        }

        $first = null;
        if (is_object($addresses) && method_exists($addresses, 'first')) {
            try {
                $first = $addresses->first();
            } catch (\Throwable) {
                $first = null;
            }
        } elseif (is_array($addresses)) {
            $first = $addresses[0] ?? null;
        } elseif ($addresses instanceof \Countable && $addresses instanceof \ArrayAccess) {
            try {
                if (count($addresses) > 0) {
                    $first = $addresses[0] ?? null;
                }
            } catch (\Throwable) {
                $first = null;
            }
        } else {
            $first = $addresses;
        }

        if ($first === null || $first === false || $first === '') {
            return '';
        }

        if (is_object($first)) {
            $mail = trim((string) ($first->mail ?? $first->mailbox ?? ''));
            if ($mail !== '') {
                return $mail;
            }
            if (isset($first->personal)) {
                return trim((string) $first->personal);
            }
            if (method_exists($first, '__toString')) {
                return $this->extractEmail(trim((string) $first));
            }

            return '';
        }

        return is_scalar($first) ? $this->extractEmail(trim((string) $first)) : '';
    }

    /**
     * @return array{html: string, text: string}
     */
    private function extractMessageBodies(Message $message): array
    {
        $html = '';
        $text = '';
        try {
            $html = (string) ($message->getHTMLBody() ?: '');
        } catch (\Throwable) {
        }
        try {
            $text = (string) ($message->getTextBody() ?: '');
        } catch (\Throwable) {
        }

        if ($html === '' && $text === '') {
            try {
                $bodies = $message->getBodies();
                if (is_array($bodies)) {
                    $html = (string) ($bodies['html'] ?? '');
                    $text = (string) ($bodies['text'] ?? '');
                }
            } catch (\Throwable) {
            }
        }

        if ($html === '' && $text === '') {
            try {
                $raw = trim((string) $message->getRawBody());
                if ($raw !== '') {
                    // ヘッダ付き raw の場合は本文っぽい部分だけ拾う
                    if (preg_match('/\r?\n\r?\n(.*)$/s', $raw, $m)) {
                        $raw = trim($m[1]);
                    }
                    $text = $raw;
                }
            } catch (\Throwable) {
            }
        }

        if ($html === '' && $text !== '') {
            $html = nl2br(e($text));
        }

        // 送信者が用意した HTML をそのまま画面に入れないよう、ここで必ず無害化する
        $html = $this->htmlSanitizer()->sanitize($html);

        if (trim($html) === '') {
            $html = '<p class="hint">'.e(__('(本文なし)')).'</p>';
        }

        return ['html' => $html, 'text' => $text];
    }

    private function htmlSanitizer(): MailHtmlSanitizer
    {
        return $this->htmlSanitizer ??= new MailHtmlSanitizer;
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
                'fetch_body' => true,
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
            'timeout' => 30,
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
                    'timeout' => 30,
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
                ? __('Gmailの受信（IMAP）がタイムアウトしました。共有サーバでは外向き IMAP が制限されることがあります。送信（SMTP）だけ通る場合も同様です。受信は @:domain の利用を推奨します。', ['domain' => config('mail_domain.domain')])
                : __('メールサーバへの接続がタイムアウトしました。');
        }

        if (str_contains($lower, 'connection refused')
            || str_contains($lower, 'network is unreachable')
            || str_contains($lower, 'failed to connect')
            || str_contains($lower, 'could not connect')) {
            return $isGmail
                ? __('Gmail IMAP に接続できません。共有サーバの外向き通信制限の可能性が高く、送信（SMTP）は成功しても受信だけ失敗することがあります。受信は @:domain をご利用ください。', ['domain' => config('mail_domain.domain')])
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
                return __('@:domain の認証に失敗しました。メールアドレス全体をユーザー名にし、メールボックス作成時のパスワードを確認してください。', ['domain' => config('mail_domain.domain')]);
            }

            return __('メール認証に失敗しました。メールアドレス・パスワード・IMAP/SMTP設定を確認してください。');
        }

        return $raw !== '' ? $raw : __('メールサーバへの接続に失敗しました。');
    }

    private function openFolder($client, string $folderPath): Folder
    {
        $path = $folderPath !== '' ? $folderPath : 'INBOX';
        $candidates = array_values(array_unique(array_filter([
            $path,
            $this->encodeImapMailboxPath($path),
            $this->decodeImapMailboxPath($path),
            ...$this->folderAliases($this->folderKind($path, $path), $path),
        ])));

        $last = null;
        foreach ($candidates as $candidate) {
            try {
                return $client->getFolderByPath($candidate);
            } catch (\Throwable $e) {
                $last = $e;
                try {
                    $folder = $client->getFolder($candidate);
                    if ($folder) {
                        return $folder;
                    }
                } catch (\Throwable $e2) {
                    $last = $e2;
                }
            }
        }

        throw new \RuntimeException(
            __('フォルダ「:folder」を開けませんでした。', ['folder' => $this->decodeImapMailboxPath($path)]),
            0,
            $last
        );
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
