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
        'finance_extra_regions',
        'subscription_status',
        'trial_ends_at',
        'storage_overage_active',
        'mailbox_addon_active',
        'special_quota',
        'tenant_id',
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
            'special_quota' => 'boolean',
            'menu_features' => 'array',
            'footer_nav' => 'array',
            'header_nav' => 'array',
            'finance_extra_regions' => 'array',
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

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isTenantAdmin(): bool
    {
        return $this->isAdmin() && ! $this->isSuperAdmin() && $this->tenant_id !== null;
    }

    public function isPlatformStaff(): bool
    {
        return $this->isSuperAdmin() || ($this->isAdmin() && $this->tenant_id === null);
    }

    public function canManageOwnKeys(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if ($this->isTenantAdmin()) {
            return (bool) ($this->tenant?->allow_own_keys ?? true);
        }

        return $this->isAdmin();
    }

    public function sharesContractWith(?User $other): bool
    {
        if (! $other) {
            return false;
        }

        return $this->tenant_id === $other->tenant_id;
    }

    public function canViewUser(User $target): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if ($this->isTenantAdmin()) {
            return (int) $target->tenant_id === (int) $this->tenant_id;
        }
        if ($this->isAdmin()) {
            return $target->tenant_id === null;
        }

        return $this->id === $target->id;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeVisibleTo($query, User $actor)
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }
        if ($actor->tenant_id) {
            return $query->where('tenant_id', $actor->tenant_id);
        }

        return $query->whereNull('tenant_id');
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

    /** 運営がユーザー管理で付けた特別枠。自己登録の招待コードとは別。 */
    public function hasSpecialQuota(): bool
    {
        return (bool) $this->special_quota && ! $this->isSuperAdmin() && $this->tenant_id === null;
    }

    /** スーパー管理者または管理者（設定・ユーザー管理など） */
    public function isAdmin(): bool
    {
        return $this->roleEnum()->isStaff();
    }

    public function canAccess(string $feature): bool
    {
        if ($feature === MenuFeature::Travel->value) {
            return $this->isSuperAdmin();
        }

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
            return $this->isSuperAdmin() ? MenuFeature::values() : MenuFeature::assignableValues();
        }

        if (is_array($this->menu_features)) {
            return array_values(array_intersect($this->menu_features, MenuFeature::assignableValues()));
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
            ->whereIn('group_menu_features.feature', MenuFeature::assignableValues())
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
            return $this->isSuperAdmin() ? MenuFeature::values() : MenuFeature::assignableValues();
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
            'specialQuota' => (bool) $this->special_quota,
            'tenantId' => $this->tenant_id,
            'tenantName' => $this->tenant?->name,
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
