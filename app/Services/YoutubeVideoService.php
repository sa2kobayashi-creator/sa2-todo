<?php

namespace App\Services;

use App\Models\YoutubeVideo;
use Illuminate\Support\Facades\Http;

class YoutubeVideoService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return YoutubeVideo::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (YoutubeVideo $video) => $this->toArray($video))
            ->all();
    }

    public function findOwned(int $userId, int $id): ?YoutubeVideo
    {
        return YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function addFromUrl(int $userId, string $rawUrl, ?string $title = null): array
    {
        $youtubeId = $this->extractVideoId($rawUrl);
        if ($youtubeId === null) {
            throw new \InvalidArgumentException(__('YouTubeのURLを認識できませんでした。'));
        }

        $existing = YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('youtube_id', $youtubeId)
            ->first();
        if ($existing) {
            throw new \InvalidArgumentException(__('この動画はすでに登録されています。'));
        }

        $canonical = 'https://www.youtube.com/watch?v='.$youtubeId;
        $meta = $this->fetchOEmbed($canonical);
        $sortOrder = (int) YoutubeVideo::query()->where('user_id', $userId)->max('sort_order') + 10;

        $video = YoutubeVideo::query()->create([
            'user_id' => $userId,
            'youtube_id' => $youtubeId,
            'title' => $this->resolveTitle($title, $meta['title'] ?? null, $youtubeId),
            'url' => $canonical,
            'thumbnail_url' => $meta['thumbnail_url']
                ?? 'https://i.ytimg.com/vi/'.$youtubeId.'/hqdefault.jpg',
            'sort_order' => $sortOrder,
        ]);

        return $this->toArray($video);
    }

    public function delete(int $userId, int $id): bool
    {
        $video = $this->findOwned($userId, $id);
        if (! $video) {
            return false;
        }

        return (bool) $video->delete();
    }

    public function extractVideoId(string $rawUrl): ?string
    {
        $url = trim($rawUrl);
        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $id = null;
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            if (! empty($query['v']) && is_string($query['v'])) {
                $id = $query['v'];
            } elseif (preg_match('#^/(embed|shorts|live|v)/([A-Za-z0-9_-]{6,})#', $path, $m)) {
                $id = $m[2];
            }
        } elseif ($host === 'youtu.be') {
            if (preg_match('#^/([A-Za-z0-9_-]{6,})#', $path, $m)) {
                $id = $m[1];
            }
        }

        if ($id === null) {
            return null;
        }

        $id = strtok($id, '?&') ?: $id;
        if (! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id)) {
            return null;
        }

        return $id;
    }

    /**
     * @return array{title?: string, thumbnail_url?: string}
     */
    public function fetchOEmbed(string $url): array
    {
        try {
            $response = Http::timeout(6)
                ->acceptJson()
                ->get('https://www.youtube.com/oembed', [
                    'url' => $url,
                    'format' => 'json',
                ]);
            if (! $response->successful()) {
                return [];
            }
            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            return [
                'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
                'thumbnail_url' => is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(YoutubeVideo $video): array
    {
        return [
            'id' => $video->id,
            'source' => 'youtube',
            'youtubeId' => $video->youtube_id,
            'title' => $video->title ?: __('YouTube動画'),
            'url' => $video->url,
            'embedUrl' => 'https://www.youtube.com/embed/'.$video->youtube_id.'?rel=0&modestbranding=1',
            'thumbUrl' => $video->thumbnail_url
                ?: 'https://i.ytimg.com/vi/'.$video->youtube_id.'/hqdefault.jpg',
            'createdAt' => $video->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function resolveTitle(?string $input, ?string $oembed, string $youtubeId): string
    {
        $input = is_string($input) ? trim($input) : '';
        if ($input !== '') {
            return mb_substr($input, 0, 255);
        }
        if (is_string($oembed) && trim($oembed) !== '') {
            return mb_substr(trim($oembed), 0, 255);
        }

        return 'YouTube '.$youtubeId;
    }
}
