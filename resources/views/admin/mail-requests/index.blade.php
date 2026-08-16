<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('メール申請管理') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-mail'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @include('partials.settings-subnav', ['active' => 'admin-mail'])

      <div class="panel">
        <h2>{{ __('@:domain メール申請', ['domain' => $mailDomain]) }}</h2>
        <p class="banner notice">
          {{ __('@:domain メールの自動作成は近日公開予定です。公開までは管理画面で手動作成し、下で「作成済み」にしてください。', ['domain' => $mailDomain]) }}
        </p>
        <p class="hint">
          {{ __('不正利用対策: 契約あたり無料 :count 件・管理者承認必須・自動一括作成は行いません。スパム用途が疑われる場合は停止してください。', ['count' => $freeQuota]) }}
        </p>
      </div>

      <div class="panel">
        <h3>{{ __('対応待ち') }}</h3>
        @forelse($pending as $req)
          <article class="mail-admin-request">
            <header>
              <strong>{{ $req['email'] }}</strong>
              <span class="mail-status mail-status-{{ $req['status'] }}">{{ match($req['status']) {
                'pending' => __('申請中'),
                'approved' => __('承認済み（作成待ち）'),
                'rejected' => __('却下'),
                'provisioned' => __('作成済み'),
                'suspended' => __('停止'),
                default => $req['status'],
              } }}</span>
            </header>
            <p>{{ __('申請者') }}: {{ $req['user']['displayName'] ?? '' }}（{{ $req['user']['email'] ?? '' }}）</p>
            @if(!empty($req['userNote']))
              <p class="hint">{{ $req['userNote'] }}</p>
            @endif
            <form method="post" class="mail-admin-actions">
              @csrf
              <label>{{ __('管理者メモ') }}<input type="text" name="admin_note" value="{{ $req['adminNote'] ?? '' }}" /></label>
              @if($req['status'] === 'pending')
                <button formaction="/admin/mail-requests/{{ $req['id'] }}/approve">{{ __('承認') }}</button>
                <button formaction="/admin/mail-requests/{{ $req['id'] }}/reject" class="secondary">{{ __('却下') }}</button>
              @endif
              <button formaction="/admin/mail-requests/{{ $req['id'] }}/provision">{{ __('作成済みにする（手動）') }}</button>
            </form>
          </article>
        @empty
          <p class="hint">{{ __('対応待ちの申請はありません。') }}</p>
        @endforelse
      </div>

      <div class="panel">
        <h3>{{ __('最近の申請') }}</h3>
        <ul class="mail-domain-request-list">
          @foreach($recent as $req)
            <li>
              <strong>{{ $req['email'] }}</strong>
              <span class="mail-status mail-status-{{ $req['status'] }}">{{ match($req['status']) {
                'pending' => __('申請中'),
                'approved' => __('承認済み（作成待ち）'),
                'rejected' => __('却下'),
                'provisioned' => __('作成済み'),
                'suspended' => __('停止'),
                default => $req['status'],
              } }}</span>
              <span class="hint">{{ $req['user']['displayName'] ?? '' }}</span>
              @if($req['status'] === 'provisioned')
                <form method="post" action="/admin/mail-requests/{{ $req['id'] }}/suspend" class="inline-form">
                  @csrf
                  <button type="submit" class="danger">{{ __('停止') }}</button>
                </form>
              @endif
            </li>
          @endforeach
        </ul>
      </div>
    </main>
  </body>
</html>
