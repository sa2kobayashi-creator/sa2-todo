@php
  $labels = array_values($labels ?? []);
  $count = count($labels);
  $popupId = $popupId ?? ('menu-popup-'.uniqid());
@endphp
@if($count === 0)
  <span class="hint">{{ __('なし') }}</span>
@elseif($count === 1)
  <div class="menu-feature-labels">
    <span class="menu-feature-label">{{ $labels[0] }}</span>
  </div>
@else
  <button
    type="button"
    class="menu-features-popup-trigger mini-btn"
    data-menu-popup-target="{{ $popupId }}"
    aria-haspopup="dialog"
    aria-controls="{{ $popupId }}"
  >{{ __(':count件', ['count' => $count]) }}</button>
  <div class="modal" id="{{ $popupId }}" hidden>
    <div class="modal-backdrop" data-menu-popup-close></div>
    <div class="modal-dialog menu-features-popup-dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $popupId }}-title">
      <div class="modal-header">
        <h2 id="{{ $popupId }}-title">{{ __('利用メニュー') }}</h2>
        <button type="button" class="modal-close" data-menu-popup-close aria-label="{{ __('閉じる') }}">×</button>
      </div>
      <ul class="menu-features-popup-list">
        @foreach($labels as $label)
          <li>{{ $label }}</li>
        @endforeach
      </ul>
    </div>
  </div>
@endif
