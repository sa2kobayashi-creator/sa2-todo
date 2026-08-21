<?php

namespace App\Models;

use App\Enums\GroupStatus;
use App\Enums\MenuFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'email',
        'display_name',
        'password',
        'role',
        'menu_features',
        'footer_nav',
        'header_nav',
        'app_context',
        'must_change_password',
        'reset_token',
        'reset_token_expires_at',
        'reset_attempts',
        'reset_last_sent_at',
        'pending_email',
        'pending_email_token',
        'pending_email_expires_at',
        'pending_email_attempts',
        'pending_email_sent_at',
        'last_seen_at',
        'timezone',
        'subscription_status',
        'trial_ends_at',
        'storage_overage_active',
        'mailbox_addon_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'reset_token',
        'pending_email_token',
    ];

    protected function casts(): array
    {
        return [
            'reset_token_expires_at' => 'datetime',
            'reset_last_sent_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'pending_email_sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'must_change_password' => 'boolean',
            'role' => UserRole::class,
            'subscription_status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'storage_overage_active' => 'boolean',
            'mailbox_addon_active' => 'boolean',
            'menu_features' => 'array',
            'footer_nav' => 'array',
            'header_nav' => 'array',
        ];
    }

    public function isOnline(int $withinSeconds = 120): bool
    {
        if (! $this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gt(now()->subSeconds(max(30, $withinSeconds)));
    }

    public function googleCalendarConnection()
    {
        return $this->hasOne(GoogleCalendarConnection::class);
    }

    public function messagingConnections()
    {
        return $this->hasMany(MessagingConnection::class);
    }

    public function roleEnum(): UserRole
    {
        return $this->role instanceof UserRole
            ? $this->role
            : UserRole::tryFrom((string) $this->role) ?? UserRole::Standard;
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleEnum() === UserRole::SuperAdmin;
    }

    /** スーパー管理者または管理者（設定・ユーザー管理など） */
    public function isAdmin(): bool
    {
        return $this->roleEnum()->isStaff();
    }

    public function canAccess(string $feature): bool
    {
        if ($this->isAdmin()) {
            return $this->roleEnum()->canAccess($feature);
        }

        if (MenuFeature::tryFrom($feature) !== null) {
            return in_array($feature, $this->effectiveMenuFeatures(), true);
        }

        return $this->roleEnum()->canAccess($feature);
    }

    /** @return list<string> */
    public function baseMenuFeatures(): array
    {
        if ($this->isAdmin()) {
            return MenuFeature::values();
        }

        if (is_array($this->menu_features)) {
            return array_values(array_intersect($this->menu_features, MenuFeature::values()));
        }

        return MenuFeature::defaultsForRole($this->roleEnum());
    }

    /** @return list<string> */
    public function groupMenuFeatures(): array
    {
        if ($this->isAdmin()) {
            return [];
        }

        return DB::table('group_menu_features')
            ->join('group_members', 'group_members.group_id', '=', 'group_menu_features.group_id')
            ->join('groups', 'groups.id', '=', 'group_menu_features.group_id')
            ->where('group_members.user_id', $this->id)
            ->where('groups.status', GroupStatus::Approved->value)
            ->whereIn('group_menu_features.feature', MenuFeature::values())
            ->distinct()
            ->pluck('group_menu_features.feature')
            ->map(fn ($feature) => (string) $feature)
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function effectiveMenuFeatures(): array
    {
        if ($this->isAdmin()) {
            return MenuFeature::values();
        }

        // 管理画面で利用メニューを明示設定した場合（空配列＝追加メニューなし）はそれを優先。
        // グループ付与で制限を上書きしない。
        if (is_array($this->menu_features)) {
            return $this->baseMenuFeatures();
        }

        return array_values(array_unique([
            ...$this->baseMenuFeatures(),
            ...$this->groupMenuFeatures(),
        ]));
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        $role = $this->roleEnum();

        return [
            'id' => $this->id,
            'email' => $this->email,
            'pendingEmail' => $this->pending_email,
            'displayName' => $this->display_name,
            'role' => $role->value,
            'roleLabel' => __($role->label()),
            'roleDescription' => __($role->description()),
            'menuFeatures' => $this->baseMenuFeatures(),
            'hasCustomMenuFeatures' => is_array($this->menu_features),
            'timezone' => $this->timezone ?: \App\Support\LocaleFormat::timezone($this),
            'subscriptionStatus' => $this->subscriptionStatusEnum()->value,
            'subscriptionStatusLabel' => __($this->subscriptionStatusEnum()->label()),
            'trialEndsAt' => optional($this->trial_ends_at)?->format('Y-m-d'),
            'storageOverageActive' => (bool) $this->storage_overage_active,
            'mailboxAddonActive' => (bool) $this->mailbox_addon_active,
            'createdAt' => optional($this->created_at)?->format('Y-m-d H:i'),
            'updatedAt' => optional($this->updated_at)?->format('Y-m-d H:i'),
        ];
    }

    public function subscriptionStatusEnum(): SubscriptionStatus
    {
        $status = $this->subscription_status;

        return $status instanceof SubscriptionStatus
            ? $status
            : (SubscriptionStatus::tryFrom((string) $status) ?? SubscriptionStatus::None);
    }
}
