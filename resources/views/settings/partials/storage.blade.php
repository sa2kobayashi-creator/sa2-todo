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
  <p class="hint">{{ __('アップロード → Cloudflare R2（原本）。Cloudinary は編集用、古い原本は Backblaze B2 へ、という流れをここで切り替えます。AI鮮明化は「API設定」で行います。') }}</p>
  <form method="post" action="/settings/storage/pipeline" class="storage-provider-form" id="storage-pipeline-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($pipeline['enabled'])) />
      {{ __('パイプライン設定を有効にする') }}
    </label>
    <label class="storage-enable">
      <input type="checkbox" name="allow_paid_overage" value="1" @checked(!empty($pSettings['allow_paid_overage'])) />
      {{ __('有料枠（無料枠超過）を許可する') }}
    </label>
    <p class="hint">{{ __('オンにすると、製品無料枠（合計20GB）を超えても写真・動画を追加できます。実課金ではなく、運営が超過利用を認めるスイッチです。') }}</p>
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

    @php
      $capacityMode = $pSettings['capacity_mode'] ?? (
        !empty($pSettings['archive_to_backblaze'])
          ? \App\Services\MediaStorageConfigService::CAPACITY_MODE_AGE_ARCHIVE
          : \App\Services\MediaStorageConfigService::CAPACITY_MODE_AGE_ARCHIVE
      );
    @endphp
    <label>{{ __('容量の運用モード') }}
      <select name="capacity_mode" id="storage-capacity-mode">
        <option value="r2_cap" @selected($capacityMode === 'r2_cap')>
          {{ __('1. R2は無料枠（10GB）までしか使わない') }}
        </option>
        <option value="age_archive" @selected($capacityMode === 'age_archive')>
          {{ __('2. 日数経過でB2へアーカイブ（現行）') }}
        </option>
        <option value="overflow_priority" @selected($capacityMode === 'overflow_priority')>
          {{ __('3. R2/B2とも無料枠超過後は次の保存先へ') }}
        </option>
      </select>
    </label>
    <p class="hint" data-capacity-hint="r2_cap" @if($capacityMode !== 'r2_cap') hidden @endif>
      {{ __('R2（ホット）が約10GBを超えないよう、古い原本を自動で B2 へ移します。一覧用サムネは R2 に残します。') }}
    </p>
    <p class="hint" data-capacity-hint="age_archive" @if($capacityMode !== 'age_archive') hidden @endif>
      {{ __('登録日（なければ作成日）から指定日数を過ぎた原本を B2 へ移します。R2 が10GBを超えても、日数条件を満たすまで移動しません。') }}
    </p>
    <p class="hint" data-capacity-hint="overflow_priority" @if($capacityMode !== 'overflow_priority') hidden @endif>
      {{ __('まず R2 無料枠 → 次に B2 無料枠へ収容し、どちらも超えた新規保存は下の「次の保存先」を使います。超過分の料金見込に保存料を反映します。') }}
    </p>

    <div data-capacity-panel="age_archive" @if($capacityMode !== 'age_archive') hidden @endif>
      <p class="hint">{{ __('毎日 3:30 に自動実行します。手動実行: php artisan photos:archive-cold') }}</p>
      <p class="hint">{{ __('Photosの「B2へアーカイブ」はバックグラウンド実行です（他画面へ移っても継続）。毎分の tick と毎日 3:30 の自動実行があります。調整: PHOTO_ARCHIVE_COLD_BATCH_SECONDS・PHOTO_ARCHIVE_COLD_BATCH_SIZE・PHOTO_ARCHIVE_COLD_LARGE_FILE_BYTES') }}</p>
    </div>

    <div data-capacity-panel="r2_cap" @if($capacityMode !== 'r2_cap') hidden @endif>
      <p class="hint">{{ __('毎日 3:30 に古い原本を B2 へ移します。アップロード中の同期移動は既定オフ（保存速度優先）。必要なら PHOTO_UPLOAD_SYNC_ARCHIVE_LIMIT で調整できます。') }}</p>
      <p class="hint">{{ __('Photosの「B2へアーカイブ」はバックグラウンド実行です（他画面へ移っても継続）。毎分の tick と毎日 3:30 の自動実行があります。調整: PHOTO_ARCHIVE_COLD_BATCH_SECONDS・PHOTO_ARCHIVE_COLD_BATCH_SIZE・PHOTO_ARCHIVE_COLD_LARGE_FILE_BYTES') }}</p>
    </div>

    <div data-capacity-panel="overflow_priority" @if($capacityMode !== 'overflow_priority') hidden @endif>
      <label>{{ __('次の保存先（R2/B2とも無料枠超過後）') }}
        <select name="overflow_disk">
          <option value="public" @selected(($pSettings['overflow_disk'] ?? 'public') === 'public')>{{ __('サーバーローカル (public)') }}</option>
          <option value="r2" @selected(($pSettings['overflow_disk'] ?? '') === 'r2')>Cloudflare R2（{{ __('有料継続') }}）</option>
          <option value="backblaze" @selected(($pSettings['overflow_disk'] ?? '') === 'backblaze')>Backblaze B2（{{ __('有料継続') }}）</option>
        </select>
      </label>
      <label>{{ __('次の保存先の保存料（USD / GB / 月）') }}
        <input type="number" name="overflow_price_per_gb_month_usd" min="0" step="0.001" value="{{ number_format((float) ($pSettings['overflow_price_per_gb_month_usd'] ?? 0.015), 3, '.', '') }}" />
      </label>
      <p class="hint">{{ __('使用状況の「超過課金見込」に反映する単価です。サーバーローカルなら 0 を推奨。') }}</p>
    </div>

    <div data-capacity-panel="age_or_overflow" @if(!in_array($capacityMode, ['age_archive', 'overflow_priority'], true)) hidden @endif>
      <label>{{ __('B2へ移す目安日数（登録日から何日後）') }}
        <input type="number" name="archive_after_days" min="0" value="{{ (int) ($pSettings['archive_after_days'] ?? 365) }}" />
      </label>
      <p class="hint" data-capacity-hint-days="overflow_priority" @if($capacityMode !== 'overflow_priority') hidden @endif>
        {{ __('0 にすると日数アーカイブは行わず、容量ベースの振り分けのみです。モード2では日数経過で B2 へ移します。') }}
      </p>
    </div>

    <button type="submit" class="button-link">{{ __('パイプラインを保存') }}</button>
  </form>
</div>

<script>
  (function () {
    const select = document.getElementById('storage-capacity-mode')
    if (!select) return
    const sync = () => {
      const mode = select.value
      document.querySelectorAll('[data-capacity-hint]').forEach((el) => {
        el.hidden = el.getAttribute('data-capacity-hint') !== mode
      })
      document.querySelectorAll('[data-capacity-panel]').forEach((el) => {
        const key = el.getAttribute('data-capacity-panel')
        if (key === 'age_or_overflow') {
          el.hidden = !(mode === 'age_archive' || mode === 'overflow_priority')
          return
        }
        el.hidden = key !== mode
      })
      document.querySelectorAll('[data-capacity-hint-days]').forEach((el) => {
        el.hidden = el.getAttribute('data-capacity-hint-days') !== mode
      })
    }
    select.addEventListener('change', sync)
    sync()
  })()
</script>

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
    <label class="storage-enable">
      <input type="checkbox" name="allow_paid_overage" value="1" @checked(!empty($r2Settings['allow_paid_overage'])) />
      {{ __('有料枠（無料枠超過）を許可する') }}
    </label>
    <p class="hint">{{ __('オンにすると、製品無料枠を超えても追加できます（見込料金は表示のみ）。いずれかのストレージでオンなら全体で解除されます。') }}</p>
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
    <label class="storage-enable">
      <input type="checkbox" name="allow_paid_overage" value="1" @checked(!empty($cSettings['allow_paid_overage'])) />
      {{ __('有料枠（無料枠超過）を許可する') }}
    </label>
    <p class="hint">{{ __('オンにすると、製品無料枠を超えても追加できます。いずれかのストレージでオンなら全体で解除されます。') }}</p>
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
    <label class="storage-enable">
      <input type="checkbox" name="allow_paid_overage" value="1" @checked(!empty($bSettings['allow_paid_overage'])) />
      {{ __('有料枠（無料枠超過）を許可する') }}
    </label>
    <p class="hint">{{ __('オンにすると、製品無料枠を超えても追加できます。いずれかのストレージでオンなら全体で解除されます。') }}</p>
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
