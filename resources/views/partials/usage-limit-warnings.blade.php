{{-- 利用枠の月次警告（80% / 90% / 100%） --}}
@php
  $warnings = $warnings ?? [];
  $compact = ! empty($compact);
@endphp
@if(count($warnings) > 0)
  <ul class="usage-limit-warnings{{ $compact ? ' is-compact' : '' }}" role="status">
    @foreach($warnings as $w)
      @php
        $level = (string) ($w['level'] ?? 'warn');
        $msg = (string) ($w['message'] ?? '');
        if ($compact && $level === 'critical' && ($w['label'] ?? '') !== '') {
          $msg = __(':name：:message', ['name' => $w['label'], 'message' => $msg]);
        }
      @endphp
      @if($msg !== '')
        <li class="usage-limit-warning is-{{ $level }}">{{ $msg }}</li>
      @endif
    @endforeach
  </ul>
@endif
