@php
    $dashId = $id ?? 'photos-show-dashboard';
    $dashName = $name ?? 'show_on_dashboard';
    $dashClass = $class ?? '';
    $dashValue = $value ?? true;
@endphp
<label class="photos-dashboard-field {{ $dashClass }}">
  <span>{{ __('ダッシュボード') }}</span>
  <select name="{{ $dashName }}" id="{{ $dashId }}">
    <option value="1" @selected($dashValue)>{{ __('表示する') }}</option>
    <option value="0" @selected(! $dashValue)>{{ __('表示しない') }}</option>
  </select>
</label>
