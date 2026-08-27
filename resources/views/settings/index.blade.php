<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('設定') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'settings', 'settingsSection' => $section ?? 'holidays'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
      @if(!empty($isTenantAdmin))
        <div class="banner notice">{{ __('この画面のストレージ・AI・Maps などのキーは、あなたの契約だけに使われます。LINE 公式アカウント、Messenger、Web Push は運営の共有設定です。') }}</div>
      @endif

      @if(($section ?? 'holidays') === 'nav')
        @include('settings.partials.nav')
      @elseif(($section ?? 'holidays') === 'usage')
        @include('settings.partials.usage')
      @elseif(($section ?? '') === 'limits' && !empty($isSuperAdmin))
        @include('settings.partials.limits')
      @else
      @include('settings.partials.setup-tabs')
      @if(($section ?? 'holidays') === 'holidays')
      @include('settings.partials.holidays', [
        'holidayBase' => '/settings',
        'prevHolidayHref' => $settingsPath('holidays', $prevHolidayYear),
        'nextHolidayHref' => $settingsPath('holidays', $nextHolidayYear),
      ])
      @elseif(($section ?? '') === 'ai')
      <div class="panel" id="ai-settings">
        <h2>{{ __('AI設定') }}</h2>
        <p class="hint">{{ __('翻訳は DeepL、入出金・Todo・メモの音声入力は ChatGPT / Gemini、生活ガイドは Cloudflare Workers AI、動画検索は YouTube Data API を使います。キーを保存すると、このアプリのユーザーが使えます。利用料は管理者の責任です。') }}</p>

        @php $llm = $llmSettings ?? []; $llmSettingsArr = $llm['settings'] ?? []; $yt = $youtubeSettings ?? []; $wai = $workersAiSettings ?? []; $waiSettingsArr = $wai['settings'] ?? []; @endphp
        <div class="storage-settings ai-llm-panel" id="ai-llm-settings">
          <h3 class="ai-settings-subtitle">{{ __('LLM（入出金音声入力）') }}</h3>
          <p class="hint">{{ __('Web Speech API で文字起こしした文言を、選択した LLM が JSON に変換します。ChatGPT と Gemini を設定し、使用する方を切り替えられます。') }}</p>
          @if(!empty($llm['last_test_message']))
            <p class="hint storage-test-result {{ ($llm['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
              {{ $llm['last_test_message'] }}
              @if(!empty($llm['last_tested_at']))
                <span class="inline-hint">({{ $llm['last_tested_at'] }})</span>
              @endif
            </p>
          @endif
          <form method="post" action="/settings/ai/llm" class="storage-provider-form" id="ai-llm-form">
            @csrf
            <label class="storage-enable">
              <input type="checkbox" name="enabled" value="1" @checked(!empty($llm['enabled'])) />
              {{ __('有効にする') }}
            </label>
            <label>
              {{ __('使用するプロバイダ') }}
              <select name="active_provider" id="ai-llm-active-provider">
                <option value="openai" @selected(($llmSettingsArr['active_provider'] ?? '') === 'openai')>ChatGPT (OpenAI)</option>
                <option value="gemini" @selected(($llmSettingsArr['active_provider'] ?? '') === 'gemini')>Gemini (Google)</option>
              </select>
            </label>
            <label>
              {{ __('OpenAI APIキー') }}
              <input type="password" name="openai_api_key" value="{{ $llm['openai_api_key_masked'] ?? '' }}" placeholder="sk-..." autocomplete="off" />
            </label>
            <label class="ai-model-field">
              {{ __('OpenAI モデル') }}
              <div class="ai-model-row">
                <select name="openai_model" id="openai-model-select">
                  @foreach(($llm['openai_model_options'] ?? [$llmSettingsArr['openai_model'] ?? 'gpt-4o-mini']) as $modelId)
                    <option value="{{ $modelId }}" @selected($modelId === ($llmSettingsArr['openai_model'] ?? 'gpt-4o-mini'))>{{ $modelId }}</option>
                  @endforeach
                </select>
                <button type="button" class="secondary ai-model-refresh" data-url="/settings/ai/llm/models" data-provider="openai" data-select="#openai-model-select" data-live="#openai-models-live">{{ __('最新取得') }}</button>
              </div>
              <span class="hint storage-test-live" id="openai-models-live">
                @if(!empty($llm['openai_models_fetched_at']))
                  {{ __('取得:') }} {{ $llm['openai_models_fetched_at'] }}
                @endif
              </span>
            </label>
            <label>
              {{ __('Gemini APIキー') }}
              <input type="password" name="gemini_api_key" value="{{ $llm['gemini_api_key_masked'] ?? '' }}" placeholder="AIza..." autocomplete="off" />
            </label>
            <label class="ai-model-field">
              {{ __('Gemini モデル') }}
              <div class="ai-model-row">
                <select name="gemini_model" id="gemini-model-select">
                  @foreach(($llm['gemini_model_options'] ?? [$llmSettingsArr['gemini_model'] ?? 'gemini-2.0-flash']) as $modelId)
                    <option value="{{ $modelId }}" @selected($modelId === ($llmSettingsArr['gemini_model'] ?? 'gemini-2.0-flash'))>{{ $modelId }}</option>
                  @endforeach
                </select>
                <button type="button" class="secondary ai-model-refresh" data-url="/settings/ai/llm/models" data-provider="gemini" data-select="#gemini-model-select" data-live="#gemini-models-live">{{ __('最新取得') }}</button>
              </div>
              <span class="hint storage-test-live" id="gemini-models-live">
                @if(!empty($llm['gemini_models_fetched_at']))
                  {{ __('取得:') }} {{ $llm['gemini_models_fetched_at'] }}
                @endif
              </span>
            </label>
            <div class="storage-form-actions">
              <button type="submit" class="button-link">{{ __('保存') }}</button>
              <button type="button" class="secondary" id="ai-llm-test-btn">{{ __('接続テスト') }}</button>
              <span class="storage-test-live hint" id="ai-llm-test-live"></span>
            </div>
          </form>
        </div>

        <div class="storage-settings ai-llm-panel" id="workers-ai-settings">
          <h3 class="ai-settings-subtitle">{{ __('Cloudflare Workers AI') }}</h3>
          <p class="hint">{{ __('生活ガイド（知恵・話し相手・料理・カレンダー案内）に使います。Cloudflare ダッシュボードのアカウント ID と、Workers AI 権限の API トークンを登録してください。') }}</p>
          @if(!empty($wai['last_test_message']))
            <p class="hint storage-test-result {{ ($wai['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
              {{ $wai['last_test_message'] }}
              @if(!empty($wai['last_tested_at']))
                <span class="inline-hint">({{ $wai['last_tested_at'] }})</span>
              @endif
            </p>
          @endif
          <form method="post" action="/settings/ai/workers-ai" class="storage-provider-form" id="workers-ai-form">
            @csrf
            <label class="storage-enable">
              <input type="checkbox" name="enabled" value="1" @checked(!empty($wai['enabled'])) />
              {{ __('有効にする') }}
            </label>
            <label>
              {{ __('Cloudflare アカウント ID') }}
              <input type="text" name="account_id" value="{{ $waiSettingsArr['account_id'] ?? '' }}" autocomplete="off" placeholder="0123456789abcdef0123456789abcdef" />
            </label>
            <label>
              {{ __('Workers AI API トークン') }}
              <input type="password" name="api_token" value="{{ $wai['api_token_masked'] ?? '' }}" autocomplete="off" />
            </label>
            <p class="hint">{{ __('トークンは Cloudflare の「マイプロフィール → API トークン → トークンを作成」で Workers AI テンプレートを選び、アカウントリソースに上のアカウントを指定して作成してください。権限が足りないトークンは「認証エラー」になります。') }}</p>
            <label class="ai-model-field">
              {{ __('モデル') }}
              <div class="ai-model-row">
                <select name="model" id="workers-ai-model-select">
                  @foreach(($wai['model_options'] ?? [$waiSettingsArr['model'] ?? '@cf/meta/llama-3.1-8b-instruct-fp8']) as $modelId)
                    <option value="{{ $modelId }}" @selected($modelId === ($waiSettingsArr['model'] ?? '@cf/meta/llama-3.1-8b-instruct-fp8'))>{{ $modelId }}</option>
                  @endforeach
                </select>
                <button type="button" class="secondary ai-model-refresh" data-url="/settings/ai/workers-ai/models" data-select="#workers-ai-model-select" data-live="#workers-ai-models-live">{{ __('最新取得') }}</button>
              </div>
              <span class="hint storage-test-live" id="workers-ai-models-live">
                @if(!empty($wai['models_fetched_at']))
                  {{ __('取得:') }} {{ $wai['models_fetched_at'] }}
                @endif
              </span>
            </label>
            <p class="hint">{{ __('一覧は Cloudflare の Text Generation モデルです。キー保存後に「最新取得」してください。品質を上げるなら Llama 4 Scout などを選べます。') }}</p>
            <div class="storage-form-actions">
              <button type="submit" class="button-link">{{ __('保存') }}</button>
              <button type="button" class="secondary" id="workers-ai-test-btn">{{ __('接続テスト') }}</button>
              <span class="storage-test-live hint" id="workers-ai-test-live"></span>
            </div>
          </form>
        </div>

        <div class="storage-settings ai-llm-panel" id="youtube-api-settings">
          <h3 class="ai-settings-subtitle">{{ __('YouTube検索（Data API）') }}</h3>
          <p class="hint">{{ __('Google Cloud で YouTube Data API v3 を有効化し、APIキーを登録すると、動画ページでキーワード検索が使えます。') }}</p>
          @if(!empty($yt['last_test_message']))
            <p class="hint storage-test-result {{ ($yt['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
              {{ $yt['last_test_message'] }}
              @if(!empty($yt['last_tested_at']))
                <span class="inline-hint">({{ $yt['last_tested_at'] }})</span>
              @endif
            </p>
          @endif
          <form method="post" action="/settings/ai/youtube" class="storage-provider-form" id="youtube-api-form">
            @csrf
            <label class="storage-enable">
              <input type="checkbox" name="enabled" value="1" @checked(!empty($yt['enabled'])) />
              {{ __('有効にする') }}
            </label>
            <label>
              {{ __('YouTube Data API キー') }}
              <input type="password" name="api_key" value="{{ $yt['api_key_masked'] ?? '' }}" placeholder="AIza..." autocomplete="off" />
            </label>
            <div class="storage-form-actions">
              <button type="submit" class="button-link">{{ __('保存') }}</button>
              <button type="button" class="secondary" id="youtube-api-test-btn">{{ __('接続テスト') }}</button>
              <span class="storage-test-live hint" id="youtube-api-test-live"></span>
            </div>
          </form>
        </div>

        <h3 class="ai-settings-subtitle">{{ __('AI翻訳（DeepL）') }}</h3>
        <p class="hint">{{ __('複数のAPIキーを登録すると、使用制限に達した場合に自動的に次のキーへ切り替わります。') }}</p>

        @php
          $deeplPricing = $deeplPricing ?? ['paid_monthly_base_eur' => 5.49, 'paid_per_million_chars_eur' => 20];
          $deeplUsageSummaries = $deeplUsageSummaries ?? [];
        @endphp

        <div class="deepl-usage-panel" id="deepl-usage-overview">
          <div class="deepl-usage-panel-head">
            <div>
              <h4 class="deepl-usage-heading">{{ __('DeepL使用量・料金') }}</h4>
              <p class="hint">{{ __('DeepL /v2/usage API から取得した文字数と、設定した単価に基づく推定料金を表示します。') }}</p>
            </div>
            @if(count($translationKeys) > 0)
              <form method="post" action="/settings/translation-keys/fetch-usage-all" class="inline-form">
                @csrf
                <button type="submit" class="secondary">{{ __('DeepL使用量を更新') }}</button>
              </form>
            @endif
          </div>

          @if(count($translationKeys) === 0)
            <p class="hint">{{ __('APIキー登録後に使用量を取得できます。') }}</p>
          @else
            <div class="deepl-usage-cards">
              @foreach($translationKeys as $key)
                @php $summary = $deeplUsageSummaries[$key->id] ?? null; @endphp
                <article class="deepl-usage-card">
                  <div class="deepl-usage-card-top">
                    <strong>{{ $key->name }}</strong>
                    <span class="deepl-plan-badge {{ !empty($summary['is_paid_plan']) ? 'is-paid' : 'is-free' }}">
                      {{ !empty($summary['is_paid_plan']) ? __('有料') : __('無料') }}
                    </span>
                  </div>
                  <div class="deepl-usage-metric">
                    <span class="deepl-usage-label">{{ __('文字数') }}</span>
                    <span class="deepl-usage-value">
                      {{ number_format((int) ($summary['character_count'] ?? 0)) }}
                      @if(($summary['character_limit'] ?? null) !== null)
                        <span class="hint">/ {{ number_format((int) $summary['character_limit']) }}</span>
                      @endif
                    </span>
                  </div>
                  @if(($summary['usage_rate'] ?? null) !== null)
                    @php $rate = (float) $summary['usage_rate']; @endphp
                    <div class="deepl-usage-bar" role="progressbar" aria-valuenow="{{ min(100, $rate) }}" aria-valuemin="0" aria-valuemax="100">
                      <span class="deepl-usage-bar-fill {{ $rate > 80 ? 'is-danger' : ($rate > 50 ? 'is-warn' : '') }}" style="width: {{ min(100, $rate) }}%"></span>
                    </div>
                    <div class="hint deepl-usage-rate-text">{{ number_format($rate, 1) }}%</div>
                  @endif
                  @if(!empty($summary['is_paid_plan']) && ($summary['estimated_cost'] ?? null) !== null)
                    <div class="deepl-cost-block">
                      <div>{{ __('月額基本') }}: €{{ number_format((float) $summary['monthly_base_fee'], 2) }}</div>
                      <div>{{ __('従量') }}: €{{ number_format((float) $summary['usage_cost'], 4) }}</div>
                      <div class="deepl-cost-total">{{ __('推定合計') }}: €{{ number_format((float) $summary['estimated_cost'], 2) }}</div>
                    </div>
                  @else
                    <p class="hint">{{ __('無料プランのため従量料金はありません。') }}</p>
                  @endif
                  <p class="hint deepl-fetched-at">
                    {{ __('取得:') }}
                    {{ !empty($summary['fetched_at']) ? $summary['fetched_at'] : __('未取得') }}
                  </p>
                </article>
              @endforeach
            </div>
          @endif

          <form method="post" action="/settings/translation-keys/pricing" class="deepl-pricing-form" id="deepl-usage-pricing">
            @csrf
            <h4 class="deepl-usage-heading">{{ __('有料プラン料金設定（EUR）') }}</h4>
            <p class="hint">{{ __('DeepL API Pro の請求目安です。公式料金が変わったらここを更新してください。') }}</p>
            <div class="deepl-pricing-grid">
              <label>
                {{ __('月額基本料金（EUR）') }}
                <input type="number" name="paid_monthly_base_eur" step="0.01" min="0" value="{{ $deeplPricing['paid_monthly_base_eur'] }}" required />
              </label>
              <label>
                {{ __('100万文字あたり（EUR）') }}
                <input type="number" name="paid_per_million_chars_eur" step="0.01" min="0" value="{{ $deeplPricing['paid_per_million_chars_eur'] }}" required />
              </label>
            </div>
            <p class="hint">{{ __('推定料金 = 月額基本 +（使用文字数 × 単価）。実際の請求は DeepL の請求書を確認してください。') }}</p>
            <button type="submit" class="button-link">{{ __('料金設定を保存') }}</button>
          </form>
        </div>

        <div class="translation-toolbar">
          <button type="button" class="button-link" id="translation-add-btn">{{ __('APIキーを追加') }}</button>
        </div>

        <table class="holiday-table translation-key-table">
          <thead>
            <tr><th>{{ __('識別名') }}</th><th>{{ __('状態') }}</th><th>{{ __('優先') }}</th><th>{{ __('使用量') }}</th><th>{{ __('エラー') }}</th><th>{{ __('操作') }}</th></tr>
          </thead>
          <tbody>
            @if(count($translationKeys) === 0)
              <tr><td colspan="6" class="empty-cell">{{ __('APIキーが登録されていません。「APIキーを追加」から登録してください。') }}</td></tr>
            @endif
            @foreach($translationKeys as $key)
              @php
                $dailyRate = $key->getDailyUsageRate();
                $monthlyRate = $key->getMonthlyUsageRate();
                $summary = $deeplUsageSummaries[$key->id] ?? null;
              @endphp
              <tr>
                <td>
                  <strong>{{ $key->name }}</strong>
                  @if($key->notes)<div class="hint inline-hint">{{ $key->notes }}</div>@endif
                </td>
                <td><span class="holiday-type {{ $key->is_active ? 'national' : '' }}">{{ $key->is_active ? __('有効') : __('無効') }}</span></td>
                <td>{{ $key->priority }}</td>
                <td class="translation-usage-cell">
                  <div>
                    {{ __('日次:') }} {{ number_format($key->current_daily_usage) }}
                    @if($key->daily_limit)
                      / {{ number_format($key->daily_limit) }}
                      @if($dailyRate !== null)
                        <span class="translation-usage-rate {{ $dailyRate > 80 ? 'is-danger' : ($dailyRate > 50 ? 'is-warn' : '') }}">({{ number_format($dailyRate, 1) }}%)</span>
                      @endif
                    @else
                      <span class="hint inline-hint">{{ __('(制限なし)') }}</span>
                    @endif
                  </div>
                  <div>
                    {{ __('月次:') }} {{ number_format($key->current_monthly_usage) }}
                    @if($key->monthly_limit)
                      / {{ number_format($key->monthly_limit) }}
                      @if($monthlyRate !== null)
                        <span class="translation-usage-rate {{ $monthlyRate > 80 ? 'is-danger' : ($monthlyRate > 50 ? 'is-warn' : '') }}">({{ number_format($monthlyRate, 1) }}%)</span>
                      @endif
                    @else
                      <span class="hint inline-hint">{{ __('(制限なし)') }}</span>
                    @endif
                  </div>
                  @if(!empty($summary['estimated_cost']))
                    <div class="hint inline-hint">{{ __('推定:') }} €{{ number_format((float) $summary['estimated_cost'], 2) }}</div>
                  @endif
                  @if(!empty($summary['fetched_at']))
                    <div class="hint inline-hint">DeepL: {{ $summary['fetched_at'] }}</div>
                  @endif
                </td>
                <td>
                  @if($key->error_count > 0)
                    <span class="holiday-type {{ $key->error_count >= 5 ? '' : 'national_ph' }}">{{ $key->error_count }}{{ __('回') }}</span>
                    @if($key->last_error_at)<div class="hint inline-hint">{{ $key->last_error_at->format('Y/m/d H:i') }}</div>@endif
                  @else
                    <span class="hint">-</span>
                  @endif
                </td>
                <td class="translation-key-row-actions">
                  <button type="button" class="mini-btn secondary translation-edit-btn" data-id="{{ $key->id }}">{{ __('編集') }}</button>
                  <form method="post" action="/settings/translation-keys/{{ $key->id }}/reset-usage" class="inline-form" onsubmit='return confirm(@json(__('使用量をリセットしますか？')))'>
                    @csrf
                    <button type="submit" class="mini-btn secondary" title="{{ __('使用量をリセット') }}">↺</button>
                  </form>
                  <form method="post" action="/settings/translation-keys/{{ $key->id }}/delete" class="inline-form" onsubmit='return confirm(@json(__('「:name」を削除しますか？', ['name' => $key->name])))'>
                    @csrf
                    <button type="submit" class="danger mini-btn">{{ __('削除') }}</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @elseif(($section ?? '') === 'integration')
      @include('settings.partials.line-messaging')
      @if(!empty($canSuperAdmin))
        @include('settings.partials.facebook-messenger')
      @endif
      @include('settings.partials.livekit')
      @include('settings.partials.web-push')
      <div class="panel storage-settings" id="google-calendar">
        <h2>{{ __('Googleカレンダー') }}</h2>
        <p class="hint">{{ __('個人の Google カレンダー連携はマイページで行います。スタンダード／ライトユーザーも自分のアカウントを接続できます。') }}</p>
        <div class="storage-form-actions">
          <a class="button-link" href="/mypage#google-calendar">{{ __('マイページで連携する') }}</a>
        </div>
      </div>
      @elseif(($section ?? '') === 'storage')
        @include('settings.partials.storage')
      @elseif(($section ?? '') === 'enhance')
        @include('settings.partials.google-maps')
        @include('settings.partials.route-search')
        @include('settings.partials.google-routes')
        @include('settings.partials.navitime')
        @include('settings.partials.google-calendar-oauth')
      @elseif(($section ?? '') === 'notifications')
      @include('settings.partials.notifications')
      @endif
      @endif
    </main>

    @if(($section ?? '') === 'ai')
    <div class="modal modal-centered" id="translation-key-modal" hidden>
      <div class="modal-backdrop" data-close-translation-modal></div>
      <div class="modal-dialog modal-dialog-wide" role="dialog" aria-labelledby="translation-modal-title">
        <div class="modal-header">
          <h2 id="translation-modal-title">{{ __('APIキーを追加') }}</h2>
          <button type="button" class="modal-close" data-close-translation-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/settings/translation-keys" id="translation-key-form" class="modal-form translation-modal-form">
          @csrf
          <input type="hidden" name="editing_id" id="translation-editing-id" value="" />
          <label>{{ __('識別名') }}<input type="text" name="name" id="translation-name" placeholder="DeepL Key 1" required /></label>
          <label>
            {{ __('APIキー') }}
            <span class="translation-key-input">
              <input type="password" name="api_key" id="translation-api-key-input" placeholder="xxxxxxxx-xxxx-...:fx" autocomplete="off" required />
              <button type="button" class="mini-btn secondary" id="translation-key-toggle" aria-label="{{ __('表示切替') }}">{{ __('表示') }}</button>
            </span>
          </label>
          <label>{{ __('APIエンドポイント（任意）') }}<input type="url" name="api_url" id="translation-api-url" placeholder="{{ __('未入力ならキーから自動判定') }}" /></label>
          <div class="translation-form-grid">
            <label>{{ __('優先順位') }}<input type="number" name="priority" id="translation-priority" value="0" min="0" /></label>
            <label>{{ __('日次制限（文字数）') }}<input type="number" name="daily_limit" id="translation-daily-limit" min="0" placeholder="{{ __('無制限') }}" /></label>
            <label>{{ __('月次制限（文字数）') }}<input type="number" name="monthly_limit" id="translation-monthly-limit" min="0" placeholder="{{ __('無制限') }}" /></label>
            <label>{{ __('日次使用量（文字数）') }}<input type="number" name="current_daily_usage" id="translation-daily-usage" min="0" placeholder="0" /></label>
            <label>{{ __('月次使用量（文字数）') }}<input type="number" name="current_monthly_usage" id="translation-monthly-usage" min="0" placeholder="0" /></label>
          </div>
          <div id="translation-deepl-usage-section" hidden>
            <button type="button" class="secondary" id="translation-fetch-usage-btn">{{ __('DeepLから使用量を取得') }}</button>
            <p class="hint inline-hint">{{ __('DeepL APIから現在の使用量と制限を取得して、上記フィールドに自動入力します。') }}</p>
          </div>
          <label>{{ __('メモ') }}<input type="text" name="notes" id="translation-notes" placeholder="{{ __('用途など') }}" /></label>
          <label class="checkbox-inline"><input type="checkbox" name="is_active" id="translation-is-active" value="1" checked /> {{ __('有効にする') }}</label>
          <label class="checkbox-inline translation-limit-exceeded-row" id="translation-limit-exceeded-row" hidden>
            <input type="checkbox" name="set_limit_exceeded" id="translation-set-limit-exceeded" value="1" />
            {{ __('制限超過を設定（使用量を制限値に設定）') }}
          </label>
          <div class="translation-form-actions">
            <button type="button" class="secondary" id="translation-test-btn">{{ __('接続テスト') }}</button>
            <span class="translation-test-result hint" id="translation-test-result"></span>
            <div class="translation-modal-submit-actions">
              <button type="button" class="secondary" data-close-translation-modal>{{ __('キャンセル') }}</button>
              <button type="submit" id="translation-submit-btn">{{ __('保存') }}</button>
            </div>
          </div>
        </form>
      </div>
    </div>
    @endif

    @if(($section ?? '') === 'ai')
    <script>
      (function () {
        const modal = document.getElementById('translation-key-modal');
        const form = document.getElementById('translation-key-form');
        const modalTitle = document.getElementById('translation-modal-title');
        const editingIdInput = document.getElementById('translation-editing-id');
        const keyInput = document.getElementById('translation-api-key-input');
        const apiUrlInput = document.getElementById('translation-api-url');
        const testBtn = document.getElementById('translation-test-btn');
        const resultEl = document.getElementById('translation-test-result');
        const fetchUsageBtn = document.getElementById('translation-fetch-usage-btn');
        const deeplUsageSection = document.getElementById('translation-deepl-usage-section');
        const limitExceededRow = document.getElementById('translation-limit-exceeded-row');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const deeplApiUrls = {
          free: 'https://api-free.deepl.com/v2/translate',
          paid: 'https://api.deepl.com/v2/translate',
        };

        let editingId = null;

        function getDeepLApiUrl(apiKey) {
          if (!apiKey || apiKey.trim() === '') return '';
          return apiKey.includes(':fx') ? deeplApiUrls.free : deeplApiUrls.paid;
        }

        function updateApiUrlFromKey() {
          const apiKey = keyInput?.value || '';
          const currentUrl = apiUrlInput?.value.trim() || '';
          if (!apiKey.trim()) {
            if (apiUrlInput) apiUrlInput.value = '';
            return;
          }
          const deeplUrl = getDeepLApiUrl(apiKey);
          if (!currentUrl || !currentUrl.includes('deepl.com') ||
              (currentUrl !== deeplApiUrls.free && currentUrl !== deeplApiUrls.paid)) {
            if (apiUrlInput) apiUrlInput.value = deeplUrl;
          }
        }

        function openModal() {
          if (!modal) return
          modal.removeAttribute('hidden')
          document.body.classList.add('has-settings-modal')
        }

        function closeModal() {
          if (!modal) return
          modal.setAttribute('hidden', '')
          document.body.classList.remove('has-settings-modal')
          editingId = null
        }

        function setField(id, value) {
          const el = document.getElementById(id);
          if (el) el.value = value ?? '';
        }

        function openAddModal() {
          editingId = null;
          if (editingIdInput) editingIdInput.value = '';
          if (modalTitle) modalTitle.textContent = @json(__('APIキーを追加'));
          if (form) {
            form.action = '/settings/translation-keys';
            form.reset();
          }
          setField('translation-priority', '0');
          const activeEl = document.getElementById('translation-is-active');
          if (activeEl) activeEl.checked = true;
          if (keyInput) keyInput.required = true;
          if (deeplUsageSection) deeplUsageSection.hidden = true;
          if (limitExceededRow) limitExceededRow.hidden = true;
          if (resultEl) resultEl.textContent = '';
          openModal();
        }

        async function openEditModal(id) {
          editingId = id;
          try {
            const res = await fetch(`/settings/translation-keys/${id}/edit`, {
              headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (!res.ok) {
              window.alert(data.error || @json(__('データの取得に失敗しました')));
              return;
            }

            if (modalTitle) modalTitle.textContent = @json(__('APIキーを編集'));
            if (form) form.action = `/settings/translation-keys/${id}/update`;
            if (editingIdInput) editingIdInput.value = String(id);

            setField('translation-name', data.name);
            setField('translation-api-key-input', '');
            if (keyInput) {
              keyInput.placeholder = data.api_key_masked || '••••••••（変更するときだけ入力）';
              keyInput.required = false;
            }
            setField('translation-api-url', data.api_url || '');
            setField('translation-priority', data.priority ?? 0);
            setField('translation-daily-limit', data.daily_limit ?? '');
            setField('translation-monthly-limit', data.monthly_limit ?? '');
            setField('translation-daily-usage', data.current_daily_usage ?? 0);
            setField('translation-monthly-usage', data.current_monthly_usage ?? 0);
            setField('translation-notes', data.notes ?? '');

            const activeEl = document.getElementById('translation-is-active');
            if (activeEl) activeEl.checked = data.is_active !== false;
            const limitEl = document.getElementById('translation-set-limit-exceeded');
            if (limitEl) limitEl.checked = false;

            if (keyInput) keyInput.required = false;
            if (deeplUsageSection) deeplUsageSection.hidden = false;
            if (limitExceededRow) limitExceededRow.hidden = false;
            if (resultEl) resultEl.textContent = '';
            openModal();
          } catch (e) {
            window.alert(@json(__('データの取得に失敗しました')));
          }
        }

        document.getElementById('translation-add-btn')?.addEventListener('click', (event) => {
          event.preventDefault()
          event.stopPropagation()
          openAddModal()
        })
        document.querySelectorAll('.translation-edit-btn').forEach((btn) => {
          btn.addEventListener('click', (event) => {
            event.preventDefault()
            event.stopPropagation()
            openEditModal(btn.dataset.id)
          })
        })
        document.querySelectorAll('[data-close-translation-modal]').forEach((el) => {
          el.addEventListener('click', (event) => {
            // 背景クリックは背景そのものだけ閉じる（子要素クリックでは閉じない）
            if (el.classList.contains('modal-backdrop') && event.target !== el) return
            closeModal()
          })
        })

        document.getElementById('translation-key-toggle')?.addEventListener('click', () => {
          if (!keyInput) return;
          const show = keyInput.type === 'password';
          keyInput.type = show ? 'text' : 'password';
          const toggleBtn = document.getElementById('translation-key-toggle');
          if (toggleBtn) toggleBtn.textContent = show ? @json(__('隠す')) : @json(__('表示'));
        });

        keyInput?.addEventListener('input', updateApiUrlFromKey);

        testBtn?.addEventListener('click', async () => {
          const apiKey = keyInput?.value.trim();
          if (!apiKey) {
            resultEl.textContent = @json(__('APIキーを入力してください'));
            resultEl.className = 'translation-test-result hint is-error';
            return;
          }
          testBtn.disabled = true;
          resultEl.textContent = @json(__('テスト中...'));
          resultEl.className = 'translation-test-result hint';
          try {
            const res = await fetch('/settings/translation-keys/test', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
              },
              body: JSON.stringify({ api_key: apiKey, api_url: apiUrlInput?.value || '' }),
            });
            const data = await res.json();
            resultEl.textContent = data.ok
              ? `${data.message}（Hello, world! → ${data.translated ?? ''}）`
              : data.message;
            resultEl.className = 'translation-test-result hint ' + (data.ok ? 'is-ok' : 'is-error');
          } catch (e) {
            resultEl.textContent = @json(__('通信エラーが発生しました'));
            resultEl.className = 'translation-test-result hint is-error';
          } finally {
            testBtn.disabled = false;
          }
        });

        fetchUsageBtn?.addEventListener('click', async () => {
          if (!editingId) {
            window.alert(@json(__('編集モードでのみ使用量を取得できます')));
            return;
          }
          fetchUsageBtn.disabled = true;
          const originalText = fetchUsageBtn.textContent;
          fetchUsageBtn.textContent = @json(__('取得中...'));
          try {
            const res = await fetch(`/settings/translation-keys/${editingId}/fetch-usage`, {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
              },
            });
            const data = await res.json();
            if (!data.ok) {
              window.alert(data.message || @json(__('使用量の取得に失敗しました')));
              return;
            }
            setField('translation-monthly-usage', data.character_count ?? 0);
            if (data.character_limit && !document.getElementById('translation-monthly-limit')?.value) {
              setField('translation-monthly-limit', data.character_limit);
            }
            let message = @json(__('使用量を取得しました。')) + `\n\n${@json(__('文字数:'))} ${(data.character_count ?? 0).toLocaleString()}`;
            if (data.character_limit) {
              message += `\n${@json(__('制限:'))} ${data.character_limit.toLocaleString()}`;
            }
            if (data.is_paid_plan && data.estimated_cost !== null) {
              message += `\n\n${@json(__('【料金情報（推定）】'))}`;
              message += `\n${@json(__('月額基本料金'))}: €${(data.monthly_base_fee ?? 0).toFixed(2)}`;
              message += `\n${@json(__('従量課金'))}: €${(data.usage_cost ?? 0).toFixed(4)}`;
              message += `\n${@json(__('合計（推定）'))}: €${data.estimated_cost.toFixed(2)}`;
              message += `\n\n${@json(__('※ 実際の請求額はDeepLの請求書を確認してください。'))}`;
            }
            window.alert(message);
          } catch (e) {
            window.alert(@json(__('使用量の取得に失敗しました')));
          } finally {
            fetchUsageBtn.disabled = false;
            fetchUsageBtn.textContent = originalText;
          }
        });

        const llmTestBtn = document.getElementById('ai-llm-test-btn');
        const llmTestLive = document.getElementById('ai-llm-test-live');
        const llmProviderSelect = document.getElementById('ai-llm-active-provider');
        const llmStrings = {
          testing: @json(__('テスト中...')),
          success: @json(__('成功')),
          failed: @json(__('失敗')),
          networkError: @json(__('通信エラーが発生しました')),
        };
        const bindModelRefresh = (btn) => {
          const select = document.querySelector(btn.dataset.select || '');
          const live = document.querySelector(btn.dataset.live || '');
          btn.addEventListener('click', async () => {
            btn.disabled = true;
            if (live) {
              live.textContent = llmStrings.testing;
              live.classList.remove('is-ok', 'is-fail');
            }
            try {
              const res = await fetch(btn.dataset.url || '', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-TOKEN': csrf,
                  Accept: 'application/json',
                },
                body: JSON.stringify({
                  provider: btn.dataset.provider || '',
                }),
              });
              const data = await res.json().catch(() => ({}));
              if (select && Array.isArray(data.models)) {
                const current = select.value;
                const ids = data.models.slice();
                if (current && !ids.includes(current)) {
                  ids.unshift(current);
                }
                select.innerHTML = '';
                ids.forEach((id) => {
                  const option = document.createElement('option');
                  option.value = id;
                  option.textContent = id;
                  if (id === current) option.selected = true;
                  select.appendChild(option);
                });
              }
              if (live) {
                const fetched = data.fetched_at ? ` (${data.fetched_at})` : '';
                live.textContent = (data.message || '') + fetched;
                live.classList.toggle('is-ok', Boolean(data.ok));
                live.classList.toggle('is-fail', !data.ok);
              }
            } catch (_) {
              if (live) {
                live.textContent = llmStrings.networkError;
                live.classList.add('is-fail');
              }
            } finally {
              btn.disabled = false;
            }
          });
        };
        document.querySelectorAll('.ai-model-refresh').forEach((btn) => bindModelRefresh(btn));
        llmTestBtn?.addEventListener('click', async () => {
          llmTestBtn.disabled = true;
          if (llmTestLive) llmTestLive.textContent = llmStrings.testing;
          try {
            const res = await fetch('/settings/ai/llm/test', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
              },
              body: JSON.stringify({
                provider: llmProviderSelect?.value || '',
              }),
            });
            const data = await res.json().catch(() => ({}));
            if (llmTestLive) {
              llmTestLive.textContent = data.message || (data.ok ? llmStrings.success : llmStrings.failed);
              llmTestLive.classList.toggle('is-ok', Boolean(data.ok));
              llmTestLive.classList.toggle('is-fail', !data.ok);
            }
          } catch (_) {
            if (llmTestLive) {
              llmTestLive.textContent = llmStrings.networkError;
              llmTestLive.classList.add('is-fail');
            }
          } finally {
            llmTestBtn.disabled = false;
          }
        });

        const waiTestBtn = document.getElementById('workers-ai-test-btn');
        const waiTestLive = document.getElementById('workers-ai-test-live');
        waiTestBtn?.addEventListener('click', async () => {
          waiTestBtn.disabled = true;
          if (waiTestLive) waiTestLive.textContent = llmStrings.testing;
          try {
            const res = await fetch('/settings/ai/workers-ai/test', {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
              },
            });
            const data = await res.json().catch(() => ({}));
            if (waiTestLive) {
              waiTestLive.textContent = data.message || (data.ok ? llmStrings.success : llmStrings.failed);
              waiTestLive.classList.toggle('is-ok', Boolean(data.ok));
              waiTestLive.classList.toggle('is-fail', !data.ok);
            }
          } catch (_) {
            if (waiTestLive) {
              waiTestLive.textContent = llmStrings.networkError;
              waiTestLive.classList.add('is-fail');
            }
          } finally {
            waiTestBtn.disabled = false;
          }
        });

        const ytTestBtn = document.getElementById('youtube-api-test-btn');
        const ytTestLive = document.getElementById('youtube-api-test-live');
        const ytStrings = {
          testing: @json(__('テスト中...')),
          networkError: @json(__('通信エラーが発生しました')),
        };
        ytTestBtn?.addEventListener('click', async () => {
          ytTestBtn.disabled = true;
          if (ytTestLive) ytTestLive.textContent = ytStrings.testing;
          try {
            const res = await fetch('/settings/ai/youtube/test', {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
              },
            });
            const data = await res.json().catch(() => ({}));
            if (ytTestLive) {
              ytTestLive.textContent = data.message || '';
              ytTestLive.classList.toggle('is-ok', Boolean(data.ok));
              ytTestLive.classList.toggle('is-fail', !data.ok);
            }
          } catch (_) {
            if (ytTestLive) {
              ytTestLive.textContent = ytStrings.networkError;
              ytTestLive.classList.add('is-fail');
            }
          } finally {
            ytTestBtn.disabled = false;
          }
        });
      })();
    </script>
    @endif
  </body>
</html>
