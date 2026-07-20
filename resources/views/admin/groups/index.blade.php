<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('グループ管理') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-groups'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <h2>{{ __('グループ管理') }}</h2>
        <p class="hint">{{ __('申請されたグループの承認・却下を行います。承認後に ToDo / Photos の共有先として利用できます。') }}</p>
      </div>

      <div class="panel">
        <h2>{{ __('グループ一覧') }} ({{ count($groups) }})</h2>
        @forelse($groups as $group)
          <div class="admin-user-card">
            <div class="admin-user-card-head">
              <strong>{{ $group['name'] }}</strong>
              <span class="role-badge">{{ $group['statusLabel'] }}</span>
            </div>
            @if(!empty($group['description']))
              <p class="hint">{{ $group['description'] }}</p>
            @endif
            <p class="hint">
              {{ __('オーナー') }}: {{ $group['ownerName'] ?? '—' }}
              · {{ __('メンバー') }}: {{ $group['memberCount'] }}
              @if(!empty($group['reviewedAt']))
                · {{ __('審査') }}: {{ $group['reviewedAt'] }}
              @endif
            </p>
            @if(!empty($group['reviewNote']))
              <p class="hint">{{ __('メモ') }}: {{ $group['reviewNote'] }}</p>
            @endif

            @if(($group['status'] ?? '') === 'pending')
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <form method="post" action="/admin/groups/{{ $group['id'] }}/approve" class="inline-form">
                  @csrf
                  <input type="hidden" name="review_note" value="" />
                  <button type="submit">{{ __('承認') }}</button>
                </form>
                <form method="post" action="/admin/groups/{{ $group['id'] }}/reject" class="inline-form">
                  @csrf
                  <input type="text" name="review_note" placeholder="{{ __('却下理由（任意）') }}" maxlength="500" />
                  <button type="submit" class="danger">{{ __('却下') }}</button>
                </form>
              </div>
            @endif
          </div>
        @empty
          <p class="hint">{{ __('グループはまだありません。') }}</p>
        @endforelse
      </div>
    </main>
  </body>
</html>
