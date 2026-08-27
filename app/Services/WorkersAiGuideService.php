<?php

namespace App\Services;

use App\Enums\AppContext;
use App\Models\GuideTopic;
use App\Models\TransitFavorite;
use App\Models\User;
use Illuminate\Support\Collection;

class WorkersAiGuideService
{
    public const TOPIC_LIFE = 'life';

    public const TOPIC_RECIPE = 'recipe';

    public const TOPIC_CALENDAR = 'calendar';

    /** 生活ガイドには出さず、路線検索の画面に組み込む話題 */
    public const TOPIC_TRANSIT = 'transit';

    public const MAX_USER_TOPICS = 12;

    public function __construct(
        private CloudflareWorkersAiConfigService $workersAi,
        private DashboardHomeService $home,
        private AppContextService $contexts,
        private IntegrationUsageService $usage,
    ) {}

    /** @return list<string> 生活ガイドの既定話題 */
    public static function topicIds(): array
    {
        return [
            self::TOPIC_LIFE,
            self::TOPIC_RECIPE,
            self::TOPIC_CALENDAR,
        ];
    }

    /**
     * 生活ガイドに並ぶ既定話題。
     *
     * @return array<string, array{id: string, label: string, ready: bool, hint: string, icon: string, samples: list<string>, custom: bool}>
     */
    public function builtinTopics(): array
    {
        return [
            self::TOPIC_LIFE => [
                'id' => self::TOPIC_LIFE,
                'label' => __('生活の知恵'),
                'ready' => true,
                'custom' => false,
                'hint' => __('日々の案内や、ちょっとした話し相手です。'),
                'icon' => '💡',
                'samples' => [
                    __('シャツの襟の黄ばみを落とす方法は？'),
                    __('雨の日に部屋干しを早く乾かすコツは？'),
                    __('少し疲れました。気分転換の方法を教えて'),
                ],
            ],
            self::TOPIC_RECIPE => [
                'id' => self::TOPIC_RECIPE,
                'label' => __('料理レシピ'),
                'ready' => true,
                'custom' => false,
                'hint' => __('材料と手順を、家庭向けに短く出します。'),
                'icon' => '🍳',
                'samples' => [
                    __('スライスオニオンサラダのレシピを教えて'),
                    __('鶏むね肉と玉ねぎで、15分でできる夕食は？'),
                    __('冷蔵庫の卵と豆腐で一品作りたい'),
                ],
            ],
            self::TOPIC_CALENDAR => [
                'id' => self::TOPIC_CALENDAR,
                'label' => __('カレンダー'),
                'ready' => true,
                'custom' => false,
                'hint' => __('今日の予定と Todo を見ながら案内します。'),
                'icon' => '📅',
                'samples' => [
                    __('今日の予定を整理して教えて'),
                    __('今日中に終わらせるべきことは？'),
                    __('明日の準備で今やっておくことは？'),
                ],
            ],
        ];
    }

    /**
     * 路線検索の画面に埋め込む話題。生活ガイドの一覧には出さない。
     *
     * @return array<string, array{id: string, label: string, ready: bool, hint: string, icon: string, samples: list<string>, custom: bool}>
     */
    public function embeddedTopics(): array
    {
        return [
            self::TOPIC_TRANSIT => [
                'id' => self::TOPIC_TRANSIT,
                'label' => __('AI に路線を相談'),
                'ready' => true,
                'custom' => false,
                'hint' => __('相談すると経路検索 API で時刻・運賃・乗換を取り、その結果で答えます。続けて聞けば条件を変えて再検索します。'),
                'icon' => '🚃',
                'samples' => [
                    __('天神から博多駅までの行き方を教えて'),
                    __('もっと安いルートは？'),
                    __('雨の日でも濡れにくい乗り換えは？'),
                ],
            ],
        ];
    }

    /**
     * ユーザーが自分で足した話題。
     *
     * @return array<string, array{id: string, label: string, ready: bool, hint: string, icon: string, samples: list<string>, custom: bool, topic_id: int, instruction: string}>
     */
    public function userTopics(User $user): array
    {
        $topics = [];
        foreach ($this->userTopicRows($user) as $row) {
            $topics[$row->topicId()] = [
                'id' => $row->topicId(),
                'label' => (string) $row->label,
                'ready' => true,
                'custom' => true,
                'hint' => (string) ($row->instruction ?: __('自分で追加した話題です。')),
                'icon' => (string) ($row->icon ?: '💬'),
                'samples' => [],
                'topic_id' => (int) $row->getKey(),
                'instruction' => (string) $row->instruction,
            ];
        }

        return $topics;
    }

    /** @return Collection<int, GuideTopic> */
    public function userTopicRows(User $user)
    {
        return GuideTopic::query()
            ->where('user_id', $user->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * 生活ガイドの画面に並ぶ話題（既定＋ユーザー追加）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function topics(?User $user = null): array
    {
        $topics = $this->builtinTopics();
        if ($user) {
            $topics += $this->userTopics($user);
        }

        return $topics;
    }

    /**
     * ask() で受け付ける全話題。
     *
     * @return array<string, array<string, mixed>>
     */
    public function allTopics(User $user): array
    {
        return $this->topics($user) + $this->embeddedTopics();
    }

    public function isReady(): bool
    {
        return $this->workersAi->isReady();
    }

    /**
     * @param  list<array{role?: mixed, content?: mixed}>  $history
     * @return array{ok: bool, text: string, message: string}
     */
    public function ask(User $user, string $topic, string $prompt, array $history = []): array
    {
        $topics = $this->allTopics($user);
        if (! isset($topics[$topic])) {
            return ['ok' => false, 'text' => '', 'message' => __('話題を選んでください。')];
        }
        if (! $topics[$topic]['ready']) {
            return ['ok' => false, 'text' => '', 'message' => (string) $topics[$topic]['hint']];
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($user, $topics[$topic])],
        ];
        foreach (array_slice($history, -10) as $turn) {
            $role = (string) ($turn['role'] ?? '');
            $content = trim((string) ($turn['content'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr(trim($prompt), 0, 2000)];

        $result = $this->workersAi->complete($messages, 700);
        if ($result['ok']) {
            $this->usage->increment('workers_ai', 'requests');
        }

        return $result;
    }

    /** @param array<string, mixed> $topic */
    private function systemPrompt(User $user, array $topic): string
    {
        $locale = app()->getLocale() === 'en' ? 'English' : 'Japanese';
        $base = 'You are a concise household helper inside the sa2-plus life app. '
            .'Answer in '.$locale.'. Keep answers practical and short. '
            .'Do not invent private data that is not in the prompt. '
            .'Do not give medical, legal, or financial advice as if you were a professional.';

        if (! empty($topic['custom'])) {
            $instruction = trim((string) ($topic['instruction'] ?? ''));
            $extra = ' The user created this topic: "'.mb_substr((string) $topic['label'], 0, 60).'".';
            if ($instruction !== '') {
                $extra .= ' Follow their instruction: '.mb_substr($instruction, 0, 600);
            }

            return trim($base.$extra);
        }

        $extra = match ((string) $topic['id']) {
            self::TOPIC_RECIPE => ' Focus on home cooking: ingredients, steps, timing, and substitutions.',
            self::TOPIC_CALENDAR => ' Help the user plan the day using the calendar snapshot below. Suggest order, travel buffer, and what to register as a ToDo if something is missing.',
            self::TOPIC_TRANSIT => ' Help with getting around by train, bus, subway, and ferry. '
                .'Use only timetable, fare, and transfer facts supplied with the prompt.',
            default => ' Help with everyday life tips, how to use this app, and friendly conversation.',
        };

        $context = match ((string) $topic['id']) {
            self::TOPIC_CALENDAR => $this->calendarSnapshot($user),
            self::TOPIC_TRANSIT => $this->transitSnapshot($user),
            default => '',
        };

        return trim($base.$extra."\n".$context);
    }

    private function calendarSnapshot(User $user): string
    {
        try {
            $context = $this->contexts->current($user, request());
        } catch (\Throwable) {
            $context = AppContext::Personal;
        }

        try {
            $home = $this->home->build($user, [], $context);
        } catch (\Throwable) {
            return '';
        }

        $lines = [
            'Today: '.($home['dateLabel'] ?? ''),
            'Context: '.($context === AppContext::Work ? 'work' : 'personal'),
        ];
        $events = array_slice($home['calendar']['events'] ?? [], 0, 12);
        if ($events === []) {
            $lines[] = 'Events: none listed.';
        } else {
            $lines[] = 'Events:';
            foreach ($events as $event) {
                $lines[] = '- '.trim((string) ($event['timeLabel'] ?? '')).' '.trim((string) ($event['title'] ?? ''));
            }
        }
        $todos = array_slice($home['nextActions'] ?? [], 0, 8);
        if ($todos === []) {
            $lines[] = 'ToDos: none listed.';
        } else {
            $lines[] = 'ToDos:';
            foreach ($todos as $item) {
                $lines[] = '- '.trim((string) ($item['title'] ?? ''));
            }
        }

        return implode("\n", $lines);
    }

    private function transitSnapshot(User $user): string
    {
        try {
            $rows = TransitFavorite::query()
                ->where('user_id', $user->id)
                ->orderBy('sort_order')
                ->limit(10)
                ->get();
        } catch (\Throwable) {
            return '';
        }

        if ($rows->isEmpty()) {
            return 'Saved routes: none.';
        }

        $lines = ['Saved routes:'];
        foreach ($rows as $row) {
            $lines[] = '- ['.trim((string) $row->category).'] '.trim((string) $row->name)
                .' '.trim((string) $row->from_place).' -> '.trim((string) $row->to_place)
                .' '.trim((string) $row->line_name);
        }

        return implode("\n", $lines);
    }
}
