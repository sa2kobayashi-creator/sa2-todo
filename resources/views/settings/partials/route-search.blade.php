@php
  $rs = $routeSearchSettings ?? ['options' => [], 'selected' => 'auto', 'ready' => []];
@endphp

<div class="panel storage-settings" id="route-search-settings">
  <h2>{{ __('経路検索に使う API') }}</h2>
  <p class="hint">{{ __('既定の API です。路線検索画面の「使う経路検索」でも検索のたびに切り替えられます。自動は NAVITIME → 駅すぱあと → RAPTOR です。Google Maps Routes は日本の電車・バスを返さないため自動では使いません。') }}</p>
  <form method="post" action="/settings/api/route-search" class="storage-provider-form">
    @csrf
    <label>
      {{ __('使う API') }}
      <select name="engine">
        @foreach($rs['options'] as $key => $label)
          <option value="{{ $key }}" @selected(($rs['selected'] ?? 'auto') === $key)>
            {{ $label }}
            @if($key === 'google')
              {{ __('（日本の電車・バスは非対応）') }}
            @endif
            @if(array_key_exists($key, $rs['ready'] ?? []) && ! $rs['ready'][$key])
              {{ __('（未設定）') }}
            @endif
          </option>
        @endforeach
      </select>
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <span class="hint">{{ __('いま使われるのは:') }} <strong>{{ $rs['activeLabel'] ?? __('なし') }}</strong></span>
    </div>
  </form>
</div>
