<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DataArchive;
use App\Models\MailAccount;
use App\Models\MailArchive;
use App\Models\Note;
use App\Models\NoteAttachment;
use App\Models\Todo;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\DatabaseRecordArchiveService;
use App\Services\MailClientService;
use App\Services\MailColdArchiveService;
use App\Services\MediaStorageConfigService;
use App\Services\StorageManagementService;
use App\Services\StorageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backblaze');
        config([
            'storage_management.disk' => 'backblaze',
            'storage_management.backup_driver' => 'php',
            'storage_management.db_min_age_days' => 365,
            'storage_management.mail_archive_bytes' => 800,
            'storage_management.mail_target_bytes' => 100,
            'storage_management.mail_protect_days' => 90,
        ]);
        $media = \Mockery::mock(MediaStorageConfigService::class)->makePartial();
        $media->shouldReceive('backblazeEnabled')->andReturnTrue();
        $media->shouldReceive('applyRuntimeDisks')->andReturnNull();
        $this->app->instance(MediaStorageConfigService::class, $media);
    }

    private function user(string $email, UserRole $role = UserRole::Standard): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Store',
            'password' => Hash::make('password123'),
            'role' => $role,
        ]);
    }

    public function test_old_completed_todos_are_moved_to_b2_and_can_be_restored(): void
    {
        $user = $this->user('archive-todo@example.com');
        $todo = Todo::create([
            'user_id' => $user->id,
            'title' => '古い完了タスク',
            'memo' => '残しておきたいメモ',
            'completed' => true,
            'keep_on_server' => false,
        ]);
        $todo->forceFill([
            'created_at' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ])->save();

        $service = app(DatabaseRecordArchiveService::class);
        $result = $service->archiveDueRecords(true);

        $this->assertSame(1, $result['archived']);
        $this->assertNull(Todo::query()->find($todo->id));
        $archive = DataArchive::query()->where('source_table', 'todos')->where('source_id', $todo->id)->first();
        $this->assertNotNull($archive);
        Storage::disk('backblaze')->assertExists($archive->storage_key);

        $this->actingAs($user)->get('/archives?q=完了')->assertOk()->assertSee('古い完了タスク', false);
        $this->actingAs($user)->post('/archives/'.$archive->id.'/restore')->assertRedirect('/archives');
        $this->assertNotNull(Todo::query()->where('user_id', $user->id)->where('title', '古い完了タスク')->first());
        $this->assertNull(DataArchive::query()->find($archive->id));
    }

    public function test_keep_on_server_todos_are_not_archived(): void
    {
        $user = $this->user('keep@example.com');
        $todo = Todo::create([
            'user_id' => $user->id,
            'title' => '残す',
            'completed' => true,
            'keep_on_server' => true,
        ]);
        $todo->forceFill(['updated_at' => now()->subYears(2)])->save();

        $result = app(DatabaseRecordArchiveService::class)->archiveDueRecords(true);
        $this->assertSame(0, $result['archived']);
        $this->assertNotNull(Todo::query()->find($todo->id));
    }

    public function test_mail_is_archived_only_after_b2_put_and_then_deleted(): void
    {
        $user = $this->user('mail-arc@example.com');
        $account = MailAccount::create([
            'user_id' => $user->id,
            'label' => 'sa2',
            'email' => 'box@sa2-plus.com',
            'provider' => 'lolipop',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => 'box@sa2-plus.com',
            'password' => 'secret',
            'is_sa2_plus_mailbox' => true,
        ]);

        $imap = \Mockery::mock(MailClientService::class);
        $imap->shouldReceive('mailboxUsedBytes')->andReturn(900);
        $imap->shouldReceive('oldestMessages')->andReturn([[
            'uid' => 11,
            'subject' => '古い手紙',
            'from' => 'a@example.com',
            'to' => 'box@sa2-plus.com',
            'date' => now()->subYear(),
            'size' => 400,
            'flagged' => false,
        ]]);
        $imap->shouldReceive('exportRawMessages')->once()->andReturn([11 => "From: a@example.com\r\nSubject: 古い手紙\r\n\r\nhello"]);
        $imap->shouldReceive('expungeMessages')->once()->with(\Mockery::any(), 'INBOX', [11])->andReturn([11]);
        $this->app->instance(MailClientService::class, $imap);

        $result = app(MailColdArchiveService::class)->archiveOverQuotaBoxes(900);
        $this->assertSame(1, $result['archived']);
        $row = MailArchive::query()->where('mail_account_id', $account->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('古い手紙', $row->subject);
        Storage::disk('backblaze')->assertExists($row->storage_key);

        $this->actingAs($user)
            ->get('/mail/accounts/'.$account->id.'/mailbox?folder=SA2.B2')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('folder', 'SA2.B2')
            ->assertJsonPath('messages.0.subject', '古い手紙');
    }

    public function test_mail_left_on_server_after_failed_delete_is_not_archived_twice(): void
    {
        $user = $this->user('mail-retry@example.com');
        $account = MailAccount::create([
            'user_id' => $user->id,
            'label' => 'sa2',
            'email' => 'retry@sa2-plus.com',
            'provider' => 'lolipop',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => 'retry@sa2-plus.com',
            'password' => 'secret',
            'is_sa2_plus_mailbox' => true,
        ]);

        Storage::disk('backblaze')->put('archives/mail/x.eml', 'raw');
        MailArchive::create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'folder_path' => 'INBOX',
            'imap_uid' => 21,
            'subject' => '前回退避済み',
            'storage_provider' => 'b2',
            'storage_key' => 'archives/mail/x.eml',
            'archived_at' => now(),
        ]);

        $imap = \Mockery::mock(MailClientService::class);
        $imap->shouldReceive('oldestMessages')->andReturn([[
            'uid' => 21,
            'subject' => '前回退避済み',
            'from' => 'a@example.com',
            'to' => 'retry@sa2-plus.com',
            'date' => now()->subYear(),
            'size' => 400,
            'flagged' => false,
        ]]);
        $imap->shouldNotReceive('exportRawMessages');
        $imap->shouldReceive('expungeMessages')->once()->with(\Mockery::any(), 'INBOX', [21])->andReturn([21]);
        $this->app->instance(MailClientService::class, $imap);

        app(MailColdArchiveService::class)->archiveOverQuotaBoxes(900);

        $this->assertSame(1, MailArchive::query()->where('mail_account_id', $account->id)->count());
    }

    public function test_mail_is_not_deleted_when_the_b2_object_is_missing(): void
    {
        $user = $this->user('mail-missing@example.com');
        $account = MailAccount::create([
            'user_id' => $user->id,
            'label' => 'sa2',
            'email' => 'missing@sa2-plus.com',
            'provider' => 'lolipop',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => 'missing@sa2-plus.com',
            'password' => 'secret',
            'is_sa2_plus_mailbox' => true,
        ]);

        MailArchive::create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'folder_path' => 'INBOX',
            'imap_uid' => 41,
            'subject' => '実体が無い',
            'storage_provider' => 'b2',
            'storage_key' => 'archives/mail/gone.eml',
            'archived_at' => now(),
        ]);

        $imap = \Mockery::mock(MailClientService::class);
        $imap->shouldReceive('oldestMessages')->andReturn([[
            'uid' => 41,
            'subject' => '実体が無い',
            'from' => 'a@example.com',
            'to' => 'missing@sa2-plus.com',
            'date' => now()->subYear(),
            'size' => 400,
            'flagged' => false,
        ]]);
        $imap->shouldNotReceive('exportRawMessages');
        $imap->shouldNotReceive('expungeMessages');
        $this->app->instance(MailClientService::class, $imap);

        app(MailColdArchiveService::class)->archiveOverQuotaBoxes(900);
    }

    public function test_recent_mail_without_a_date_is_protected(): void
    {
        $user = $this->user('mail-nodate@example.com');
        MailAccount::create([
            'user_id' => $user->id,
            'label' => 'sa2',
            'email' => 'nodate@sa2-plus.com',
            'provider' => 'lolipop',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => 'nodate@sa2-plus.com',
            'password' => 'secret',
            'is_sa2_plus_mailbox' => true,
        ]);

        $imap = \Mockery::mock(MailClientService::class);
        $imap->shouldReceive('oldestMessages')->andReturn([[
            'uid' => 31,
            'subject' => '日付不明',
            'from' => 'a@example.com',
            'to' => 'nodate@sa2-plus.com',
            'date' => null,
            'size' => 400,
            'flagged' => false,
        ]]);
        $imap->shouldNotReceive('exportRawMessages');
        $imap->shouldNotReceive('expungeMessages');
        $this->app->instance(MailClientService::class, $imap);

        $result = app(MailColdArchiveService::class)->archiveOverQuotaBoxes(900);

        $this->assertSame(0, $result['archived']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, MailArchive::query()->count());
    }

    public function test_note_attachments_survive_archive_and_restore(): void
    {
        $user = $this->user('note-attach@example.com');
        $note = Note::create([
            'user_id' => $user->id,
            'title' => '古いメモ',
            'body' => '本文',
            'completed' => true,
            'keep_on_server' => false,
        ]);
        $note->forceFill(['updated_at' => now()->subYears(2)])->save();
        NoteAttachment::create([
            'note_id' => $note->id,
            'user_id' => $user->id,
            'disk' => 'public',
            'path' => 'notes/1/file.pdf',
            'original_name' => '資料.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1234,
        ]);

        $archives = app(DatabaseRecordArchiveService::class);
        $this->assertSame(1, $archives->archiveDueRecords(true)['archived']);
        $this->assertSame(0, NoteAttachment::query()->count());

        $archive = DataArchive::query()->where('source_table', 'notes')->firstOrFail();
        $restored = $archives->restore($user->id, $archive->id);

        $attachment = NoteAttachment::query()->where('note_id', $restored->id)->first();
        $this->assertNotNull($attachment);
        $this->assertSame('notes/1/file.pdf', $attachment->path);
        $this->assertSame('資料.pdf', $attachment->original_name);
    }

    public function test_restore_keeps_original_created_at(): void
    {
        $user = $this->user('keep-time@example.com');
        $createdAt = now()->subYears(3)->startOfDay();
        $todo = Todo::create([
            'user_id' => $user->id,
            'title' => '昔のタスク',
            'completed' => true,
            'keep_on_server' => false,
        ]);
        $todo->forceFill(['created_at' => $createdAt, 'updated_at' => now()->subYears(2)])->save();

        $archives = app(DatabaseRecordArchiveService::class);
        $archives->archiveDueRecords(true);
        $archive = DataArchive::query()->where('source_table', 'todos')->firstOrFail();
        $restored = $archives->restore($user->id, $archive->id);

        $this->assertSame($createdAt->toDateTimeString(), $restored->created_at->toDateTimeString());
    }

    public function test_group_shared_todos_are_never_archived(): void
    {
        $user = $this->user('group-owner@example.com');
        $group = \App\Models\Group::create(['name' => 'チーム', 'owner_user_id' => $user->id]);
        $todo = Todo::create([
            'user_id' => $user->id,
            'group_id' => $group->id,
            'title' => '共有タスク',
            'completed' => true,
            'keep_on_server' => false,
        ]);
        $todo->forceFill(['updated_at' => now()->subYears(2)])->save();

        $this->assertSame(0, app(DatabaseRecordArchiveService::class)->archiveDueRecords(true)['archived']);
        $this->assertNotNull(Todo::query()->find($todo->id));
    }

    public function test_user_can_mark_a_candidate_to_stay_on_server(): void
    {
        $user = $this->user('candidate@example.com');
        $todo = Todo::create([
            'user_id' => $user->id,
            'title' => '残したいタスク',
            'completed' => true,
            'keep_on_server' => false,
        ]);
        $todo->forceFill(['updated_at' => now()->subYears(2)])->save();

        $this->actingAs($user)->get('/archives')->assertOk()->assertSee('残したいタスク', false);
        $this->actingAs($user)
            ->post('/archives/keep', ['type' => 'todos', 'id' => $todo->id, 'keep' => 1])
            ->assertRedirect();

        $this->assertTrue((bool) $todo->fresh()->keep_on_server);
        $this->assertSame(0, app(DatabaseRecordArchiveService::class)->archiveDueRecords(true)['archived']);
    }

    public function test_keep_endpoint_rejects_other_users_records(): void
    {
        $owner = $this->user('owner-keep@example.com');
        $other = $this->user('other-keep@example.com');
        $todo = Todo::create([
            'user_id' => $owner->id,
            'title' => '他人のタスク',
            'completed' => true,
            'keep_on_server' => false,
        ]);

        $this->actingAs($other)
            ->post('/archives/keep', ['type' => 'todos', 'id' => $todo->id, 'keep' => 1])
            ->assertRedirect();

        $this->assertFalse((bool) $todo->fresh()->keep_on_server);
    }

    public function test_database_backup_writes_gzip_to_b2(): void
    {
        $result = app(DatabaseBackupService::class)->run();
        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['key']);
        Storage::disk('backblaze')->assertExists($result['key']);

        // スキーマが無いダンプは復元に使えないので、CREATE TABLE が入っていることを確かめる。
        $sql = gzdecode(Storage::disk('backblaze')->get($result['key']));
        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
    }

    public function test_php_dump_maps_numeric_and_uppercase_row_keys(): void
    {
        $service = app(DatabaseBackupService::class);
        $toAssoc = new \ReflectionMethod(DatabaseBackupService::class, 'rowToAssociative');
        $toAssoc->setAccessible(true);
        $value = new \ReflectionMethod(DatabaseBackupService::class, 'valueIgnoringCase');
        $value->setAccessible(true);

        $fromNumeric = $toAssoc->invoke($service, [11, 'hello'], ['id', 'title']);
        $this->assertSame(['id' => 11, 'title' => 'hello'], $fromNumeric);

        $fromUpper = $toAssoc->invoke($service, (object) ['ID' => 9, 'TITLE' => 'kept'], ['id', 'title']);
        $this->assertSame(['ID' => 9, 'TITLE' => 'kept'], $fromUpper);
        $this->assertSame(9, $value->invoke($service, $fromUpper, 'id'));
    }

    public function test_php_dump_includes_tables_that_have_no_id_column(): void
    {
        \Illuminate\Support\Facades\DB::table('cache')->insert([
            'key' => 'backup-probe',
            'value' => 'ok',
            'expiration' => time() + 60,
        ]);

        $result = app(DatabaseBackupService::class)->run();
        $this->assertTrue($result['ok']);
        $sql = gzdecode(Storage::disk('backblaze')->get($result['key']));
        $this->assertIsString($sql);
        $this->assertStringContainsString('backup-probe', $sql);
    }

    public function test_storage_manage_records_a_log(): void
    {
        $result = app(StorageManagementService::class)->run();
        $this->assertArrayHasKey('log_id', $result);
        $this->assertDatabaseHas('storage_management_logs', ['id' => $result['log_id']]);
    }

    public function test_admin_and_super_admin_can_open_storage_monitor(): void
    {
        $super = $this->user('root@example.com', UserRole::SuperAdmin);
        $admin = $this->user('ops-admin@example.com', UserRole::Admin);
        $this->actingAs($super)->get('/admin/storage-archive')->assertOk()->assertSee('ストレージ監視', false);
        $this->actingAs($admin)->get('/admin/storage-archive')->assertOk()->assertSee('ストレージ監視', false);
        $member = $this->user('member@example.com');
        $this->actingAs($member)->get('/admin/storage-archive')->assertForbidden();
    }

    public function test_usage_probe_returns_levels(): void
    {
        $snap = app(StorageUsageService::class)->snapshot();
        $this->assertArrayHasKey('r2_status', $snap);
        $this->assertArrayHasKey('db_bytes', $snap);
        $this->assertContains($snap['db_status'], ['ok', 'warn', 'archive']);
    }
}
