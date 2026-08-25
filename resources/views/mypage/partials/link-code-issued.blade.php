@php
  $issuedProvider = $linkCodeProvider ?? null;
  $issuedCode = $linkCode ?? null;
  $issuedMinutes = $linkCodeMinutes ?? 15;
  $showIssued = ($issuedProvider === ($expectedProvider ?? '')) && is_string($issuedCode) && $issuedCode !== '';
@endphp
@if($showIssued)
  <p class="line-issued-code" role="status">
    <span class="line-issued-code-value">{{ $issuedCode }}</span>
    <span>{{ __('（:minutes分有効）公式アカウント／ページのトークにこのコードだけを送ってください。', ['minutes' => $issuedMinutes]) }}</span>
  </p>
@endif
