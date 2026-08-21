<?php

namespace Tests\Unit;

use App\Support\PublicUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicUrlGuardTest extends TestCase
{
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/admin'],
            'loopback name' => ['http://localhost/admin'],
            'private class A' => ['http://10.0.0.5/'],
            'private class C' => ['http://192.168.1.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_internal_addresses_are_rejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicUrlGuard::assertFetchable($url);
    }

    public function test_non_http_schemes_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicUrlGuard::assertFetchable('file:///etc/passwd');
    }

    public function test_public_ip_is_allowed(): void
    {
        PublicUrlGuard::assertFetchable('https://8.8.8.8/');

        $this->expectNotToPerformAssertions();
    }
}
