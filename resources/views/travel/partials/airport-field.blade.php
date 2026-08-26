@php
  $name = $name ?? 'origin';
  $label = $label ?? __('出発空港');
  $value = $value ?? '';
  $placeholder = $placeholder ?? __('例: FUK / 福岡');
  $required = ! empty($required);
@endphp
<label class="travel-airport-field">
  <span>{{ $label }}</span>
  <span class="travel-airport-control">
    <input
      type="text"
      name="{{ $name }}"
      value="{{ $value }}"
      maxlength="40"
      autocomplete="off"
      placeholder="{{ $placeholder }}"
      data-travel-airport
      @required($required)
    />
  </span>
</label>
