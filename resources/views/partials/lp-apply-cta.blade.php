{{-- TOP 利用申請 CTA。$applicationsOpen が false のとき「準備中」表示 --}}
@php
  $ctaClass = $class ?? 'lp-btn lp-btn-primary';
  $ctaLabel = $label ?? __('利用申請');
  $ctaPending = $pendingLabel ?? __('準備中');
  $ctaOpen = ! empty($applicationsOpen);
@endphp
@if($ctaOpen)
  <a
    href="{{ $href }}"
    @if(!empty($event)) data-stat-event="{{ $event }}" @endif
    class="{{ $ctaClass }}"
  >{{ $ctaLabel }}</a>
@else
  <span
    class="{{ $ctaClass }} is-pending"
    role="status"
    aria-disabled="true"
    title="{{ __('現在、利用申請の受付は準備中です。') }}"
  >{{ $ctaPending }}</span>
@endif
