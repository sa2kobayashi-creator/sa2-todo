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

      <div class="panel">
        <h2>{{ __('@:domain メール申請', ['domain' => $mailDomain]) }}</h2>
        <p class="banner notice">
          {{ __('アドレスの作成とパスワード設定は、運営者がロリポップ管理画面で手動で行います。アプリから自動作成はしません。') }}
        </p>
        <ol class="hint" style="margin:8px 0 12px;padding-left:1.4em;">
          <li>{{ __('申請を確認し、必要なら承認する') }}</li>
          <li>{{ __('ロリポップでメールアドレスとパスワードを作成する') }}</li>
          <li>{{ __('パスワードを利用者へ別途伝える（この画面には出ません）') }}</li>
          <li>{{ __('下の「作成済みにする（手動）」を押す') }}</li>
          <li>{{ __('利用者はメール設定に、そのパスワードを入力して送受信する') }}</li>
        </ol>
        <p class="hint">
          {{ __('スタンダードはプランに含みます。ライトは個別契約です。ライトは入金確認後、ユーザー編集で「メールボックス有料オプション」をオンにするか、ここで承認するとオンになります。') }}
        </p>
        <p class="hint">
          {{ __('不正利用対策: 契約あたり :count 件・管理者承認必須。スパム用途が疑われる場合は停止してください。', ['count' => $mailboxLimit ?? 1]) }}
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
              @if(!empty($req['cancelRequested']))
                <span class="mail-status mail-status-suspended">{{ __('解約依頼中') }}</span>
              @endif
              @if(!empty($req['mailboxAddonActive']))
                <span class="hint">{{ __('オプション: 有効') }}</span>
              @else
                <span class="hint">{{ __('オプション: 未設定') }}</span>
              @endif
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
              @if(in_array($req['status'], ['pending', 'approved'], true))
                <button formaction="/admin/mail-requests/{{ $req['id'] }}/provision">{{ __('作成済みにする（手動）') }}</button>
              @endif
              @if($req['status'] === 'provisioned')
                <button formaction="/admin/mail-requests/{{ $req['id'] }}/suspend" class="danger">{{ __('停止（解約）') }}</button>
              @endif
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
              @if(!empty($req['cancelRequested']))
                <span class="mail-status mail-status-suspended">{{ __('解約依頼中') }}</span>
              @endif
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
