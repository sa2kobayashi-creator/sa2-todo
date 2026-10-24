<?php

namespace Tests;

use App\Models\MediaStorageSetting;
use App\Services\MaintenanceModeService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (app()->bound(TenantContext::class)) {
            app(TenantContext::class)->set(null);
        }
        MediaStorageSetting::forgetSchemaCache();
        $maintenanceFile = storage_path('framework/'.MaintenanceModeService::FILENAME);
        if (is_file($maintenanceFile)) {
            @unlink($maintenanceFile);
        }
        // 英語OSの PHPUnit でも画面文言テストが日本語で安定するようにする
        $this->withHeader('Accept-Language', 'ja');
    }

    protected function tearDown(): void
    {
        $maintenanceFile = storage_path('framework/'.MaintenanceModeService::FILENAME);
        if (is_file($maintenanceFile)) {
            @unlink($maintenanceFile);
        }
        parent::tearDown();
    }
}
