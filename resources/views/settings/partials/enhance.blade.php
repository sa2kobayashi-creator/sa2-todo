@php
  $enhance = $enhanceSettings ?? [];
  $providers = $enhance['providers'] ?? [];
  $active = $enhance['active_provider'] ?? 'stability';
  $stability = $providers['stability'] ?? [];
  $realesrgan = $providers['realesrgan'] ?? [];
  $swinir = $providers['swinir'] ?? [];
  $sSettings = $stability['settings'] ?? [];
  $eSettings = $realesrgan['settings'] ?? [];
  $wSettings = $swinir['settings'] ?? [];
  $defaultBinary = storage_path('app/bin/realesrgan-ncnn-vulkan.exe');
  $realesrganPaused = !empty($realesrgan['temporarily_disabled']);
  $swinirPaused = !empty($swinir['temporarily_disabled']);
@endphp

<div class="panel storage-settings" id="enhance-active">
  <h2>{{ __('鮮明化設定') }}</h2>
  <p class="hint">{{ __('Photos の「AIで鮮明化」で使うエンジンを選びます。Stability AI / Real-ESRGAN（ローカル GPU）/ SwinIR（GPU VPS）を切り替えられます。') }}</p>
  <p class="hint">{{ __('Real-ESRGAN と SwinIR は当面利用停止中です（実装は残しています）。') }}</p>
  <form method="post" action="/settings/enhance/active" class="storage-provider-form">
    @csrf
    <label>
      {{ __('使用するエンジン') }}
      <select name="active_provider">
        <option value="stability" @selected($active === 'stability')>Stability AI</option>
        <option value="realesrgan" @selected($active === 'realesrgan') @disabled($realesrganPaused)>
          Real-ESRGAN（ローカル GPU）@if($realesrganPaused) — {{ __('当面利用停止中') }}@endif
        </option>
        <option value="swinir" @selected($active === 'swinir') @disabled($swinirPaused)>
          SwinIR（GPU VPS）@if($swinirPaused) — {{ __('当面利用停止中') }}@endif
        </option>
      </select>
    </label>
    <p class="hint">
      @if(!empty($enhance['ready']))
        {{ __('現在のエンジンは利用可能です。') }}
      @else
        {{ __('選択中のエンジンが未設定です。下の各プロバイダを設定してください。') }}
      @endif
    </p>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('使用エンジンを保存') }}</button>
    </div>
  </form>
</div>

<div class="panel storage-settings" id="enhance-stability">
  <h2>Stability AI</h2>
  <p class="hint">{{ __('写真の AI 鮮明化（Upscale）に使います。Photos で写真を選び「AIで鮮明化」すると、解像度を上げた結果を Cloudflare R2（原本ディスク）へ新規保存します。元画像はそのまま残ります。API は1回あたり最大約1MPのため、大きな写真はタイル分割で処理し、アップスケール解像度のまま合成します。') }}</p>
  @if(!empty($stability['last_test_message']))
    <p class="hint storage-test-result {{ ($stability['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ ($stability['last_tested_at'] ?? '') }} — {{ $stability['last_test_message'] }}
    </p>
  @endif
  <form method="post" action="/settings/enhance/stability" class="storage-provider-form" data-provider="stability">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($stability['enabled'])) />
      {{ __('Stability AI を有効にする') }}
    </label>
    <label>API Key
      <input type="password" name="api_key" autocomplete="off" placeholder="{{ !empty($stability['hasSecrets']['api_key']) ? '••••••••' : 'sk-...' }}" />
    </label>
    <label>{{ __('鮮明化モード') }}
      <select name="mode">
        <option value="fast" @selected(($sSettings['mode'] ?? '') === 'fast')>{{ __('Fast（高速・差は拡大で確認）') }}</option>
        <option value="conservative" @selected(($sSettings['mode'] ?? 'conservative') === 'conservative')>{{ __('Conservative（顔・人物を保つ・推奨）') }}</option>
        <option value="creative" @selected(($sSettings['mode'] ?? '') === 'creative')>{{ __('Creative（見た目は綺麗だが別人になりやすい）') }}</option>
      </select>
    </label>
    <label>{{ __('出力形式') }}
      <select name="output_format">
        <option value="jpeg" @selected(($sSettings['output_format'] ?? 'jpeg') === 'jpeg')>JPEG</option>
        <option value="png" @selected(($sSettings['output_format'] ?? '') === 'png')>PNG</option>
        <option value="webp" @selected(($sSettings['output_format'] ?? '') === 'webp')>WebP</option>
      </select>
    </label>
    <label>{{ __('Conservative / Creative 用プロンプト') }}
      <input type="text" name="default_prompt" value="{{ $sSettings['default_prompt'] ?? 'same people, preserve exact faces and identity, natural photo, mild sharpen, no face swap' }}" />
    </label>
    <p class="hint">{{ __('Creative はディテールを作り直すため別人に見えることがあります。人物写真は Conservative を選んでください。') }}</p>
    <p class="hint">{{ __('API Key は platform.stability.ai で取得したキーを入力してください。空欄のまま保存すると既存キーを維持します。') }}</p>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary enhance-test-btn" data-provider="stability">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" data-test-live="stability"></span>
    </div>
  </form>
</div>

<div class="panel storage-settings" id="enhance-realesrgan">
  <h2>Real-ESRGAN（ローカル GPU）</h2>
  <p class="hint">{{ __('無料・高速のローカル超解像です。realesrgan-ncnn-vulkan を GPU 付きマシンに置き、Laravel から実行して結果を R2 へ保存します。API キーは不要です。') }}</p>
  <p class="hint">
    {{ __('Windows 用 zip（直接ダウンロード）:') }}
    <a href="https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesrgan-ncnn-vulkan-20220424-windows.zip" target="_blank" rel="noopener noreferrer">realesrgan-ncnn-vulkan-20220424-windows.zip</a>
  </p>
  <p class="hint">
    {{ __('場所:') }}
    <a href="https://github.com/xinntao/Real-ESRGAN/releases/tag/v0.2.5.0" target="_blank" rel="noopener noreferrer">Real-ESRGAN v0.2.5.0</a>
    {{ __('のページ下部 Assets（最新 v0.3.0 には含まれていません）') }}
  </p>
  <p class="hint">{{ __('推奨配置:') }} <code>{{ $defaultBinary }}</code></p>
  @if(!empty($realesrgan['last_test_message']))
    <p class="hint storage-test-result {{ ($realesrgan['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ ($realesrgan['last_tested_at'] ?? '') }} — {{ $realesrgan['last_test_message'] }}
    </p>
  @endif
  <form method="post" action="/settings/enhance/realesrgan" class="storage-provider-form" data-provider="realesrgan">
    @csrf
    @if($realesrganPaused)
      <p class="hint">{{ __('当面利用停止中です。設定の保存はできますが、有効化・実行はできません。') }}</p>
    @endif
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($realesrgan['enabled'])) @disabled($realesrganPaused) />
      {{ __('Real-ESRGAN を有効にする') }}
    </label>
    <label>{{ __('実行ファイルのパス') }}
      <input type="text" name="binary_path" value="{{ $eSettings['binary_path'] ?? '' }}" placeholder="{{ $defaultBinary }}" />
    </label>
    <label>{{ __('モデル') }}
      <select name="model">
        <option value="realesrgan-x4plus" @selected(($eSettings['model'] ?? 'realesrgan-x4plus') === 'realesrgan-x4plus')>realesrgan-x4plus（写真向け）</option>
        <option value="realesrgan-x4plus-anime" @selected(($eSettings['model'] ?? '') === 'realesrgan-x4plus-anime')>realesrgan-x4plus-anime</option>
        <option value="realesrnet-x4plus" @selected(($eSettings['model'] ?? '') === 'realesrnet-x4plus')>realesrnet-x4plus</option>
        <option value="realesr-animevideov3" @selected(($eSettings['model'] ?? '') === 'realesr-animevideov3')>realesr-animevideov3</option>
      </select>
    </label>
    <label>{{ __('倍率') }}
      <select name="scale">
        <option value="2" @selected((int) ($eSettings['scale'] ?? 2) === 2)>2x（{{ __('推奨・低VRAM') }}）</option>
        <option value="3" @selected((int) ($eSettings['scale'] ?? 2) === 3)>3x</option>
        <option value="4" @selected((int) ($eSettings['scale'] ?? 2) === 4)>4x</option>
      </select>
    </label>
    <label>{{ __('GPU ID') }}
      <input type="text" name="gpu_id" value="{{ $eSettings['gpu_id'] ?? '0' }}" placeholder="0" />
    </label>
    <label>{{ __('タイルサイズ') }}
      <input type="number" name="tile_size" min="0" step="32" value="{{ (int) ($eSettings['tile_size'] ?? 64) }}" />
    </label>
    <p class="hint">{{ __('AMD 内蔵GPU は タイル 64・倍率 2x を推奨。失敗時は入力縮小＋タイル 128→64→32、必要なら倍率 2x へ自動再試行します。') }}</p>
    <label>{{ __('出力形式') }}
      <select name="output_format">
        <option value="png" @selected(($eSettings['output_format'] ?? 'png') === 'png')>PNG</option>
        <option value="jpg" @selected(($eSettings['output_format'] ?? '') === 'jpg')>JPEG</option>
        <option value="webp" @selected(($eSettings['output_format'] ?? '') === 'webp')>WebP</option>
      </select>
    </label>
    <label>{{ __('タイムアウト（秒）') }}
      <input type="number" name="timeout_seconds" min="30" max="3600" value="{{ (int) ($eSettings['timeout_seconds'] ?? 600) }}" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary enhance-test-btn" data-provider="realesrgan" @disabled($realesrganPaused)>{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" data-test-live="realesrgan"></span>
    </div>
  </form>
</div>

<div class="panel storage-settings" id="enhance-swinir">
  <h2>SwinIR（GPU VPS）</h2>
  <p class="hint">{{ __('高品質な無料超解像です。GPU 付き VPS に SwinIR ワーカーを起動し、Laravel から HTTP で呼び出して結果を R2 へ保存します。') }}</p>
  <p class="hint">
    {{ __('公式リポジトリ:') }}
    <a href="https://github.com/JingyunLiang/SwinIR" target="_blank" rel="noopener noreferrer">JingyunLiang/SwinIR</a>
    · {{ __('本リポジトリのワーカー:') }} <code>tools/swinir-worker/</code>
  </p>
  @if(!empty($swinir['last_test_message']))
    <p class="hint storage-test-result {{ ($swinir['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ ($swinir['last_tested_at'] ?? '') }} — {{ $swinir['last_test_message'] }}
    </p>
  @endif
  <form method="post" action="/settings/enhance/swinir" class="storage-provider-form" data-provider="swinir">
    @csrf
    @if($swinirPaused)
      <p class="hint">{{ __('当面利用停止中です。設定の保存はできますが、有効化・実行はできません。') }}</p>
    @endif
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($swinir['enabled'])) @disabled($swinirPaused) />
      {{ __('SwinIR を有効にする') }}
    </label>
    <label>{{ __('エンドポイント URL') }}
      <input type="url" name="endpoint" value="{{ $wSettings['endpoint'] ?? '' }}" placeholder="http://gpu-vps.example.com:8000" />
    </label>
    <label>API Key <span class="hint inline-hint">{{ __('VPS の SWINIR_API_KEY と同じ値') }}</span>
      <input type="password" name="api_key" autocomplete="off" placeholder="{{ !empty($swinir['hasSecrets']['api_key']) ? '••••••••' : '' }}" />
    </label>
    <label class="storage-enable">
      <input type="checkbox" name="large_model" value="1" @checked(!empty($wSettings['large_model'])) />
      {{ __('Large モデル（高品質・VRAM 多め）') }}
    </label>
    <label>{{ __('タイルサイズ') }}
      <input type="number" name="tile" min="0" step="8" value="{{ (int) ($wSettings['tile'] ?? 400) }}" />
    </label>
    <p class="hint">{{ __('VRAM 不足時は 400 や 256。0 はタイルなし（大きな画像では OOM しやすい）。公式 real_sr は x4 固定です。') }}</p>
    <label>{{ __('出力形式') }}
      <select name="output_format">
        <option value="png" @selected(($wSettings['output_format'] ?? 'png') === 'png')>PNG</option>
        <option value="jpg" @selected(($wSettings['output_format'] ?? '') === 'jpg')>JPEG</option>
        <option value="webp" @selected(($wSettings['output_format'] ?? '') === 'webp')>WebP</option>
      </select>
    </label>
    <label>{{ __('タイムアウト（秒）') }}
      <input type="number" name="timeout_seconds" min="30" max="3600" value="{{ (int) ($wSettings['timeout_seconds'] ?? 600) }}" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary enhance-test-btn" data-provider="swinir" @disabled($swinirPaused)>{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" data-test-live="swinir"></span>
    </div>
  </form>
</div>

<script>
  (function () {
    const enhanceStrings = {
      testing: @json(__('テスト中...')),
      success: @json(__('成功')),
      failed: @json(__('失敗')),
      networkError: @json(__('接続テストに失敗しました')),
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    document.querySelectorAll('.enhance-test-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const provider = btn.dataset.provider;
        const live = document.querySelector(`[data-test-live="${provider}"]`);
        btn.disabled = true;
        if (live) live.textContent = enhanceStrings.testing;
        try {
          const res = await fetch(`/settings/enhance/${provider}/test`, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrf,
            },
          });
          const data = await res.json().catch(() => ({}));
          if (live) {
            live.textContent = data.message || (data.ok ? enhanceStrings.success : enhanceStrings.failed);
            live.classList.toggle('is-ok', !!data.ok);
            live.classList.toggle('is-fail', !data.ok);
          }
        } catch (e) {
          if (live) {
            live.textContent = enhanceStrings.networkError;
            live.classList.add('is-fail');
          }
        } finally {
          btn.disabled = false;
        }
      });
    });
  })();
</script>
