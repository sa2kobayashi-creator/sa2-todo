{{-- TOP 利用申請 CTA。$plan（light/standard/tenant/dedicated）または $applicationsOpen で開閉 --}}
@php
  use App\Support\Registration;
  $ctaClass = $class ?? 'lp-btn lp-btn-primary';
  $ctaLabel = $label ?? __('利用申請');
  $ctaPending = $pendingLabel ?? __('準備中');
  $ctaPlan = isset($plan) ? (string) $plan : null;
  if ($ctaPlan !== null && $ctaPlan !== '') {
    $ctaOpen = Registration::applicationsOpenFor($ctaPlan);
  } elseif (array_key_exists('applicationsOpen', get_defined_vars())) {
    $ctaOpen = ! empty($applicationsOpen);
  } else {
    $ctaOpen = Registration::applicationsOpen();
  }
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
    title="{{ __('現在、このプランの利用申請は準備中です。') }}"
  >{{ $ctaPending }}</span>
@endif
