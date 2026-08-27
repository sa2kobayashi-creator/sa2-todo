<?php

namespace App\Http\Controllers;

use App\Exceptions\UsageLimitExceededException;
use App\Models\GuideTopic;
use App\Services\TransitAiConsultService;
use App\Services\UserUsageLimitService;
use App\Services\WorkersAiGuideService;
use Illuminate\Http\Request;

class GuideController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private WorkersAiGuideService $guide,
        private UserUsageLimitService $usageLimits,
        private TransitAiConsultService $transitAi,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $topics = $this->guide->topics($user);
        $topic = (string) $request->query('topic', WorkersAiGuideService::TOPIC_LIFE);
        if (! isset($topics[$topic])) {
            $topic = WorkersAiGuideService::TOPIC_LIFE;
        }

        return view('guide.index', array_merge($this->flashFromQuery($request), [
            'configured' => $this->guide->isReady(),
            'topics' => $topics,
            'topic' => $topic,
            'customTopics' => array_filter($topics, static fn (array $item) => ! empty($item['custom'])),
            'canAddTopic' => count($this->guide->userTopicRows($user)) < WorkersAiGuideService::MAX_USER_TOPICS,
            'maxTopics' => WorkersAiGuideService::MAX_USER_TOPICS,
            'dailyLimit' => $this->usageLimits->limitForUser($user, UserUsageLimitService::FEATURE_WORKERS_AI),
            'usedToday' => $this->usageLimits->usedToday($user, UserUsageLimitService::FEATURE_WORKERS_AI),
        ]));
    }

    public function ask(Request $request)
    {
        return $this->askTopic(
            $request,
            (string) $request->input('topic', ''),
            $this->guide->topics($request->user())
        );
    }

    /** 路線検索の画面に埋め込んだ相談パネルから呼ぶ。経路 API を挟んでから答える */
    public function askTransit(Request $request)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'messages' => ['nullable', 'array', 'max:12'],
            'messages.*.role' => ['required_with:messages', 'in:user,assistant'],
            'messages.*.content' => ['required_with:messages', 'string', 'max:2000'],
            'from' => ['nullable', 'string', 'max:120'],
            'to' => ['nullable', 'string', 'max:120'],
            'departureAt' => ['nullable', 'string', 'max:32'],
            'timeType' => ['nullable', 'in:departure,arrival'],
            'preference' => ['nullable', 'in:fastest,cheapest,fewest_transfers'],
            'preferredOperator' => ['nullable', 'string', 'max:40'],
            'lastSearch' => ['nullable', 'array'],
            'lastSearch.from' => ['nullable', 'string', 'max:120'],
            'lastSearch.to' => ['nullable', 'string', 'max:120'],
            'lastSearch.departureAt' => ['nullable', 'string', 'max:32'],
            'lastSearch.timeType' => ['nullable', 'in:departure,arrival'],
            'lastSearch.preference' => ['nullable', 'in:fastest,cheapest,fewest_transfers'],
            'lastSearch.preferredOperator' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();

        try {
            $this->usageLimits->assertWithin($user, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'text' => '', 'message' => $e->getMessage()], 429);
        }

        try {
            $result = $this->transitAi->ask(
                $user,
                (string) $data['prompt'],
                is_array($data['messages'] ?? null) ? $data['messages'] : [],
                $data
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'text' => '',
                'message' => __('うまく答えられませんでした。少し時間をおいてもう一度お試しください。'),
            ], 422);
        }

        if ($result['ok']) {
            try {
                $this->usageLimits->consume($user, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
            } catch (UsageLimitExceededException) {
            }
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** @param array<string, array<string, mixed>> $allowedTopics */
    private function askTopic(Request $request, string $topic, array $allowedTopics)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'messages' => ['nullable', 'array', 'max:12'],
            'messages.*.role' => ['required_with:messages', 'in:user,assistant'],
            'messages.*.content' => ['required_with:messages', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $wantsJson = $request->expectsJson() || $request->ajax();
        $fallbackUrl = '/guide?topic='.urlencode($topic);

        if (! isset($allowedTopics[$topic])) {
            $message = __('話題を選んでください。');

            return $wantsJson
                ? response()->json(['ok' => false, 'text' => '', 'message' => $message], 422)
                : $this->redirectWithMessage('/guide', $message, 'error');
        }

        try {
            $this->usageLimits->assertWithin($user, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
        } catch (UsageLimitExceededException $e) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
            }

            return $this->redirectWithMessage($fallbackUrl, $e->getMessage(), 'error');
        }

        try {
            $result = $this->guide->ask(
                $user,
                $topic,
                (string) $data['prompt'],
                is_array($data['messages'] ?? null) ? $data['messages'] : []
            );
        } catch (\Throwable $e) {
            report($e);
            $result = [
                'ok' => false,
                'text' => '',
                'message' => __('うまく答えられませんでした。少し時間をおいてもう一度お試しください。'),
            ];
        }

        if ($result['ok']) {
            try {
                $this->usageLimits->consume($user, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
            } catch (UsageLimitExceededException) {
                // 応答は返す。次回から上限
            }
        }

        if ($wantsJson) {
            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        if (! $result['ok']) {
            return $this->redirectWithMessage($fallbackUrl, $result['message'], 'error');
        }

        return $this->redirectWithMessage($fallbackUrl, $result['text']);
    }

    public function storeTopic(Request $request)
    {
        $user = $request->user();
        if (count($this->guide->userTopicRows($user)) >= WorkersAiGuideService::MAX_USER_TOPICS) {
            return $this->redirectWithMessage(
                '/guide',
                __('話題は :max 個までです。', ['max' => WorkersAiGuideService::MAX_USER_TOPICS]),
                'error'
            );
        }

        $data = $this->validateTopic($request);
        $topic = GuideTopic::create([
            'user_id' => $user->id,
            'label' => $data['label'],
            'icon' => $data['icon'],
            'instruction' => $data['instruction'],
            'sort_order' => (int) (GuideTopic::query()->where('user_id', $user->id)->max('sort_order') ?? 0) + 1,
        ]);

        return $this->redirectWithMessage('/guide?topic='.$topic->topicId(), __('話題を追加しました'));
    }

    public function updateTopic(Request $request, int $id)
    {
        $topic = $this->findTopic($request, $id);
        $data = $this->validateTopic($request);
        $topic->fill([
            'label' => $data['label'],
            'icon' => $data['icon'],
            'instruction' => $data['instruction'],
        ])->save();

        return $this->redirectWithMessage('/guide?topic='.$topic->topicId(), __('話題を更新しました'));
    }

    public function destroyTopic(Request $request, int $id)
    {
        $this->findTopic($request, $id)->delete();

        return $this->redirectWithMessage('/guide', __('話題を削除しました'));
    }

    public function moveTopic(Request $request, int $id)
    {
        $topic = $this->findTopic($request, $id);
        $up = $request->input('direction') !== 'down';

        $neighbor = GuideTopic::query()
            ->where('user_id', $topic->user_id)
            ->where('id', '!=', $topic->id)
            ->when(
                $up,
                fn ($query) => $query->where('sort_order', '<=', $topic->sort_order)->orderByDesc('sort_order')->orderByDesc('id'),
                fn ($query) => $query->where('sort_order', '>=', $topic->sort_order)->orderBy('sort_order')->orderBy('id')
            )
            ->first();

        if ($neighbor) {
            $order = $topic->sort_order;
            $topic->sort_order = $neighbor->sort_order;
            $neighbor->sort_order = $order;
            // 同順の並びは id で決まるため、入れ替わらないときは明示的にずらす
            if ($topic->sort_order === $neighbor->sort_order) {
                $topic->sort_order = $up ? $neighbor->sort_order - 1 : $neighbor->sort_order + 1;
            }
            $topic->save();
            $neighbor->save();
        }

        return $this->redirectWithMessage('/guide?topic='.$topic->topicId(), __('並び順を変えました'));
    }

    /** @return array{label: string, icon: ?string, instruction: ?string} */
    private function validateTopic(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:8'],
            'instruction' => ['nullable', 'string', 'max:600'],
        ]);

        return [
            'label' => trim((string) $data['label']),
            'icon' => trim((string) ($data['icon'] ?? '')) ?: null,
            'instruction' => trim((string) ($data['instruction'] ?? '')) ?: null,
        ];
    }

    private function findTopic(Request $request, int $id): GuideTopic
    {
        return GuideTopic::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
    }
}
