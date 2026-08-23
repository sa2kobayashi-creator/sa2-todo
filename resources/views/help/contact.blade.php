<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('お問い合わせ') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'contact'])
    <main class="page-main page-main-narrow">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @include('partials.settings-subnav', ['active' => 'contact'])

      <div class="panel">
        <h1>{{ __('お問い合わせ') }}</h1>
        <p class="hint">{{ __('このアプリの運営者へ連絡します。返信は登録メールアドレスへ送ります。') }}</p>
        <form method="post" action="/contact" class="stack-form">
          @csrf
          <label>{{ __('件名') }}
            <input type="text" name="subject" value="{{ old('subject') }}" required maxlength="120" />
          </label>
          @error('subject')
            <p class="hint storage-test-result is-fail">{{ $message }}</p>
          @enderror
          <label>{{ __('内容') }}
            <textarea name="body" rows="8" required maxlength="5000">{{ old('body') }}</textarea>
          </label>
          @error('body')
            <p class="hint storage-test-result is-fail">{{ $message }}</p>
          @enderror
          <div class="modal-actions" style="justify-content:flex-start;">
            <button type="submit">{{ __('送信する') }}</button>
          </div>
        </form>
      </div>
    </main>
  </body>
</html>
