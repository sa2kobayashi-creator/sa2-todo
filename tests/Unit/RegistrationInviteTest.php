<?php

namespace Tests\Unit;

use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_invite_code_is_readable_and_unique_enough(): void
    {
        $code = Registration::generateInviteCode();

        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/', $code);
        $this->assertNotSame(Registration::generateInviteCode(), $code);
    }

    public function test_invitation_message_includes_register_url_and_code(): void
    {
        Registration::setInviteCode('TEST-CODE-1234');

        $message = Registration::invitationMessage();

        $this->assertStringContainsString('TEST-CODE-1234', $message);
        $this->assertStringContainsString(url('/register'), $message);
        $this->assertStringContainsString((string) config('app.name'), $message);
    }

    public function test_invitation_message_is_empty_without_code(): void
    {
        Registration::setInviteCode('');

        $this->assertSame('', Registration::invitationMessage());
    }
}
