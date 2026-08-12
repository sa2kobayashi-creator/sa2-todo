<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Exceptions\UsageLimitExceededException;
use App\Models\TranslationHistory;
use App\Services\TranslateContentExtractor;
use App\Services\TranslationService;
use App\Services\UserUsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TranslateController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private TranslationService $translation,
        private TranslateContentExtractor $extractor,
        private UserUsageLimitService $usageLimits,
    ) {}

    public function index(Request $request)
    {
        return view('translate.index', [
            'configured' => $this->translation->isConfigured(),
            'source' => old('source', 'JA'),
            'target' => old('target', 'EN'),
            'input' => old('text', ''),
            'output' => '',
            ...$this->flashFromQuery($request),
        ]);
    }

    public function translate(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'source' => ['required', 'string', 'max:8'],
            'target' => ['required', 'string', 'max:8'],
            'mode' => ['nullable', 'string', Rule::in(['text', 'image', 'document', 'website'])],
            'title' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'save_history' => ['nullable', 'boolean'],
        ]);

        if (! $this->translation->isConfigured()) {
            return $this->fail($request, __('翻訳APIキーが未設定です。設定 → AI設定から DeepL キーを登録してください。'));
        }

        $source = strtoupper(trim($data['source']));
        $target = strtoupper(trim($data['target']));
        $text = (string) $data['text'];
        $mode = $data['mode'] ?? 'text';
        $chars = mb_strlen($text);

        try {
            if (Auth::check()) {
                $this->usageLimits->consume(Auth::user(), UserUsageLimitService::FEATURE_TRANSLATE, $chars);
            }
        } catch (UsageLimitExceededException $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
            }

            return $this->redirectWithMessage('/translate', $e->getMessage(), 'error');
        }

        $result = $this->translation->translateDetailed($text, $source, $target);
        if ($result === null) {
            return $this->fail($request, __('翻訳に失敗しました。APIキーや利用枠を確認してください。'));
        }

        $detected = $result['detected_source'] ?? null;
        $effectiveSource = ($source === 'AUTO' || $source === '') ? ($detected ?: 'AUTO') : $source;

        $historyId = null;
        if ($request->boolean('save_history', true) && Auth::check()) {
            $history = TranslationHistory::query()->create([
                'user_id' => Auth::id(),
                'mode' => $mode,
                'source_lang' => $effectiveSource,
                'target_lang' => $target,
                'source_text' => mb_substr($text, 0, 20000),
                'translated_text' => mb_substr($result['text'], 0, 20000),
                'title' => $data['title'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'is_saved' => false,
            ]);
            $historyId = $history->id;
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'translated' => $result['text'],
                'source_text' => $text,
                'source' => $source,
                'target' => $target,
                'detected_source' => $detected,
                'title' => $data['title'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'history_id' => $historyId,
            ]);
        }

        return view('translate.index', [
            'configured' => true,
            'source' => $source === 'AUTO' ? ($detected ?: 'JA') : $source,
            'target' => $target,
            'input' => $text,
            'output' => $result['text'],
            ...$this->flashFromQuery($request),
        ]);
    }

    public function document(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'source' => ['required', 'string', 'max:8'],
            'target' => ['required', 'string', 'max:8'],
        ]);

        if (! $this->translation->isConfigured()) {
            return response()->json(['ok' => false, 'message' => __('翻訳APIキーが未設定です。')], 422);
        }

        try {
            $text = $this->extractor->fromUpload($data['file']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        if (trim($text) === '') {
            return response()->json(['ok' => false, 'message' => __('ファイルから文字を抽出できませんでした。')], 422);
        }

        $request->merge([
            'text' => $text,
            'mode' => 'document',
            'title' => $data['file']->getClientOriginalName(),
            'save_history' => true,
        ]);

        return $this->translate($request);
    }

    public function website(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'source' => ['required', 'string', 'max:8'],
            'target' => ['required', 'string', 'max:8'],
        ]);

        if (! $this->translation->isConfigured()) {
            return response()->json(['ok' => false, 'message' => __('翻訳APIキーが未設定です。')], 422);
        }

        try {
            $page = $this->extractor->fromUrl($data['url']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        if (trim($page['text']) === '') {
            return response()->json(['ok' => false, 'message' => __('ページから文字を抽出できませんでした。')], 422);
        }

        $request->merge([
            'text' => $page['text'],
            'mode' => 'website',
            'title' => $page['title'],
            'source_url' => $page['url'],
            'save_history' => true,
        ]);

        return $this->translate($request);
    }

    public function history(Request $request)
    {
        $savedOnly = $request->boolean('saved');
        $items = TranslationHistory::query()
            ->where('user_id', Auth::id())
            ->when($savedOnly, fn ($q) => $q->where('is_saved', true))
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (TranslationHistory $row) => [
                'id' => $row->id,
                'mode' => $row->mode,
                'source_lang' => $row->source_lang,
                'target_lang' => $row->target_lang,
                'source_text' => $row->source_text,
                'translated_text' => $row->translated_text,
                'title' => $row->title,
                'source_url' => $row->source_url,
                'is_saved' => $row->is_saved,
                'created_at' => optional($row->created_at)?->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function toggleSaved(Request $request, int $id)
    {
        $row = TranslationHistory::query()
            ->where('user_id', Auth::id())
            ->whereKey($id)
            ->firstOrFail();

        $row->is_saved = ! $row->is_saved;
        $row->save();

        return response()->json(['ok' => true, 'is_saved' => $row->is_saved]);
    }

    public function destroyHistory(int $id)
    {
        TranslationHistory::query()
            ->where('user_id', Auth::id())
            ->whereKey($id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function fail(Request $request, string $message)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        return $this->redirectWithMessage('/translate', $message, 'error');
    }
}
