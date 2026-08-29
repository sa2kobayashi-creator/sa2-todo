{{-- Google Calendar OAuth（個人連携。ログイン認証ではない） --}}
@php
  $gc = $googleCalendar ?? [
    'configured' => false,
    'connected' => false,
    'googleEmail' => null,
    'connectedAt' => null,
    'calendars' => [],
    'selectedCalendarIds' => [],
    'syncCalendarId' => null,
    'needsRescope' => false,
  ];
  $selected = $gc['selectedCalendarIds'] ?? [];
  $gcalBase = $googleCalendarActionBase ?? '/mypage/google-calendar';
  $gcalReturnTo = is_string($googleCalendarReturnTo ?? null) ? $googleCalendarReturnTo : null;
  $gcalConnectUrl = $gcalBase.'/connect';
  if ($gcalReturnTo) {
    $gcalConnectUrl .= '?returnTo='.rawurlencode($gcalReturnTo);
  }
  $gcalEmbed = ! empty($googleCalendarEmbed);
@endphp
@if($gcalEmbed)
<div class="google-calendar-embed" @if(empty($googleCalendarOmitId)) id="google-calendar" @endif>
@else
<div class="panel storage-settings" id="google-calendar">
  <h2>{{ __($googleCalendarTitle ?? 'Googleカレンダー') }}</h2>
@endif
  <p class="hint">{{ __('sa2-plus のログインとは別に、ご自身の Google アカウントのカレンダーを連携します。仕事モードのダッシュボード／Todo と同期します。仕事 ToDo 作成時は Google Meet を自動付与します。') }}</p>

  @if(empty($gc['configured']))
    <p class="hint storage-test-result is-fail">
      {{ __('OAuth が未設定です。管理者に設定 → API設定 で Google カレンダーの Client ID を登録してもらってください。') }}
    </p>
  @elseif(empty($gc['connected']))
    <p class="hint">{{ __('Googleカレンダーは連携されていません。') }}</p>
    <div class="storage-form-actions">
      <a class="button-link" href="{{ $gcalConnectUrl }}">{{ __('Googleカレンダーと連携') }}</a>
    </div>
  @else
    <dl class="photos-usage-result-dl" style="margin: 0 0 12px;">
      <div>
        <dt>{{ __('状態') }}</dt>
        <dd>{{ __('連携済み') }}</dd>
      </div>
      <div>
        <dt>{{ __('Googleアカウント') }}</dt>
        <dd>{{ $gc['googleEmail'] ?: '—' }}</dd>
      </div>
      @if(!empty($gc['connectedAt']))
        <div>
          <dt>{{ __('最終更新') }}</dt>
          <dd>{{ $gc['connectedAt'] }}</dd>
        </div>
      @endif
    </dl>

    @if(!empty($gc['needsRescope']))
      <p class="hint storage-test-result is-fail">
        {{ __('カレンダー一覧の取得に失敗しました。権限追加のため「再連携」してください。') }}
      </p>
      <div class="storage-form-actions">
        <a class="button-link" href="{{ $gcalConnectUrl }}">{{ __('権限を追加して再連携') }}</a>
      </div>
    @elseif(!empty($gc['calendars']))
      <form method="post" action="{{ $gcalBase }}/calendars" class="storage-provider-form">
        @csrf
        @if($gcalReturnTo)
          <input type="hidden" name="returnTo" value="{{ $gcalReturnTo }}" />
        @endif
        <h3>{{ __('表示するカレンダー') }}</h3>
        <p class="hint">{{ __('仕事モードのダッシュボードに表示するカレンダーを選択します。') }}</p>
        <div class="storage-checkbox-list" style="display:grid;gap:6px;margin:8px 0 16px;">
          @foreach($gc['calendars'] as $cal)
            <label class="checkbox-inline">
              <input
                type="checkbox"
                name="calendar_ids[]"
                value="{{ $cal['id'] }}"
                @checked(in_array($cal['id'], $selected, true) || (empty($selected) && !empty($cal['primary'])))
              />
              {{ $cal['summary'] }}
              @if(!empty($cal['primary']))
                <span class="hint inline-hint">{{ __('（メイン）') }}</span>
              @endif
            </label>
          @endforeach
        </div>

        <h3>{{ __('Todo同期先カレンダー') }}</h3>
        <p class="hint">{{ __('仕事 ToDo を新規作成したときに書き込むカレンダーです。') }}</p>
        <label>
          <select name="sync_calendar_id">
            @foreach($gc['calendars'] as $cal)
              <option
                value="{{ $cal['id'] }}"
                @selected(($gc['syncCalendarId'] ?? '') === $cal['id'] || (($gc['syncCalendarId'] ?? '') === 'primary' && !empty($cal['primary'])))
              >
                {{ $cal['summary'] }}
              </option>
            @endforeach
          </select>
        </label>

        <div class="storage-form-actions" style="margin-top:12px;">
          <button type="submit">{{ __('カレンダー設定を保存') }}</button>
        </div>
      </form>

      <form method="post" action="{{ $gcalBase }}/import" class="storage-provider-form" style="margin-top:16px;">
        @csrf
        @if($gcalReturnTo)
          <input type="hidden" name="returnTo" value="{{ $gcalReturnTo }}" />
        @endif
        <h3>{{ __('予定の取込') }}</h3>
        <p class="hint">{{ __('選択中カレンダーの予定を仕事 ToDo として取り込みます（過去30日〜先90日）。') }}</p>
        <div class="storage-form-actions">
          <button type="submit" class="secondary">{{ __('Googleから取り込む') }}</button>
        </div>
      </form>
    @endif

    <div class="storage-form-actions" style="margin-top:16px;">
      <form method="post" action="{{ $gcalBase }}/probe" class="inline-form">
        @csrf
        @if($gcalReturnTo)
          <input type="hidden" name="returnTo" value="{{ $gcalReturnTo }}" />
        @endif
        <button type="submit" class="secondary">{{ __('接続テスト（直近1件）') }}</button>
      </form>
      <a class="button-link secondary" href="{{ $gcalConnectUrl }}">{{ __('再連携') }}</a>
      <form
        method="post"
        action="{{ $gcalBase }}/disconnect"
        class="inline-form"
        onsubmit="return confirm(@json(__('Googleカレンダー連携を解除しますか？')))"
      >
        @csrf
        @if($gcalReturnTo)
          <input type="hidden" name="returnTo" value="{{ $gcalReturnTo }}" />
        @endif
        <button type="submit" class="secondary">{{ __('連携を解除') }}</button>
      </form>
    </div>
  @endif
</div>
