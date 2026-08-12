<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Note;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class UserDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_download_export_zip(): void
    {
        $user = User::create([
            'email' => 'export@example.com',
            'display_name' => 'Export',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        Todo::create([
            'user_id' => $user->id,
            'title' => 'export-todo',
            'completed' => false,
        ]);
        Note::create([
            'user_id' => $user->id,
            'title' => 'export-note',
            'body' => 'body',
            'category' => 'personal',
        ]);

        $response = $this->actingAs($user)->get('/mypage/export');
        $response->assertOk();
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('content-type'));

        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $json = $zip->getFromName('export.json');
        $zip->close();
        @unlink($tmp);

        $payload = json_decode((string) $json, true);
        $this->assertSame('export@example.com', $payload['profile']['email'] ?? null);
        $this->assertSame('export-todo', $payload['todos'][0]['title'] ?? null);
        $this->assertSame('export-note', $payload['notes'][0]['title'] ?? null);
    }
}
