<?php

namespace App\Http\Controllers\Concerns;

use App\Services\GroupService;
use Illuminate\Http\JsonResponse;

trait ParsesVoiceTranscript
{
    /** @return list<array{id: int, name: string}> */
    protected function voiceGroups(int $userId, GroupService $groups): array
    {
        return array_map(static function (array $group): array {
            return [
                'id' => (int) $group['id'],
                'name' => (string) $group['name'],
            ];
        }, $groups->listApprovedForUser($userId)->all());
    }

    protected function voiceParseJsonResponse(callable $parser): JsonResponse
    {
        try {
            $parsed = $parser();
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() ?: __('音声の解析に失敗しました。'),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'parsed' => $parsed,
            'provider' => $parsed['provider'] ?? null,
        ]);
    }
}
