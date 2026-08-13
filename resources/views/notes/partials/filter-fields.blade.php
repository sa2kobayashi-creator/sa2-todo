{{-- メモ一覧フィルタ項目（デスクトップ常時 / スマホモーダル共用） --}}
@php
  $idPrefix = $idPrefix ?? '';
  $includeJump = $includeJump ?? true;
@endphp
@if($showArchived)<input type="hidden" name="archived" value="1" />@endif
@if($filterDate)<input type="hidden" name="date" value="{{ $filterDate }}" />@endif
<label class="notes-period-label">
  {{ __('表示月') }}
  <select name="period" id="{{ $idPrefix }}notes-period" aria-label="{{ __('表示月') }}" @disabled($filterDate)>
    <option value="" @selected($periodValue === '')>{{ __('すべて') }}</option>
    @foreach($notePeriodOptions ?? [] as $periodOption)
      <option value="{{ $periodOption }}" @selected($periodValue === $periodOption)>
        {{ __(':year年:month月', ['year' => substr($periodOption, 0, 4), 'month' => (int) substr($periodOption, 5, 2)]) }}
      </option>
    @endforeach
    @if($periodValue !== '' && ! in_array($periodValue, $notePeriodOptions ?? [], true))
      <option value="{{ $periodValue }}" selected>
        {{ __(':year年:month月', ['year' => substr($periodValue, 0, 4), 'month' => (int) substr($periodValue, 5, 2)]) }}
      </option>
    @endif
  </select>
</label>
<label class="notes-status-filter">
  {{ __('完了') }}
  <select name="status" id="{{ $idPrefix }}notes-status-filter" aria-label="{{ __('完了') }}">
    <option value="all" @selected(($filterStatus ?? 'all') === 'all')>{{ __('すべて') }}</option>
    <option value="pending" @selected(($filterStatus ?? '') === 'pending')>{{ __('未完了') }}</option>
    <option value="done" @selected(($filterStatus ?? '') === 'done')>{{ __('完了') }}</option>
  </select>
</label>
<label class="notes-search-label">
  <span class="visually-hidden">{{ __('メモを検索') }}</span>
  <input type="search" name="q" id="{{ $idPrefix }}notes-search-q" value="{{ $searchQuery }}" placeholder="{{ __('メモを検索') }}" aria-label="{{ __('メモを検索') }}" />
</label>
<label class="notes-category-filter">
  {{ __('カテゴリー') }}
  <select name="category" id="{{ $idPrefix }}notes-category-filter" aria-label="{{ __('カテゴリー') }}">
    <option value="" @selected($filterCategory === '')>{{ __('すべて') }}</option>
    @foreach($noteCategories as $key => $label)
      <option value="{{ $key }}" @selected($filterCategory === $key)>{{ $label }}</option>
    @endforeach
  </select>
</label>
@if($includeJump && count($noteJumpMonths ?? []) > 1)
  <label class="notes-jump-filter">
    <span class="visually-hidden">{{ __('年月へ移動') }}</span>
    <select id="{{ $idPrefix }}notes-month-jump" aria-label="{{ __('年月へ移動') }}">
      <option value="">{{ __('年月へ移動') }}</option>
      @foreach($noteJumpMonths as $jumpMonth)
        <option value="{{ $jumpMonth['period'] }}">{{ $jumpMonth['label'] }}</option>
      @endforeach
    </select>
  </label>
@endif
