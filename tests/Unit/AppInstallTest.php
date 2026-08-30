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
            'app_install.android_apk_url' => 'https://cdn.example.com/sa2-plus.apk',
            'app_install.android_apk_path' => 'downloads/sa2-plus-missing.apk',
        ]);

        $this->assertTrue(AppInstall::androidApkAvailable());
        $this->assertSame('https://cdn.example.com/sa2-plus.apk', AppInstall::androidApkUrl());
    }
}
