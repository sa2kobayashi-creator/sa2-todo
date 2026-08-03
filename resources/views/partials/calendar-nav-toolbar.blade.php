{{-- 共通カレンダーナビ: 1行目 ‹ 年月 › / 2行目 今日 月週日年 （メモ/Todo） --}}
@php
  $showMemoTodoActions = $showMemoTodoActions ?? false;
  $navPrevUrl = $prevUrl;
  $navNextUrl = $nextUrl;
  $navTodayUrl = $todayUrl;
  $navPeriodLabel = $periodLabel;
  $navView = $view;
  $navViewLabels = $viewLabels;
  $navBuildViewUrl = $buildViewUrl;
@endphp
<div class="calendar-toolbar calendar-toolbar-inline">
  <div class="calendar-toolbar-row calendar-toolbar-row-nav">
    <a class="button-link secondary calendar-nav-arrow" href="{{ $navPrevUrl }}" aria-label="{{ __('前へ') }}">
      <span class="calendar-nav-glyph" aria-hidden="true">‹</span>
    </a>
    <span class="month-label">{{ $navPeriodLabel }}</span>
    <a class="button-link secondary calendar-nav-arrow" href="{{ $navNextUrl }}" aria-label="{{ __('次へ') }}">
      <span class="calendar-nav-glyph" aria-hidden="true">›</span>
    </a>
  </div>
  <div class="calendar-toolbar-row calendar-toolbar-row-controls">
    <a class="button-link secondary calendar-chip-btn" href="{{ $navTodayUrl }}">{{ __('今日') }}</a>
    <div class="calendar-view-switch" role="group" aria-label="{{ __('表示切替') }}">
      @foreach($navViewLabels as $viewKey => $viewLabel)
        <a
          href="{{ $navBuildViewUrl($viewKey) }}"
          class="calendar-view-btn @if($navView === $viewKey) is-active @endif"
          aria-current="{{ $navView === $viewKey ? 'page' : 'false' }}"
        >{{ $viewLabel }}</a>
      @endforeach
    </div>
    @if($showMemoTodoActions)
      <div class="calendar-toolbar-actions">
        <a class="button-link secondary calendar-action-btn" href="/notes" title="{{ __('メモ') }}" aria-label="{{ __('メモ') }}">
          <span class="calendar-action-icon" aria-hidden="true">📝</span>
          <span class="calendar-action-label">{{ __('メモ') }}</span>
        </a>
        <a class="button-link calendar-action-btn calendar-action-primary" href="/todos" title="{{ __('Todo 管理へ') }}" aria-label="{{ __('Todo 管理へ') }}">
          <span class="calendar-action-icon" aria-hidden="true">✅</span>
          <span class="calendar-action-label">{{ __('Todo 管理へ') }}</span>
        </a>
      </div>
    @endif
  </div>
</div>
