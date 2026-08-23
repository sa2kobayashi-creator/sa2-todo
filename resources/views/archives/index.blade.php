<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('過去データの長期保存') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'archives'])
    <main class="page-main page-main-narrow">
      <div class="panel">
        <h1>{{ __('過去データの長期保存') }}</h1>
        <p class="hint">{{ __('1年以上前の完了済み Todo や整理済みメモは、サーバー容量を守るため長期保存へ移すことがあります。ここから検索して戻せます。') }}</p>
        @if(!empty($notice))
          <p class="banner notice">{{ $notice }}</p>
        @endif
        @if(!empty($error))
          <p class="banner error">{{ $error }}</p>
        @endif
        <form method="get" action="/archives" class="stack-form">
          <label>{{ __('検索') }}
            <input type="search" name="q" value="{{ $q }}" maxlength="120" />
          </label>
          <button type="submit">{{ __('検索') }}</button>
        </form>
        @if(!empty($candidates))
          <h2>{{ __('まもなく長期保存に移るもの') }}</h2>
          <p class="hint">{{ __('サーバーに残したいものは「残す」を押してください。次回以降の移動対象から外れます。') }}</p>
          <ul class="archive-list">
            @foreach($candidates as $candidate)
              <li>
                <strong>{{ $candidate['title'] ?: __('（無題）') }}</strong>
                <span class="hint">{{ $candidate['type'] === 'todos' ? __('Todo') : __('メモ') }} · {{ $candidate['updated_at']?->format('Y-m-d') }}</span>
                <form method="post" action="/archives/keep">
                  @csrf
                  <input type="hidden" name="type" value="{{ $candidate['type'] }}" />
                  <input type="hidden" name="id" value="{{ $candidate['id'] }}" />
                  <input type="hidden" name="keep" value="1" />
                  <button type="submit">{{ __('サーバーに残す') }}</button>
                </form>
              </li>
            @endforeach
          </ul>
        @endif

        <h2>{{ __('長期保存済み') }}</h2>
        <ul class="archive-list">
          @forelse($rows as $row)
            <li>
              <strong>{{ $row->title ?: __('（無題）') }}</strong>
              <span class="hint">{{ $row->source_table === 'todos' ? __('Todo') : __('メモ') }} · {{ $row->archived_at?->format('Y-m-d') }}</span>
              @if($row->summary)
                <p class="hint">{{ $row->summary }}</p>
              @endif
              <form method="post" action="/archives/{{ $row->id }}/restore">
                @csrf
                <button type="submit">{{ __('元に戻す') }}</button>
              </form>
            </li>
          @empty
            <li class="hint">{{ __('該当する長期保存はありません。') }}</li>
          @endforelse
        </ul>
      </div>
    </main>
  </body>
</html>
