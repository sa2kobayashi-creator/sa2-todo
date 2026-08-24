<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\HolidayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonalHolidayTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_light_user_can_set_personal_holidays_without_changing_instance(): void
    {
        $light = $this->makeUser(UserRole::Light, 'light-hol@example.com');
        $admin = $this->makeUser(UserRole::Admin, 'admin-hol@example.com');

        $this->actingAs($light)->get('/mypage/holidays')->assertOk();
        $this->actingAs($light)->get('/settings?section=holidays')->assertForbidden();

        $this->actingAs($light)
            ->post('/mypage/holidays/add', [
                'year' => 2026,
                'date' => '2026-12-31',
                'name' => '私の休み',
            ])
            ->assertRedirect();

        $holidays = app(HolidayService::class);
        $this->assertSame('私の休み', $holidays->getHolidayInfoMapForYear(2026, (int) $light->id)['2026-12-31']['name'] ?? null);
        $this->assertArrayNotHasKey('2026-12-31', $holidays->getHolidayInfoMapForYear(2026));

        $this->actingAs($admin)->get('/settings?section=holidays&year=2026')
            ->assertOk()
            ->assertDontSee('私の休み', false);

        $lightDates = $this->actingAs($light)
            ->get('/api/holiday-dates?start=2026-12-01&end=2026-12-31')
            ->assertOk()
            ->json();
        $this->assertContains('2026-12-31', $lightDates['closure'] ?? []);

        $adminDates = $this->actingAs($admin)
            ->get('/api/holiday-dates?start=2026-12-01&end=2026-12-31')
            ->assertOk()
            ->json();
        $this->assertNotContains('2026-12-31', $adminDates['closure'] ?? []);
    }
}
