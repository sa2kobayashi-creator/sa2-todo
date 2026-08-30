@php
  $form = $usageLimitForm ?? ['templates' => [], 'platform' => [], 'saved' => false];
  $templates = $form['templates'] ?? [];
  $platform = $form['platform'] ?? [];
  $yen = (int) ($usageEstimatedYen ?? 0);
  $yenCap = (int) ($platform['estimated_monthly_yen_cap'] ?? 0);
  $ratio = $yenCap > 0 ? $yen / $yenCap : 0;
  $planColumns = \App\Models\UsageLimitPolicy::templatePlans();
@endphp
<div class="panel" id="usage-limit-settings">
  <h2>{{ __('制限管理') }}</h2>
  <p class="hint">{{ __('運営キーで動く Light / Standard / 特別枠 / テナント契約の上限です。Standard やテナント代表はここを編集できません。未保存のあいだは従来の全員共通の日次上限です。0 はその項目を無制限にします。テナントの回数・文字数は契約メンバーの合算です。ストレージの製品既定は Light 約:lightGB／Standard 約:standardGB（設定を保存するとここが優先されます）。特別枠は運営がユーザー管理で付けた個人アカウント専用で、招待コード経路や利用申請とは別です。', [
    'lightGB' => max(1, (int) round(((int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024))),
    'standardGB' => max(1, (int) round(((int) config('photos.standard_quota_bytes', 200 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024))),
  ]) }}</p>

  @if($ratio >= 1)
    <div class="banner error">{{ __('運営の今月の見積が上限に達しています。SuperAdmin 以外の AI・翻訳を止めています。') }}</div>
  @elseif($ratio >= 0.8)
    <div class="banner notice">{{ __('運営の今月の見積が上限の 80% を超えています。') }}</div>
  @endif

  @if(!empty($platform['hard_stop_all']))
    <div class="banner error">{{ __('全停止がオンです。SuperAdmin 以外は外部APIを使えません。') }}</div>
  @endif

  <form method="post" action="/settings/limits" class="usage-limit-form">
    @csrf
    <div class="usage-limit-table-wrap">
      <table class="usage-limit-table">
        <thead>
          <tr>
            <th>{{ __('項目') }}</th>
            @foreach($planColumns as $plan)
              <th>{{ __(\App\Models\UsageLimitPolicy::templatePlanLabel($plan)) }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          <tr>
            <th>{{ __('ストレージ（GB／人）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][storage_quota_gb]" min="0" value="{{ (int) ($templates[$plan]['storage_quota_gb'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('翻訳（文字／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][translate_chars_per_day]" min="0" value="{{ (int) ($templates[$plan]['translate_chars_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('翻訳（文字／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][translate_chars_per_month]" min="0" value="{{ (int) ($templates[$plan]['translate_chars_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('音声AI（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][llm_voice_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['llm_voice_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('音声AI（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][llm_voice_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['llm_voice_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('生活ガイド（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][workers_ai_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['workers_ai_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('生活ガイド（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][workers_ai_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['workers_ai_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('路線・経路検索（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][route_search_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['route_search_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('路線・経路検索（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][route_search_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['route_search_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('YouTube検索（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][youtube_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['youtube_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('YouTube検索（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][youtube_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['youtube_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('Cloudinary編集（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][cloudinary_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['cloudinary_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('Cloudinary編集（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][cloudinary_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['cloudinary_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('通話 LiveKit（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][livekit_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['livekit_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('通話 LiveKit（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][livekit_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['livekit_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('地図・ジオコード（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][maps_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['maps_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('地図・ジオコード（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][maps_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['maps_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('通知（通／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][notify_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['notify_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('通知（通／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][notify_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['notify_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('動画再生（回／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][video_play_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['video_play_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('動画再生（回／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][video_play_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['video_play_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('添付（件／日）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][attachment_requests_per_day]" min="0" value="{{ (int) ($templates[$plan]['attachment_requests_per_day'] ?? 0) }}" /></td>
            @endforeach
          </tr>
          <tr>
            <th>{{ __('添付（件／月）') }}</th>
            @foreach($planColumns as $plan)
              <td><input type="number" name="templates[{{ $plan }}][attachment_requests_per_month]" min="0" value="{{ (int) ($templates[$plan]['attachment_requests_per_month'] ?? 0) }}" /></td>
            @endforeach
          </tr>
        </tbody>
      </table>
    </div>

    <h3>{{ __('運営全体の非常停止') }}</h3>
    <p class="hint">{{ __('見積円は公式請求ではなく、回数×下の単価です。0 でブレーカーをオフにします。全停止は障害・請求事故用です。Maps JS の地図表示自体は回数枠の対象外です（経路APIのみ）。') }}</p>
    <p>
      {{ __('今月の見積') }}: <strong>¥{{ number_format($yen) }}</strong>
      @if($yenCap > 0)
        / {{ __('上限') }} ¥{{ number_format($yenCap) }}
      @endif
    </p>
    <div class="usage-limit-platform">
      <label>
        {{ __('今月の見積上限（円）') }}
        <input type="number" name="platform[estimated_monthly_yen_cap]" min="0" value="{{ $yenCap }}" />
      </label>
      <label class="storage-enable">
        <input type="checkbox" name="platform[hard_stop_all]" value="1" @checked(!empty($platform['hard_stop_all'])) />
        {{ __('SuperAdmin 以外の外部APIを全停止する') }}
      </label>
      <label>
        {{ __('見積：音声AI 1回（円）') }}
        <input type="number" name="platform[yen_per_llm_voice]" min="0" value="{{ (int) ($platform['yen_per_llm_voice'] ?? 5) }}" />
      </label>
      <label>
        {{ __('見積：生活ガイド 1回（円）') }}
        <input type="number" name="platform[yen_per_workers_ai]" min="0" value="{{ (int) ($platform['yen_per_workers_ai'] ?? 3) }}" />
      </label>
      <label>
        {{ __('見積：翻訳 1000文字（円）') }}
        <input type="number" name="platform[yen_per_translate_1000]" min="0" value="{{ (int) ($platform['yen_per_translate_1000'] ?? 2) }}" />
      </label>
      <label>
        {{ __('見積：路線・経路 1回（円）') }}
        <input type="number" name="platform[yen_per_route_search]" min="0" value="{{ (int) ($platform['yen_per_route_search'] ?? 4) }}" />
      </label>
      <label>
        {{ __('見積：YouTube 1回（円）') }}
        <input type="number" name="platform[yen_per_youtube]" min="0" value="{{ (int) ($platform['yen_per_youtube'] ?? 2) }}" />
      </label>
      <label>
        {{ __('見積：Cloudinary 1回（円）') }}
        <input type="number" name="platform[yen_per_cloudinary]" min="0" value="{{ (int) ($platform['yen_per_cloudinary'] ?? 5) }}" />
      </label>
      <label>
        {{ __('見積：通話 1回（円）') }}
        <input type="number" name="platform[yen_per_livekit]" min="0" value="{{ (int) ($platform['yen_per_livekit'] ?? 8) }}" />
      </label>
      <label>
        {{ __('見積：地図・ジオコード 1回（円）') }}
        <input type="number" name="platform[yen_per_maps]" min="0" value="{{ (int) ($platform['yen_per_maps'] ?? 1) }}" />
      </label>
      <label>
        {{ __('見積：通知 1通（円）') }}
        <input type="number" name="platform[yen_per_notify]" min="0" value="{{ (int) ($platform['yen_per_notify'] ?? 1) }}" />
      </label>
      <label>
        {{ __('見積：動画再生 1回（円）') }}
        <input type="number" name="platform[yen_per_video_play]" min="0" value="{{ (int) ($platform['yen_per_video_play'] ?? 1) }}" />
      </label>
      <label>
        {{ __('見積：添付 1件（円）') }}
        <input type="number" name="platform[yen_per_attachment]" min="0" value="{{ (int) ($platform['yen_per_attachment'] ?? 1) }}" />
      </label>
    </div>
    <button type="submit">{{ __('制限を保存') }}</button>
  </form>
</div>
