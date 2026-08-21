<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('専用インスタンス見積') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-sales'])
    <main class="page-main page-main-narrow sales-estimate-page">
      @include('partials.settings-subnav', ['active' => 'admin-sales'])

      <div class="panel sales-estimate-controls no-print">
        <h2>{{ __('見積条件') }}</h2>
        <p class="hint">{{ __('数字を変えて「反映」すると下の見積プレビューが更新されます。印刷してお客様に渡せます。') }}</p>
        <form method="get" action="/admin/sales/estimate" class="stack-form sales-estimate-form">
          <label>{{ __('お客様名') }}
            <input type="text" name="client_name" value="{{ $form['client_name'] }}" maxlength="120" placeholder="{{ __('株式会社サンプル') }}" />
          </label>
          <label>{{ __('ユーザー数') }}
            <input type="number" name="users" value="{{ $form['users'] }}" min="1" max="200" required />
          </label>
          <label>{{ __('保守の請求') }}
            <select name="maintenance_cycle">
              <option value="monthly" @selected($form['maintenance_cycle'] === 'monthly')>{{ __('月額') }}</option>
              <option value="yearly" @selected($form['maintenance_cycle'] === 'yearly')>{{ __('年額（12か月分で1か月分オフ）') }}</option>
            </select>
          </label>
          <label class="menu-feature-check">
            <input type="checkbox" name="include_mailbox" value="1" @checked(!empty($form['include_mailbox'])) />
            <span>{{ __('メールボックス（@sa2-plus.com）を含める') }}</span>
          </label>
          <label>{{ __('備考（見積に印刷）') }}
            <textarea name="notes" rows="3" maxlength="2000">{{ $form['notes'] }}</textarea>
          </label>
          <div class="sales-estimate-actions">
            <button type="submit">{{ __('反映') }}</button>
            <button type="button" class="secondary" onclick="window.print()">{{ __('印刷') }}</button>
          </div>
        </form>
      </div>

      <article class="panel sales-estimate-sheet" id="sales-estimate-sheet">
        <header class="sales-estimate-head">
          <p class="sales-estimate-brand">{{ $estimate['productName'] }}</p>
          <h1>{{ __('専用インスタンス お見積書') }}</h1>
          <p class="hint">{{ __('発行日') }}: {{ $estimate['issuedAt'] }}</p>
          @if($estimate['clientName'] !== '')
            <p class="sales-estimate-client">{{ __('宛先') }}: {{ $estimate['clientName'] }} 様</p>
          @else
            <p class="sales-estimate-client hint">{{ __('宛先: （お客様名を上で入力）') }}</p>
          @endif
        </header>

        <p class="sales-estimate-lead">
          {{ __('家族・小組織向けの Todo／メモ／写真／メッセージ／メールを、お客様専用の環境で提供します。ストレージはお客様クラウド（BYOK）を推奨します。') }}
        </p>

        <table class="sales-estimate-table">
          <thead>
            <tr>
              <th>{{ __('項目') }}</th>
              <th>{{ __('内容') }}</th>
              <th class="num">{{ __('金額（税別）') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach($estimate['lines'] as $line)
              <tr>
                <td>{{ $line['label'] }}</td>
                <td class="hint">{{ $line['note'] }}</td>
                <td class="num">¥{{ number_format((int) $line['amount']) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>

        <dl class="sales-estimate-totals">
          <div>
            <dt>{{ __('初期（お支払い1回）') }}</dt>
            <dd>¥{{ number_format((int) $estimate['initialTotal']) }}</dd>
          </div>
          <div>
            <dt>{{ $estimate['recurringLabel'] }}</dt>
            <dd>¥{{ number_format((int) $estimate['recurringTotal']) }}</dd>
          </div>
        </dl>

        @if($estimate['notes'] !== '')
          <section class="sales-estimate-notes">
            <h2>{{ __('備考') }}</h2>
            <p>{{ $estimate['notes'] }}</p>
          </section>
        @endif

        <ul class="sales-estimate-bullets hint">
          <li>{{ __('含まれるもの: 専用環境、Admin 1名＋利用者、バックアップ方針の説明、合意したアップデート') }}</li>
          <li>{{ __('含まれないもの: Travel／鮮明化／DeepL・LLM の使い放題、大規模データ移行、24時間電話サポート') }}</li>
          <li>{{ __('クラウド利用料（R2 等）は BYOK の場合お客様負担です') }}</li>
          <li>{{ $estimate['taxNote'] }}</li>
        </ul>
      </article>
    </main>
  </body>
</html>
