<?php

namespace Tests\Unit;

use App\Support\AppInstall;
use Tests\TestCase;

class AppInstallTest extends TestCase
{
    public function test_android_apk_unavailable_without_file_or_url(): void
    {
        config([
            'app_install.android_apk_url' => '',
            'app_install.android_apk_path' => 'downloads/sa2-plus-missing.apk',
        ]);

        $this->assertFalse(AppInstall::androidApkAvailable());
        $this->assertNull(AppInstall::androidApkUrl());
    }

    public function test_android_apk_uses_external_url(): void
    {
        config([
            'app.url' => 'https://sa2-plus.com',
            'app_install.android_apk_url' => 'https://cdn.example.com/sa2-plus.apk',
            'app_install.android_apk_path' => 'downloads/sa2-plus-missing.apk',
        ]);

        $this->assertTrue(AppInstall::androidApkAvailable());
        $this->assertSame('https://cdn.example.com/sa2-plus.apk', AppInstall::androidApkUrl());
    }

    public function test_same_origin_apk_url_without_file_is_ignored(): void
    {
        config([
            'app.url' => 'https://sa2-plus.com',
            'app_install.android_apk_url' => 'https://sa2-plus.com/sa2-plus.apk',
            'app_install.android_apk_path' => 'downloads/sa2-plus-missing.apk',
        ]);

        $this->assertFalse(AppInstall::androidApkAvailable());
        $this->assertNull(AppInstall::androidApkUrl());
    }

    public function test_local_apk_is_served_at_short_url(): void
    {
        $relative = 'downloads/sa2-plus-test.apk';
        $absolute = public_path($relative);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, 'apk-bytes');

        config([
            'app.url' => 'https://sa2-plus.com',
            'app_install.android_apk_url' => 'https://sa2-plus.com/sa2-plus.apk',
            'app_install.android_apk_path' => $relative,
        ]);

        try {
            $url = AppInstall::androidApkUrl();
            $this->assertNotNull($url);
            $this->assertStringContainsString('/sa2-plus.apk', $url);

            $this->get('/sa2-plus.apk')
                ->assertOk()
                ->assertHeader('content-disposition');
        } finally {
            @unlink($absolute);
        }
    }
}
