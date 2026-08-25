<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('テナント契約') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-tenants'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <h2>{{ __('テナント契約') }}</h2>
        <p class="hint">{{ __('共有サーバー上で契約者ごとにユーザーとストレージ／AI／API キーを分けます。中身は専用インスタンスと同じです。公開料金は最初の:days日無料、その後 ¥:monthly／月（:count名まで・メール各1込み）。年額 ¥:yearly（:monthsか月分）。6人目以降は運営が上限を上げます（追加 ¥:yen／人／月）。', ['days' => $tenantTrialDays ?? 30, 'monthly' => number_format((int) ($tenantMonthlyYen ?? 3980)), 'count' => $includedUsers ?? 5, 'yearly' => number_format((int) ($tenantYearlyYen ?? 43780)), 'months' => 11, 'yen' => $extraUserYen ?? 1000]) }}</p>
      </div>

      <div class="panel">
        <h2>{{ __('契約を追加') }}</h2>
        <form method="post" action="/admin/tenants" class="stack-form admin-user-form">
          @csrf
          <label>{{ __('契約名') }}
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="{{ __('山田家') }}" />
          </label>
          <label>{{ __('ユーザー上限') }}
            <input type="number" name="max_users" value="{{ old('max_users', $includedUsers ?? 5) }}" min="1" max="200" required />
          </label>
          <label class="menu-feature-check">
            <input type="hidden" name="allow_own_keys" value="0" />
            <input type="checkbox" name="allow_own_keys" value="1" @checked(old('allow_own_keys', '1') === '1') />
            <span>{{ __('契約代表が R2／B2／AI／API キーを自分で設定できる') }}</span>
          </label>
          <label>{{ __('試用終了日') }}
            <input type="date" name="trial_ends_at" value="{{ old('trial_ends_at', $defaultTrialEndsAt) }}" />
          </label>
          <p class="hint">{{ __('空にすると試用なし（本契約）です。公開プランの既定は :days 日です。', ['days' => $tenantTrialDays ?? 30]) }}</p>
          <label>{{ __('契約代表の表示名') }}
            <input type="text" name="owner_display_name" value="{{ old('owner_display_name') }}" required maxlength="100" />
          </label>
          <label>{{ __('契約代表のメールアドレス') }}
            <input type="email" name="owner_email" value="{{ old('owner_email') }}" required maxlength="255" />
          </label>
          <label>{{ __('契約代表の初期パスワード') }}
            <input type="password" name="owner_password" required minlength="8" />
          </label>
          <label>{{ __('メモ（運営用）') }}
            <textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
          </label>
          <button type="submit">{{ __('契約を作成') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('契約一覧') }} ({{ count($tenants) }})</h2>
        @if(count($tenants) === 0)
          <p class="hint">{{ __('まだテナント契約はありません。') }}</p>
        @else
          <div class="admin-users-table-wrap">
            <table class="admin-users-table admin-users-table-readonly">
              <thead>
                <tr>
                  <th scope="col">{{ __('契約名') }}</th>
                  <th scope="col">{{ __('代表') }}</th>
                  <th scope="col">{{ __('人数') }}</th>
                  <th scope="col">{{ __('試用') }}</th>
                  <th scope="col">{{ __('状態') }}</th>
                  <th scope="col">{{ __('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($tenants as $tenant)
                  <tr>
                    <td><strong>{{ $tenant['name'] }}</strong></td>
                    <td>{{ $tenant['ownerName'] ?? '—' }}<br><span class="hint">{{ $tenant['ownerEmail'] ?? '' }}</span></td>
                    <td>{{ $tenant['userCount'] }} / {{ $tenant['maxUsers'] }}</td>
                    <td>{{ $tenant['trialStatusLabel'] ?? '—' }}</td>
                    <td>{{ $tenant['statusLabel'] }}</td>
                    <td><a href="/admin/tenants/{{ $tenant['id'] }}" class="secondary mini-btn">{{ __('詳細') }}</a></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>
    </main>
  </body>
</html>
