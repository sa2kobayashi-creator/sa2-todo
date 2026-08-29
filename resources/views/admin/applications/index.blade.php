<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('利用申請管理') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-applications'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <h2>{{ __('利用申請') }}</h2>
        <p class="hint">{{ __('TOP からの申請です。ライト（お試し）は自動審査のため、ここには出ません。スタンダード／テナントのみ手動で承認または却下します。招待コード経路とは別です。') }}</p>
        <ol class="hint" style="margin:8px 0 0;padding-left:1.4em;">
          <li>{{ __('対応待ちはスタンダード／テナントのみ。内容を確認して承認または却下') }}</li>
          <li>{{ __('承認: 申請者がメールのリンクからパスワードを設定 → 利用開始') }}</li>
          <li>{{ __('スタンダード: 登録後にプラン画面へ進み、カード登録') }}</li>
          <li>{{ __('テナント: 登録後はライトでログイン。契約作成はテナント契約画面で') }}</li>
          <li>{{ __('ライト: 目的・捨てアド・週上限を自動審査し、通過時は登録メールを即送信') }}</li>
        </ol>
      </div>

      <div class="panel">
        <h3>{{ __('対応待ち') }} ({{ $pending->count() }})</h3>
        @forelse($pending as $app)
          <article class="mail-admin-request">
            <header>
              <strong>{{ $app->display_name }}</strong>
              <span class="mail-status mail-status-pending">{{ __($app->status->label()) }}</span>
              <span class="hint">{{ __($app->plan->label()) }}</span>
            </header>
            <p>{{ __('メール') }}: {{ $app->email }}</p>
            @if($app->organization_name)
              <p>{{ __('組織・家族名') }}: {{ $app->organization_name }}</p>
            @endif
            @if($app->phone)
              <p>{{ __('電話') }}: {{ $app->phone }}</p>
            @endif
            @if($app->message)
              <p class="hint" style="white-space:pre-wrap;">{{ $app->message }}</p>
            @endif
            <p class="hint">{{ $app->created_at?->format('Y-m-d H:i') }}</p>
            <form method="post" class="mail-admin-actions">
              @csrf
              <label>{{ __('管理者メモ') }}<input type="text" name="admin_note" value="{{ $app->admin_note }}" maxlength="500" /></label>
              <button formaction="/admin/applications/{{ $app->id }}/approve">{{ __('承認してメール送信') }}</button>
              <button formaction="/admin/applications/{{ $app->id }}/reject" class="secondary">{{ __('却下') }}</button>
            </form>
          </article>
        @empty
          <p class="hint">{{ __('対応待ちの申請はありません。') }}</p>
        @endforelse
      </div>

      <div class="panel">
        <h3>{{ __('最近の申請') }}</h3>
        @forelse($recent as $app)
          <article class="mail-admin-request">
            <header>
              <strong>{{ $app->display_name }}</strong>
              <span class="mail-status">{{ __($app->status->label()) }}</span>
              <span class="hint">{{ __($app->plan->label()) }}</span>
            </header>
            <p>{{ $app->email }}</p>
            @if($app->admin_note)
              <p class="hint">{{ __('メモ') }}: {{ $app->admin_note }}</p>
            @endif
            <p class="hint">{{ $app->reviewed_at?->format('Y-m-d H:i') ?? $app->updated_at?->format('Y-m-d H:i') }}</p>
            @if($app->isAwaitingActivation())
              <form method="post" action="/admin/applications/{{ $app->id }}/resend" class="mail-admin-actions">
                @csrf
                <button type="submit" class="secondary">{{ __('登録メールを再送') }}</button>
              </form>
            @endif
          </article>
        @empty
          <p class="hint">{{ __('まだ処理済みの申請はありません。') }}</p>
        @endforelse
      </div>
    </main>
  </body>
</html>
