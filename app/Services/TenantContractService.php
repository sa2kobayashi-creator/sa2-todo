<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TenantContractService
{
    public function __construct(private TenantContext $tenants) {}

    /**
     * @param  array{
     *   name: string,
     *   notes?: ?string,
     *   max_users?: int,
     *   allow_own_keys?: bool,
     *   owner_email: string,
     *   owner_display_name: string,
     *   owner_password: string
     * }  $input
     */
    public function createWithOwner(array $input): Tenant
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(__('契約名を入力してください。'));
        }

        $email = strtolower(trim((string) ($input['owner_email'] ?? '')));
        $displayName = trim((string) ($input['owner_display_name'] ?? ''));
        $password = (string) ($input['owner_password'] ?? '');
        if ($email === '' || $displayName === '' || strlen($password) < 8) {
            throw new InvalidArgumentException(__('契約代表のメール・表示名・8文字以上のパスワードを入力してください。'));
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException(__('そのメールアドレスはすでに使われています。'));
        }

        $maxUsers = max(1, (int) ($input['max_users'] ?? Tenant::defaultMaxUsers()));

        return DB::transaction(function () use ($input, $name, $email, $displayName, $password, $maxUsers) {
            $tenant = Tenant::create([
                'name' => mb_substr($name, 0, 120),
                'slug' => $this->uniqueSlug($name),
                'status' => Tenant::STATUS_ACTIVE,
                'notes' => $this->nullableNote($input['notes'] ?? null),
                'max_users' => $maxUsers,
                'allow_own_keys' => (bool) ($input['allow_own_keys'] ?? true),
            ]);

            $owner = User::create([
                'email' => $email,
                'display_name' => mb_substr($displayName, 0, 100),
                'password' => Hash::make($password),
                'role' => UserRole::Admin,
                'tenant_id' => $tenant->id,
            ]);

            $tenant->owner_user_id = $owner->id;
            $tenant->save();

            return $tenant->load('owner');
        });
    }

    /**
     * @param  array{
     *   name?: string,
     *   notes?: ?string,
     *   max_users?: int,
     *   allow_own_keys?: bool,
     *   status?: string
     * }  $input
     */
    public function update(Tenant $tenant, array $input): Tenant
    {
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new InvalidArgumentException(__('契約名を入力してください。'));
            }
            $tenant->name = mb_substr($name, 0, 120);
        }
        if (array_key_exists('notes', $input)) {
            $tenant->notes = $this->nullableNote($input['notes']);
        }
        if (array_key_exists('max_users', $input)) {
            $max = max(1, (int) $input['max_users']);
            if ($max < $tenant->userCount()) {
                throw new InvalidArgumentException(__('上限は現在のユーザー数より小さくできません。'));
            }
            $tenant->max_users = $max;
        }
        if (array_key_exists('allow_own_keys', $input)) {
            $tenant->allow_own_keys = (bool) $input['allow_own_keys'];
        }
        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (! in_array($status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
                throw new InvalidArgumentException(__('契約状態が不正です。'));
            }
            $tenant->status = $status;
        }
        $tenant->save();

        return $tenant->fresh(['owner']) ?? $tenant;
    }

    public function assertCanAddUser(Tenant $tenant): void
    {
        if ($tenant->isSuspended()) {
            throw new InvalidArgumentException(__('停止中の契約にはユーザーを追加できません。'));
        }
        if (! $tenant->hasUserCapacity(1)) {
            throw new InvalidArgumentException(__('この契約のユーザー上限（:count人）に達しています。', [
                'count' => $tenant->max_users,
            ]));
        }
    }

    /** @return list<int> */
    public function allTenantIds(): array
    {
        return Tenant::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function applyRuntimeForUser(?User $user): void
    {
        $this->tenants->fromUser($user);
        try {
            app(MediaStorageConfigService::class)->flush();
            app(MediaStorageConfigService::class)->applyRuntimeDisks();
        } catch (\Throwable) {
            // マイグレーション前など
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'tenant';
        }
        $base = mb_substr($base, 0, 60);
        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Tenant::query()->where('slug', $slug)->exists());

        return $slug;
    }

    private function nullableNote(mixed $notes): ?string
    {
        $text = trim((string) $notes);

        return $text === '' ? null : mb_substr($text, 0, 2000);
    }
}
