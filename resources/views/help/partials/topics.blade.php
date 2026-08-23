@if(!empty($canSettings))
  @php $topic = $helpTopic ?? ''; @endphp
  <nav class="help-topics" aria-label="{{ __('管理者ヘルプ') }}">
    <a href="/help" @class(['active' => $topic === 'basic'])>{{ __('通常のヘルプ') }}</a>
    <a href="/help/overview" @class(['active' => $topic === 'overview'])>{{ __('このアプリの概要') }}</a>
    <a href="/help/guide" @class(['active' => $topic === 'guide'])>{{ __('このアプリの使用方法') }}</a>
  </nav>
@endif
