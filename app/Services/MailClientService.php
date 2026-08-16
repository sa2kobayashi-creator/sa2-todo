<?php

namespace App\Services;

use App\Models\MailAccount;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class MailClientService
{
    public function ping(MailAccount $account): void
    {
        $client = $this->connect($account);
        try {
            $client->getFolders(false);
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
            $out = [];
            foreach ($client->getFolders(false) as $folder) {
                /** @var Folder $folder */
                $status = $folder->examine();
                $out[] = [
                    'name' => (string) $folder->name,
                    'path' => (string) $folder->path,
                    'messages' => (int) ($status['exists'] ?? 0),
                ];
            }

            usort($out, function (array $a, array $b) {
                $priority = ['INBOX' => 0, 'Sent' => 1, 'Drafts' => 2, 'Trash' => 3];
                $pa = $priority[$a['name']] ?? 50;
                $pb = $priority[$b['name']] ?? 50;
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }

                return strcasecmp($a['name'], $b['name']);
            });

            return $out;
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
            $folder = $this->openFolder($client, $folderPath);
            $page = max(1, $page);
            $perPage = max(1, min(50, $perPage));

            $query = $folder->messages()->all()->setFetchBody(false)->leaveUnread()->limit($perPage, $page);
            $collection = $query->get();

            $rows = [];
            foreach ($collection as $message) {
                /** @var Message $message */
                $from = $message->getFrom();
                $fromText = $from && count($from) > 0 ? (string) $from[0]->mail : '';
                $date = $message->getDate();
                $rows[] = [
                    'uid' => (int) $message->getUid(),
                    'subject' => (string) ($message->getSubject() ?: __('(件名なし)')),
                    'from' => $fromText,
                    'date' => $date ? $date->toDate()->format('c') : null,
                    'seen' => (bool) $message->getFlags()->get('seen'),
                ];
            }

            return $rows;
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
            $folder = $this->openFolder($client, $folderPath);
            $message = $folder->messages()->getMessageByUid($uid);
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

    private function connect(MailAccount $account)
    {
        if (! class_exists(ClientManager::class)) {
            throw new \RuntimeException(
                __('メール用ライブラリ（webklex/php-imap）が未インストールです。サーバで composer install を実行してください。')
            );
        }

        $creds = $this->credentials($account);
        $cm = new ClientManager([]);
        $encryption = $account->imap_encryption === 'none' ? false : $account->imap_encryption;

        $client = $cm->make([
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $encryption,
            'validate_cert' => true,
            'username' => $creds['username'],
            'password' => $creds['password'],
            'protocol' => 'imap',
            'authentication' => null,
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->friendlyConnectError($account, $e), 0, $e);
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

        if ($account->provider === 'gmail'
            || str_ends_with($email, '@gmail.com')
            || str_ends_with($email, '@googlemail.com')) {
            $username = $email;
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    private function friendlyConnectError(MailAccount $account, \Throwable $e): string
    {
        $raw = $e->getMessage();
        $lower = strtolower($raw);
        $isGmail = $account->provider === 'gmail'
            || str_ends_with(strtolower($account->email), '@gmail.com')
            || str_ends_with(strtolower($account->email), '@googlemail.com');

        if (str_contains($lower, 'authenticationfailed')
            || str_contains($lower, 'invalid credentials')
            || str_contains($lower, 'badcredentials')
            || str_contains($lower, 'username and password not accepted')
            || str_contains($lower, '535')
            || str_contains($lower, 'auth')) {
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
        $email = strtolower(trim((string) $account->email));
        $isGmail = $account->provider === 'gmail'
            || str_ends_with($email, '@gmail.com')
            || str_ends_with($email, '@googlemail.com');

        // Gmail は 587/STARTTLS + LOGIN 固定（XOAUTH2 を使わない）
        if ($isGmail) {
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
