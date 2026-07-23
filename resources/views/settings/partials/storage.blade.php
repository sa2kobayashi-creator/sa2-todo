{{-- 写真・動画ストレージ設定 --}}
@php
  $r2 = $storageR2 ?? [];
  $cloudinary = $storageCloudinary ?? [];
  $backblaze = $storageBackblaze ?? [];
  $pipeline = $storagePipeline ?? [];
  $r2Settings = $r2['settings'] ?? [];
  $r2Env = $r2['envFallback'] ?? [];
  $cSettings = $cloudinary['settings'] ?? [];
  $bSettings = $backblaze['settings'] ?? [];
  $pSettings = $pipeline['settings'] ?? [];
@endphp

<div class="panel storage-settings" id="storage-pipeline">
  <h2>{{ __('保存パイプライン') }}</h2>
  <p class="hint">{{ __('アップロード → Cloudflare R2（原本）→ 必要に応じて Stability AI で鮮明化して R2 へ保存。Cloudinary は編集用、古い原本は Backblaze B2 へ、という流れをここで切り替えます。') }}</p>
  <form method="post" action="/settings/storage/pipeline" class="storage-provider-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($pipeline['enabled'])) />
      {{ __('パイプライン設定を有効にする') }}
    </label>
    <label>{{ __('原本の保存先') }}
      <select name="primary_disk">
        <option value="r2" @selected(($pSettings['primary_disk'] ?? 'r2') === 'r2')>Cloudflare R2</option>
        <option value="public" @selected(($pSettings['primary_disk'] ?? '') === 'public')>{{ __('サーバーローカル (public)') }}</option>
      </select>
    </label>
    <label class="storage-enable">
      <input type="checkbox" name="use_cloudinary_display" value="1" @checked(!empty($pSettings['use_cloudinary_display'])) />
      {{ __('一覧表示にも Cloudinary を使う（非推奨・Cloudinary に常設コピーが増えます）') }}
    </label>
    <p class="hint">{{ __('推奨: Cloudinary はオフのまま。Photos の「Cloudinaryで編集」だけが一時アップロード→編集→R2保存→削除します。') }}</p>
    <label class="storage-enable">
      <input type="checkbox" name="archive_to_backblaze" value="1" @checked(!empty($pSettings['archive_to_backblaze'])) />
      {{ __('古い写真・動画を Backblaze B2 へ自動アーカイブ') }}
    </label>
    <label>{{ __('アーカイブ対象（登録日から何日後）') }}
      <input type="number" name="archive_after_days" min="0" value="{{ (int) ($pSettings['archive_after_days'] ?? 365) }}" />
    </label>
    <p class="hint">{{ __('毎日 3:30 に自動実行します。手動実行: php artisan photos:archive-cold') }}</p>
    <button type="submit" class="button-link">{{ __('パイプラインを保存') }}</button>
  </form>
</div>

<div class="panel storage-settings" id="storage-r2">
  <h2>Cloudflare R2</h2>
  <p class="hint">{{ __('写真・動画の原本保存先です。空欄のシークレットは既存値を維持します。.env の R2_* がある場合はフォールバックします。') }}</p>
  @if(!empty($r2['last_test_message']))
    <p class="hint storage-test-result {{ ($r2['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ ($r2['last_tested_at'] ?? '') }} — {{ $r2['last_test_message'] }}
    </p>
  @endif
  <form method="post" action="/settings/storage/r2" class="storage-provider-form" data-provider="r2">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($r2['enabled'])) />
      {{ __('設定メニューの R2 認証情報を優先する') }}
    </label>
    <label>{{ __('Access Key ID') }}
      <input type="password" name="access_key_id" autocomplete="off" placeholder="{{ !empty($r2['hasSecrets']['access_key_id']) || ($r2Env['access_key_id'] ?? '') !== '' ? '••••••••' : '' }}" />
    </label>
    <label>{{ __('Secret Access Key') }}
      <input type="password" name="secret_access_key" autocomplete="off" placeholder="{{ !empty($r2['hasSecrets']['secret_access_key']) || ($r2Env['secret_access_key'] ?? '') !== '' ? '••••••••' : '' }}" />
    </label>
    <label>Bucket
      <input type="text" name="bucket" value="{{ $r2Settings['bucket'] ?? ($r2Env['bucket'] ?? '') }}" />
    </label>
    <label>Endpoint
      <input type="url" name="endpoint" value="{{ $r2Settings['endpoint'] ?? ($r2Env['endpoint'] ?? '') }}" placeholder="https://xxxx.r2.cloudflarestorage.com" />
    </label>
    <label>Public URL
      <input type="url" name="url" value="{{ $r2Settings['url'] ?? ($r2Env['url'] ?? '') }}" placeholder="https://pub-xxxx.r2.dev" />
    </label>
    <label>Region
      <input type="text" name="region" value="{{ $r2Settings['region'] ?? ($r2Env['region'] ?? 'auto') }}" />
    </label>
    <label class="storage-enable">
      <input type="checkbox" name="use_path_style_endpoint" value="1" @checked(($r2Settings['use_path_style_endpoint'] ?? $r2Env['use_path_style_endpoint'] ?? true)) />
      use_path_style_endpoint
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary storage-test-btn" data-provider="r2">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" data-test-live="r2"></span>
    </div>
  </form>
</div>

<div class="panel storage-settings" id="storage-cloudinary">
  <h2>Cloudinary</h2>
  <p class="hint">{{ __('画像編集（Media Editor）専用です。編集開始時だけ一時アップロードし、保存後に Cloudinary から削除します。') }}</p>
  @if(!empty($cloudinary['last_test_message']))
    <p class="hint storage-test-result {{ ($cloudinary['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ ($cloudinary['last_tested_at'] ?? '') }} — {{ $cloudinary['last_test_message'] }}
    </p>
  @endif
  <form method="post" action="/settings/storage/cloudinary" class="storage-provider-form" data-provider="cloudinary">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($cloudinary['enabled'])) />
      {{ __('Cloudinary を有効にする') }}
    </label>
    <label>Cloud name
      <input type="text" name="cloud_name" value="{{ $cSettings['cloud_name'] ?? '' }}" autocomplete="off" />
    </label>
    <label>API Key
      <input type="password" name="api_key" autocomplete="off" placeholder="{{ !empty($cloudinary['hasSecrets']['api_key']) ? '••••••••' : '' }}" />
    </label>
    <label>API Secret
      <input type="password" name="api_secret" autocomplete="off" placeholder="{{ !empty($cloudinary['hasSecrets']['api_secret']) ? '••••••••' : '' }}" />
    </label>
    <label>{{ __('フォルダ') }}
      <input type="text" name="folder" value="{{ $cSettings['folder'] ?? 'sa2todo' }}" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary storage-test-btn" data-provider="cloudinary">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" data-test-live="cloudinary"></span>
    </div>
  </form>
</div>

<div class="panel storage-settings" id="storage-stability">
  <h2>Stability AI</h2>
  <p class="hint">{{ __('写真の AI 鮮明化（Upscale）に使います。Photos で写真を選び「AIで鮮明化」すると、解像度を上げた結果を Cloudflare R2（原本ディスク）へ新規保存します。元画像はそのまま残ります。API は1回あたり最大約1MPのため、大きな写真はタイル分割で処理し、アップスケール解像度のまま合成します。') }}</p>
  @php $stability = $storageStability ?? []; $sSettings = $stability['settings'] ?? []; @endphp
  @if(!empty($stability['last_test_message']))
    <p class="hint storage-test-result {{ ($stability['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ ($stability['last_tested_at'] ?? '') }} — {{ $stability['last_test_message'] }}
    </p>
  @endif
  <form method="post" action="/settings/storage/stability" class="storage-provider-form" data-provider="stability">
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
        <option value="fast" @selected(($sSettings['mode'] ?? 'fast') === 'fast')>{{ __('Fast（高速・推奨）') }}</option>
        <option value="conservative" @selected(($sSettings['mode'] ?? '') === 'conservative')>{{ __('Conservative（高精細・要クレジット多）') }}</option>
      </select>
    </label>
    <label>{{ __('出力形式') }}
      <select name="output_format">
        <option value="jpeg" @selected(($sSettings['output_format'] ?? 'jpeg') === 'jpeg')>JPEG</option>
        <option value="png" @selected(($sSettings['output_format'] ?? '') === 'png')>PNG</option>
        <option value="webp" @selected(($sSettings['output_format'] ?? '') === 'webp')>WebP</option>
      </select>
    </label>
    <label>{{ __('Conservative 用プロンプト') }}
      <input type="text" name="default_prompt" value="{{ $sSettings['default_prompt'] ?? 'high quality clear photograph, sharp details, natural colors' }}" />
    </label>
    <p class="hint">{{ __('API Key は platform.stability.ai で取得したキーを入力してください。空欄のまま保存すると既存キーを維持します。') }}</p>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary storage-test-btn" data-provider="stability">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" data-test-live="stability"></span>
    </div>
  </form>
</div>

<div class="panel storage-settings" id="storage-backblaze">
  <h2>Backblaze B2</h2>
  <p class="hint">{{ __('長期保存用です。S3 互換の Endpoint（例: https://s3.us-west-004.backblazeb2.com）を入力してください。パイプラインで自動アーカイブを有効にすると、指定日数を過ぎた原本をここへ移動します（サムネは高速表示のためホット側に残します）。') }}</p>
  @if(!empty($backblaze['last_test_message']))
    <p class="hint storage-test-result {{ ($backblaze['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ ($backblaze['last_tested_at'] ?? '') }} — {{ $backblaze['last_test_message'] }}
    </p>
  @endif
  <form method="post" action="/settings/storage/backblaze" class="storage-provider-form" data-provider="backblaze">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($backblaze['enabled'])) />
      {{ __('Backblaze B2 を有効にする') }}
    </label>
    <label>Key ID
      <input type="password" name="key_id" autocomplete="off" placeholder="{{ !empty($backblaze['hasSecrets']['key_id']) ? '••••••••' : '' }}" />
    </label>
    <label>Application Key
      <input type="password" name="application_key" autocomplete="off" placeholder="{{ !empty($backblaze['hasSecrets']['application_key']) ? '••••••••' : '' }}" />
    </label>
    <label>Bucket
      <input type="text" name="bucket" value="{{ $bSettings['bucket'] ?? '' }}" />
    </label>
    <label>Endpoint
      <input type="url" name="endpoint" value="{{ $bSettings['endpoint'] ?? '' }}" placeholder="https://s3.us-west-004.backblazeb2.com" />
    </label>
    <label>Region
      <input type="text" name="region" value="{{ $bSettings['region'] ?? 'us-west-004' }}" />
    </label>
    <label>Public URL <span class="hint inline-hint">{{ __('任意') }}</span>
      <input type="url" name="url" value="{{ $bSettings['url'] ?? '' }}" />
    </label>
    <label class="storage-enable">
      <input type="checkbox" name="use_path_style_endpoint" value="1" @checked(($bSettings['use_path_style_endpoint'] ?? true)) />
      use_path_style_endpoint
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary storage-test-btn" data-provider="backblaze">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" data-test-live="backblaze"></span>
    </div>
  </form>
</div>

<script>
  (function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    document.querySelectorAll('.storage-test-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const provider = btn.dataset.provider;
        const live = document.querySelector(`[data-test-live="${provider}"]`);
        btn.disabled = true;
        if (live) live.textContent = @json(__('テスト中...'));
        try {
          const res = await fetch(`/settings/storage/${provider}/test`, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrf,
            },
          });
          const data = await res.json();
          if (live) {
            live.textContent = data.message || (data.ok ? @json(__('成功')) : @json(__('失敗')));
            live.classList.toggle('is-ok', !!data.ok);
            live.classList.toggle('is-fail', !data.ok);
          }
        } catch (e) {
          if (live) {
            live.textContent = @json(__('接続テストに失敗しました'));
            live.classList.add('is-fail');
          }
        } finally {
          btn.disabled = false;
        }
      });
    });
  })();
</script>
