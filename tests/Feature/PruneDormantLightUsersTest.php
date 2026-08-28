<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\LightDormantWarningMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PruneDormantLightUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_warns_then_deletes_inactive_light_users(): void
    {
        Mail::fake();

        $inactive = User::create([
            'email' => 'dormant@example.com',
            'display_name' => 'Dormant',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
            'last_seen_at' => now()->subDays(100),
            'created_at' => now()->subDays(120),
        ]);

        Artisan::call('users:prune-dormant-light');

        $inactive->refresh();
        $this->assertNotNull($inactive->dormant_warned_at);
        Mail::assertSent(LightDormantWarningMail::class);

        $inactive->forceFill([
            'dormant_warned_at' => now()->subDays(15),
        ])->save();

        Artisan::call('users:prune-dormant-light');

        $this->assertDatabaseMissing('users', ['email' => 'dormant@example.com']);
    }

    public function test_login_clears_dormant_warning(): void
    {
        $user = User::create([
            'email' => 'wake@example.com',
            'display_name' => 'Wake',
            'password' => Hash::make('password123'),
            'role' => UserRole::Light,
            'dormant_warned_at' => now()->subDay(),
            'last_seen_at' => now()->subDays(100),
        ]);

        $this->post('/login', [
            'email' => 'wake@example.com',
            'password' => 'password123',
        ])->assertRedirect();

        $user->refresh();
        $this->assertNull($user->dormant_warned_at);
        $this->assertNotNull($user->last_seen_at);
        $this->assertTrue($user->last_seen_at->gt(now()->subMinute()));
    }
}
