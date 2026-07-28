<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('Travel') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ @filemtime(public_path('app.css')) ?: time() }}" />
  </head>
  <body class="travel-page">
    @include('partials.header', ['active' => 'travel'])
    <main class="page-main travel-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <header class="travel-page-head">
        <div>
          <h1 class="travel-page-title">{{ __('Travel') }}</h1>
          <p class="travel-page-lead">
            {{ __('FUK⇔MNL（セブパシ）の渡航管理。RP・Annual Report 期限と、往復/片道×2の料金比較を記録します。支払い基本は PHP。') }}
          </p>
        </div>
      </header>

      @php
        $ar = $deadlines['annualReport'] ?? [];
        $rp = $deadlines['rp'] ?? [];
        $next = $deadlines['nextDeadline'] ?? null;
      @endphp

      <section class="travel-deadline-grid" aria-label="{{ __('手続き期限') }}">
        <article class="travel-deadline-card{{ !empty($ar['danger']) ? ' is-danger' : (!empty($ar['warn']) ? ' is-warn' : '') }}">
          <h2>{{ $ar['label'] ?? 'Annual Report' }}</h2>
          <p class="travel-deadline-date">{{ $ar['deadline'] ?? '—' }}</p>
          <p class="travel-deadline-days">
            @if(($ar['daysLeft'] ?? null) === null)
              —
            @elseif(($ar['daysLeft'] ?? 0) < 0)
              {{ __('期限超過 :n 日', ['n' => abs((int) $ar['daysLeft'])]) }}
            @else
              {{ __('あと :n 日', ['n' => (int) $ar['daysLeft']]) }}
            @endif
            @if(!empty($ar['doneThisYear']))
              <span class="travel-badge">{{ __('今年済') }}</span>
            @endif
          </p>
          <p class="travel-deadline-hint">{{ $ar['hint'] ?? '' }}</p>
        </article>

        <article class="travel-deadline-card{{ !empty($rp['danger']) ? ' is-danger' : (!empty($rp['warn']) ? ' is-warn' : '') }}">
          <h2>{{ $rp['label'] ?? 'RP' }}</h2>
          <p class="travel-deadline-date">{{ $rp['deadline'] ?? __('未設定') }}</p>
          <p class="travel-deadline-days">
            @if(empty($rp['deadline']))
              {{ __('プロフィールで RP 期限を登録してください') }}
            @elseif(($rp['daysLeft'] ?? 0) < 0)
              {{ __('期限超過 :n 日', ['n' => abs((int) $rp['daysLeft'])]) }}
            @else
              {{ __('あと :n 日', ['n' => (int) $rp['daysLeft']]) }}
              <span class="travel-badge">{{ ($rp['durationMonths'] ?? 6) }}{{ __('か月RP') }}</span>
            @endif
          </p>
          <p class="travel-deadline-hint">{{ $rp['hint'] ?? '' }}</p>
        </article>
      </section>

      @if($next)
        <div class="travel-next-banner">
          <strong>{{ __('次の必須アクション') }}:</strong>
          {{ $next['label'] ?? '' }}
          （{{ $next['deadline'] ?? '' }} /
          @if(($next['daysLeft'] ?? 0) < 0)
            {{ __('超過') }}
          @else
            {{ __('残り :n 日', ['n' => (int) $next['daysLeft']]) }}
          @endif
          ）
        </div>
      @endif

      @if(!empty($deadlines['tips']))
        <ul class="travel-tips">
          @foreach($deadlines['tips'] as $tip)
            <li>{{ $tip }}</li>
          @endforeach
        </ul>
      @endif

      @php $unreadAlerts = collect($alerts ?? [])->where('isRead', false); @endphp
      @if($unreadAlerts->isNotEmpty())
        <section class="panel travel-panel travel-alerts-panel">
          <div class="travel-section-head">
            <h2 class="travel-section-title">{{ __('未読アラート') }}</h2>
            <form method="post" action="/travel/alerts/read-all">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <button type="submit" class="secondary">{{ __('すべて既読') }}</button>
            </form>
          </div>
          <ul class="travel-alert-list">
            @foreach($unreadAlerts as $alert)
              <li class="travel-alert-item is-{{ $alert['severity'] }}">
                <div>
                  <strong>{{ $alert['title'] }}</strong>
                  @if($alert['body'] !== '')
                    <p>{{ $alert['body'] }}</p>
                  @endif
                  <span class="travel-alert-time">{{ $alert['createdAt'] }}</span>
                </div>
                <form method="post" action="/travel/alerts/{{ $alert['id'] }}/read">
                  @csrf
                  <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                  <button type="submit" class="secondary">{{ __('既読') }}</button>
                </form>
              </li>
            @endforeach
          </ul>
        </section>
      @endif

      <section class="panel travel-panel">
        <details class="travel-accordion">
          <summary class="travel-accordion-summary">
            <span class="travel-section-title">{{ __('プロフィール・期限') }}</span>
            <span class="hint">{{ __('クリックして開く') }}</span>
          </summary>
          <form method="post" action="/travel/profile" class="travel-form">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <div class="travel-form-grid">
              <label>{{ __('ビザ') }}
                <input type="text" name="visaType" value="{{ $profile['visaType'] }}" />
              </label>
              <label>{{ __('RP期限') }}
                <input type="date" name="rpExpiresOn" value="{{ $profile['rpExpiresOn'] }}" />
              </label>
              <label>{{ __('RP期間') }}
                <select name="rpDurationMonths">
                  <option value="6" @selected((int) $profile['rpDurationMonths'] === 6)>{{ __('6か月') }}</option>
                  <option value="12" @selected((int) $profile['rpDurationMonths'] === 12)>{{ __('1年') }}</option>
                </select>
              </label>
              <label>{{ __('Annual Report 完了年') }}
                <input type="number" name="annualReportDoneYear" min="2020" max="2100" step="1" value="{{ $profile['annualReportDoneYear'] }}" placeholder="{{ date('Y') }}" />
              </label>
              <label>{{ __('予算上限（円換算目安）') }}
                <input type="number" name="budgetMaxJpy" min="1" step="1" value="{{ $profile['budgetMaxJpy'] }}" />
              </label>
              <label>{{ __('優先通貨') }}
                <select name="preferredCurrency">
                  <option value="PHP" @selected($profile['preferredCurrency'] === 'PHP')>PHP</option>
                  <option value="JPY" @selected($profile['preferredCurrency'] === 'JPY')>JPY</option>
                </select>
              </label>
              <label>{{ __('日本側空港') }}
                <input type="text" name="homeAirport" value="{{ $profile['homeAirport'] }}" maxlength="8" />
              </label>
              <label>{{ __('フィリピン側空港') }}
                <input type="text" name="phAirport" value="{{ $profile['phAirport'] }}" maxlength="8" />
              </label>
              <label>{{ __('航空会社コード') }}
                <input type="text" name="airlineCode" value="{{ $profile['airlineCode'] }}" maxlength="8" />
              </label>
              <label class="travel-check">
                <input type="checkbox" name="alertsEnabled" value="1" @checked(!empty($profile['alertsEnabled'])) />
                {{ __('期限アラートを有効') }}
              </label>
              <label>{{ __('RPアラート（日前）') }}
                <input type="number" name="alertDaysRp" min="1" max="365" step="1" value="{{ $profile['alertDaysRp'] }}" />
              </label>
              <label>{{ __('ARアラート（日前）') }}
                <input type="number" name="alertDaysAr" min="1" max="365" step="1" value="{{ $profile['alertDaysAr'] }}" />
              </label>
            </div>
            <label class="travel-form-notes">{{ __('メモ') }}
              <textarea name="notes" rows="2">{{ $profile['notes'] }}</textarea>
            </label>
            <div class="travel-form-actions">
              <button type="submit" class="button-link">{{ __('プロフィールを保存') }}</button>
            </div>
          </form>
        </details>
      </section>

      <section class="panel travel-panel" id="travel-fare-table">
        <h2 class="travel-section-title">{{ __('安い日を探す（料金表）') }}</h2>
        <p class="hint">{{ __('期間を指定して Travelpayouts の月次キャッシュから運賃目安を一覧表示します。片道は出発日ごと、往復は行き×帰りの組み合わせ表です。') }}</p>

        @php $ft = $fareTable ?? null; @endphp

        <form method="post" action="/travel/fares/table" class="travel-form" id="travel-fare-table-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <div class="travel-form-grid">
            <label>{{ __('検索形態') }}
              <select name="tableMode" id="travel-fare-table-mode">
                <option value="ow" @selected(($ft['mode'] ?? 'ow') === 'ow')>{{ __('片道') }}</option>
                <option value="rt" @selected(($ft['mode'] ?? '') === 'rt')>{{ __('往復') }}</option>
              </select>
            </label>
            <label>{{ __('表示通貨') }}
              <select name="tableCurrency">
                <option value="PHP" @selected(($ft['currency'] ?? $profile['preferredCurrency'] ?? 'PHP') === 'PHP')>PHP</option>
                <option value="JPY" @selected(($ft['currency'] ?? '') === 'JPY')>JPY</option>
              </select>
            </label>
            <label>{{ __('出発期間（開始）') }}
              <input type="date" name="departFrom" value="{{ $ft['departFrom'] ?? '' }}" required />
            </label>
            <label>{{ __('出発期間（終了）') }}
              <input type="date" name="departTo" value="{{ $ft['departTo'] ?? '' }}" required />
            </label>
            <label class="travel-fare-return-field">{{ __('帰国期間（開始）') }}
              <input type="date" name="returnFrom" value="{{ $ft['returnFrom'] ?? '' }}" />
            </label>
            <label class="travel-fare-return-field">{{ __('帰国期間（終了）') }}
              <input type="date" name="returnTo" value="{{ $ft['returnTo'] ?? '' }}" />
            </label>
          </div>
          <p class="hint">{{ __('例）片道: 2026/10/01〜10/30 / 往復: 行き 10/01〜10/30、帰り 10/15〜11/15（各期間最大62日）') }}</p>
          <p class="hint">{{ __('データがない日は「—」になります。エラーにはせず、取れた日だけ金額を表示します。') }}</p>
          <div class="travel-form-actions">
            <button type="submit" class="button-link">{{ __('料金表を表示') }}</button>
          </div>
        </form>
        @if(is_array($ft))
          <form method="post" action="/travel/fares/table/clear" class="inline-form travel-fare-clear-form">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <button type="submit" class="secondary">{{ __('クリア') }}</button>
          </form>
        @endif

        @if(is_array($ft))
          <div class="travel-fare-table-result">
            <p class="travel-trip-route">
              @if($ft['mode'] === 'ow')
                {{ __('片道') }} {{ $ft['origin'] }}→{{ $ft['destination'] }}
                {{ $ft['departFrom'] }}〜{{ $ft['departTo'] }}
              @else
                {{ __('往復') }} {{ $ft['origin'] }}⇔{{ $ft['destination'] }}
                · {{ __('行き') }} {{ $ft['departFrom'] }}〜{{ $ft['departTo'] }}
                · {{ __('帰り') }} {{ $ft['returnFrom'] }}〜{{ $ft['returnTo'] }}
              @endif
              · {{ $ft['airlineCode'] }} · {{ $ft['fetchedAt'] }}
            </p>

            @foreach(($ft['notes'] ?? []) as $note)
              <p class="hint">{{ $note }}</p>
            @endforeach
            @foreach(($ft['warnings'] ?? []) as $warn)
              <p class="hint travel-quote-warn">{{ $warn }}</p>
            @endforeach

            @if(!empty($ft['cheapest']))
              <div class="travel-fare-cheapest">
                <h3>{{ __('最安候補') }}</h3>
                <ul>
                  @foreach($ft['cheapest'] as $item)
                    <li>
                      @if($ft['mode'] === 'ow')
                        <strong>{{ $item['departOn'] }}</strong>
                        @if(($ft['currency'] ?? 'PHP') === 'JPY')
                          ¥{{ number_format((int) ($item['priceJpy'] ?? 0)) }}
                        @else
                          ₱{{ number_format((int) ($item['pricePhp'] ?? 0)) }}
                        @endif
                      @else
                        <strong>{{ $item['departOn'] }} → {{ $item['returnOn'] }}</strong>
                        @if(($ft['currency'] ?? 'PHP') === 'JPY')
                          ¥{{ number_format((int) ($item['priceJpy'] ?? 0)) }}
                        @else
                          ₱{{ number_format((int) ($item['pricePhp'] ?? 0)) }}
                        @endif
                        <span class="hint">({{ $item['source'] === 'calendar' ? __('往復キャッシュ') : __('片道合計') }})</span>
                      @endif
                      @if(!empty($item['airline']))
                        · {{ $item['airline'] }}
                      @endif
                    </li>
                  @endforeach
                </ul>
              </div>
            @endif

            @if(($ft['mode'] ?? '') === 'ow')
              <div class="travel-snapshot-table-wrap">
                <table class="travel-snapshot-table travel-fare-table">
                  <thead>
                    <tr>
                      <th>{{ __('出発日') }}</th>
                      <th>PHP</th>
                      <th>JPY</th>
                      <th>{{ __('航空会社') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($ft['rows'] as $row)
                      <tr @class([
                        'is-cheapest' => !empty($row['isCheapest']),
                        'is-filled' => !empty($row['isFilled']),
                      ])>
                        <td>{{ $row['departOn'] }}</td>
                        <td>{{ $row['pricePhp'] !== null ? '₱'.number_format($row['pricePhp']) : '—' }}</td>
                        <td>{{ $row['priceJpy'] !== null ? '¥'.number_format($row['priceJpy']) : '—' }}</td>
                        <td>{{ $row['airline'] !== '' ? $row['airline'] : '—' }}</td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            @elseif(!empty($ft['matrix']))
              @php
                $matrix = $ft['matrix'];
                $cellMap = [];
                foreach ($matrix['cells'] as $cell) {
                  $cellMap[$cell['departOn']][$cell['returnOn']] = $cell;
                }
                $minPrice = null;
                foreach ($matrix['cells'] as $cell) {
                  $p = ($ft['currency'] ?? 'PHP') === 'JPY' ? $cell['priceJpy'] : $cell['pricePhp'];
                  if ($p !== null && ($minPrice === null || $p < $minPrice)) {
                    $minPrice = $p;
                  }
                }
              @endphp
              <div class="travel-fare-matrix-wrap">
                <table class="travel-fare-matrix">
                  <thead>
                    <tr>
                      <th>{{ __('行き＼帰り') }}</th>
                      @foreach($matrix['returnDates'] as $returnDate)
                        <th>{{ substr($returnDate, 5) }}</th>
                      @endforeach
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($matrix['departDates'] as $departDate)
                      <tr>
                        <th>{{ substr($departDate, 5) }}</th>
                        @foreach($matrix['returnDates'] as $returnDate)
                          @php
                            $cell = $cellMap[$departDate][$returnDate] ?? null;
                            $price = null;
                            if ($cell) {
                              $price = ($ft['currency'] ?? 'PHP') === 'JPY' ? $cell['priceJpy'] : $cell['pricePhp'];
                            }
                          @endphp
                          <td @class(['is-empty' => $cell === null, 'is-cheapest' => $price !== null && $minPrice !== null && $price === $minPrice])>
                            @if($cell && $price !== null)
                              @if(($ft['currency'] ?? 'PHP') === 'JPY')
                                ¥{{ number_format($price) }}
                              @else
                                ₱{{ number_format($price) }}
                              @endif
                            @else
                              —
                            @endif
                          </td>
                        @endforeach
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
              @if(empty($matrix['cells']))
                <p class="hint">{{ __('この期間では利用可能な往復キャッシュがまだありません。') }}</p>
              @endif
              <p class="hint">{{ __('往復キャッシュがある場合はその価格、ない場合は行き＋帰りの片道合計を表示しています。') }}</p>
            @endif
          </div>
        @endif
      </section>

      <section class="panel travel-panel">
        <h2 class="travel-section-title">{{ __('渡航予定を追加') }}</h2>
        <p class="hint">
          {{ __('運賃は Travelpayouts API のキャッシュ価格（税込目安）です。Cebu Pacific 公式と差が出ることがあるので、取得後に公式で確認して金額を直してから追加してください。') }}
        </p>

        @php
          $draft = $tripDraft ?? null;
          $q = is_array($draft) ? ($draft['quote'] ?? null) : null;
        @endphp

        <form method="post" action="/travel/trips/quote" class="travel-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <div class="travel-form-grid">
            <label>{{ __('目的') }}
              <select name="purpose">
                @foreach($purposeLabels as $key => $label)
                  <option value="{{ $key }}" @selected(($draft['purpose'] ?? 'other') === $key)>{{ $label }}</option>
                @endforeach
              </select>
            </label>
            <label>{{ __('ラベル') }}
              <input type="text" name="label" value="{{ $draft['label'] ?? '' }}" placeholder="{{ __('例: 2027 AR') }}" />
            </label>
            <label>{{ __('日本発（FUK→MNL）') }}
              <input type="date" name="departOn" value="{{ $q['departOn'] ?? '' }}" required />
            </label>
            <label>{{ __('帰国（MNL→FUK）') }}
              <input type="date" name="returnOn" value="{{ $q['returnOn'] ?? '' }}" />
            </label>
            <label>{{ __('状態') }}
              <select name="status">
                @foreach($statusLabels as $key => $label)
                  <option value="{{ $key }}" @selected(($draft['status'] ?? 'planned') === $key)>{{ $label }}</option>
                @endforeach
              </select>
            </label>
            <label>{{ __('予約形態') }}
              <select name="bookedAs" required>
                <option value="rt" @selected(($draft['bookedAs'] ?? 'rt') === 'rt')>{{ __('往復（RT）') }}</option>
                <option value="ow_pair" @selected(($draft['bookedAs'] ?? '') === 'ow_pair')>{{ __('片道（OW）') }}</option>
              </select>
            </label>
          </div>
          <p class="hint">{{ __('往復（RT）は帰国日必須。片道（OW）は帰国日を省略可（入れると行き・帰り片道×2として取得）。') }}</p>
          <div class="travel-form-actions">
            <button type="submit" class="button-link">{{ __('運賃を取得') }}</button>
          </div>
        </form>

        @if(is_array($q))
          @php $bookedAs = $draft['bookedAs'] ?? 'rt'; @endphp
          <div class="travel-quote-preview">
            <div class="travel-section-head">
              <h3 class="travel-quote-title">{{ __('取得した運賃（確認）') }}</h3>
              <form method="post" action="/travel/trips/draft/clear">
                @csrf
                <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                <button type="submit" class="secondary">{{ __('破棄') }}</button>
              </form>
            </div>

            <p class="travel-trip-route">
              <span class="travel-badge">{{ $bookedAs === 'ow_pair' ? __('片道（OW）') : __('往復（RT）') }}</span>
              {{ $q['origin'] }}→{{ $q['destination'] }} {{ $q['departOn'] }}
              @if(!empty($q['returnOn']))
                / {{ $q['destination'] }}→{{ $q['origin'] }} {{ $q['returnOn'] }}
              @endif
              · {{ $q['airlineCode'] }}
              · {{ $q['source'] }}
              （{{ $q['fetchedAt'] }}）
            </p>

            @foreach(($q['notes'] ?? []) as $note)
              <p class="hint">{{ $note }}</p>
            @endforeach
            @foreach(($q['warnings'] ?? []) as $warn)
              <p class="hint travel-quote-warn">{{ $warn }}</p>
            @endforeach

            <div class="travel-official-box">
              <p><strong>{{ __('公式金額の確認（推奨）') }}</strong></p>
              <p class="hint">{{ __('下のリンクで Cebu Pacific 公式（PHPサイト推奨）を開き、税込金額を確認してから下の欄を上書きしてください。会員ログインが必要な場合は公式側でログインしてください（当アプリでは航空会社のID/パスワードを保存しません）。') }}</p>
              <ul class="travel-watch-sources">
                <li>
                  <a href="https://www.cebupacificair.com/en-PH" target="_blank" rel="noopener noreferrer">{{ __('Cebu Pacific 公式（PH / PHP）') }}</a>
                  <span class="travel-badge">{{ __('推奨') }}</span>
                </li>
                <li>
                  <a href="https://www.cebupacificair.com/en-JP" target="_blank" rel="noopener noreferrer">{{ __('Cebu Pacific 公式（JP / JPY）') }}</a>
                </li>
                @if(!empty($q['sourceUrl']))
                  <li>
                    <a href="{{ $q['sourceUrl'] }}" target="_blank" rel="noopener noreferrer">{{ __('参考: Aviasales / Travelpayouts') }}</a>
                    <span class="travel-badge travel-badge-muted">{{ __('目安') }}</span>
                  </li>
                @endif
              </ul>
              <p class="hint">
                {{ __('検索条件') }}:
                {{ $q['origin'] }}→{{ $q['destination'] }} {{ $q['departOn'] }}
                @if(!empty($q['returnOn']))
                  / 帰り {{ $q['returnOn'] }}
                @endif
                · {{ $bookedAs === 'ow_pair' ? __('片道（OW）') : __('往復（RT）') }}
              </p>
            </div>

            <dl class="travel-compare-dl">
              <div>
                <dt>PHP</dt>
                <dd>
                  RT {{ $q['rtPricePhp'] !== null ? '₱'.number_format($q['rtPricePhp']) : '—' }}
                  /
                  OW×2 {{ $q['owSumPhp'] !== null ? '₱'.number_format($q['owSumPhp']) : '—' }}
                  <strong class="travel-winner">{{ $q['comparePhp']['label'] ?? '' }}</strong>
                </dd>
              </div>
              <div>
                <dt>JPY</dt>
                <dd>
                  RT {{ $q['rtPriceJpy'] !== null ? '¥'.number_format($q['rtPriceJpy']) : '—' }}
                  /
                  OW×2 {{ $q['owSumJpy'] !== null ? '¥'.number_format($q['owSumJpy']) : '—' }}
                  <strong class="travel-winner">{{ $q['compareJpy']['label'] ?? '' }}</strong>
                </dd>
              </div>
            </dl>

            <form method="post" action="/travel/trips" class="travel-form">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <input type="hidden" name="purpose" value="{{ $draft['purpose'] ?? 'other' }}" />
              <input type="hidden" name="label" value="{{ $draft['label'] ?? '' }}" />
              <input type="hidden" name="status" value="{{ $draft['status'] ?? 'planned' }}" />
              <input type="hidden" name="departOn" value="{{ $q['departOn'] }}" />
              <input type="hidden" name="returnOn" value="{{ $q['returnOn'] }}" />
              <input type="hidden" name="origin" value="{{ $q['origin'] }}" />
              <input type="hidden" name="destination" value="{{ $q['destination'] }}" />
              <input type="hidden" name="airlineCode" value="{{ $q['airlineCode'] }}" />
              <input type="hidden" name="preferCurrency" value="{{ $draft['preferCurrency'] ?? 'PHP' }}" />

              <div class="travel-form-grid">
                <label>{{ __('予約形態') }}
                  <select name="bookedAs" required>
                    <option value="rt" @selected($bookedAs === 'rt')>{{ __('往復（RT）') }}</option>
                    <option value="ow_pair" @selected($bookedAs === 'ow_pair')>{{ __('片道（OW）') }}</option>
                  </select>
                </label>
                <label title="{{ __('Round Trip（往復一括予約）の税込合計を、フィリピンペソ（PHP）で入力します。') }}">
                  <span class="travel-field-label">RT PHP <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                  <input type="number" name="rtPricePhp" min="0" step="1" value="{{ $q['rtPricePhp'] }}" />
                </label>
                <label title="{{ __('One Way（片道）・日本発 FUK→MNL の税込運賃を PHP で入力します。') }}">
                  <span class="travel-field-label">OW行き PHP <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                  <input type="number" name="owOutPricePhp" min="0" step="1" value="{{ $q['owOutPricePhp'] }}" />
                </label>
                <label title="{{ __('One Way（片道）・帰国 MNL→FUK の税込運賃を PHP で入力します。') }}">
                  <span class="travel-field-label">OW帰り PHP <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                  <input type="number" name="owBackPricePhp" min="0" step="1" value="{{ $q['owBackPricePhp'] }}" />
                </label>
                <label title="{{ __('Round Trip（往復一括予約）の税込合計を、日本円（JPY）で入力します。') }}">
                  <span class="travel-field-label">RT JPY <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                  <input type="number" name="rtPriceJpy" min="0" step="1" value="{{ $q['rtPriceJpy'] }}" />
                </label>
                <label title="{{ __('One Way（片道）・日本発 FUK→MNL の税込運賃を JPY で入力します。') }}">
                  <span class="travel-field-label">OW行き JPY <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                  <input type="number" name="owOutPriceJpy" min="0" step="1" value="{{ $q['owOutPriceJpy'] }}" />
                </label>
                <label title="{{ __('One Way（片道）・帰国 MNL→FUK の税込運賃を JPY で入力します。') }}">
                  <span class="travel-field-label">OW帰り JPY <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                  <input type="number" name="owBackPriceJpy" min="0" step="1" value="{{ $q['owBackPriceJpy'] }}" />
                </label>
                <label class="travel-check">
                  <input type="checkbox" name="outBookedInPhp" value="1" />
                  {{ __('行きを PHP で購入済') }}
                </label>
                <label class="travel-check">
                  <input type="checkbox" name="backBookedInPhp" value="1" />
                  {{ __('帰りを PHP で購入済') }}
                </label>
              </div>
              <label class="travel-form-notes">{{ __('メモ') }}
                <textarea name="notes" rows="2">{{ __('自動取得: :src :at', ['src' => $q['source'], 'at' => $q['fetchedAt']]) }}</textarea>
              </label>
              <div class="travel-form-actions">
                <button type="submit" class="button-link">{{ __('この内容で渡航予定に追加') }}</button>
              </div>
            </form>
          </div>
        @endif
      </section>

      <section class="panel travel-panel">
        <h2 class="travel-section-title">{{ __('渡航一覧・料金比較') }}</h2>
        @if(count($trips) === 0)
          <p class="hint">{{ __('まだ渡航予定がありません。Annual Report / RP 更新の予定から追加してください。') }}</p>
        @else
          <div class="travel-trip-list">
            @foreach($trips as $trip)
              <article class="travel-trip-card">
                <header class="travel-trip-card-head">
                  <div>
                    <h3>
                      {{ $trip['label'] !== '' ? $trip['label'] : $trip['purposeLabel'] }}
                      <span class="travel-badge">{{ $trip['statusLabel'] }}</span>
                    </h3>
                    <p class="travel-trip-route">
                      {{ $trip['origin'] }}→{{ $trip['destination'] }}
                      {{ $trip['departOn'] }}
                      @if($trip['returnOn'])
                        / {{ $trip['destination'] }}→{{ $trip['origin'] }} {{ $trip['returnOn'] }}
                      @endif
                      · {{ $trip['airlineCode'] }}
                    </p>
                  </div>
                </header>

                <dl class="travel-compare-dl">
                  <div>
                    <dt>PHP</dt>
                    <dd>
                      RT {{ $trip['rtPricePhp'] !== null ? '₱'.number_format($trip['rtPricePhp']) : '—' }}
                      /
                      OW×2 {{ $trip['owSumPhp'] !== null ? '₱'.number_format($trip['owSumPhp']) : '—' }}
                      <strong class="travel-winner">{{ $trip['comparePhp']['label'] ?? '' }}</strong>
                    </dd>
                  </div>
                  <div>
                    <dt>JPY</dt>
                    <dd>
                      RT {{ $trip['rtPriceJpy'] !== null ? '¥'.number_format($trip['rtPriceJpy']) : '—' }}
                      /
                      OW×2 {{ $trip['owSumJpy'] !== null ? '¥'.number_format($trip['owSumJpy']) : '—' }}
                      <strong class="travel-winner">{{ $trip['compareJpy']['label'] ?? '' }}</strong>
                    </dd>
                  </div>
                  <div>
                    <dt>{{ __('PHP購入') }}</dt>
                    <dd>
                      {{ __('行き') }}: {{ !empty($trip['outBookedInPhp']) ? __('済') : __('未') }}
                      ·
                      {{ __('帰り') }}: {{ !empty($trip['backBookedInPhp']) ? __('済') : __('未') }}
                    </dd>
                  </div>
                </dl>

                @if($trip['notes'] !== '')
                  <p class="travel-trip-notes">{{ $trip['notes'] }}</p>
                @endif

                <details class="travel-trip-edit">
                  <summary>{{ __('編集') }}</summary>
                  <form method="post" action="/travel/trips/{{ $trip['id'] }}/update" class="travel-form">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <div class="travel-form-grid">
                      <label>{{ __('目的') }}
                        <select name="purpose">
                          @foreach($purposeLabels as $key => $label)
                            <option value="{{ $key }}" @selected($trip['purpose'] === $key)>{{ $label }}</option>
                          @endforeach
                        </select>
                      </label>
                      <label>{{ __('ラベル') }}
                        <input type="text" name="label" value="{{ $trip['label'] }}" />
                      </label>
                      <label>{{ __('日本発') }}
                        <input type="date" name="departOn" value="{{ $trip['departOn'] }}" required />
                      </label>
                      <label>{{ __('帰国') }}
                        <input type="date" name="returnOn" value="{{ $trip['returnOn'] }}" />
                      </label>
                      <label>{{ __('状態') }}
                        <select name="status">
                          @foreach($statusLabels as $key => $label)
                            <option value="{{ $key }}" @selected($trip['status'] === $key)>{{ $label }}</option>
                          @endforeach
                        </select>
                      </label>
                      <label>{{ __('予約形態') }}
                        <select name="bookedAs">
                          <option value="" @selected(empty($trip['bookedAs']))>{{ __('未定') }}</option>
                          <option value="ow_pair" @selected($trip['bookedAs'] === 'ow_pair')>{{ __('片道×2') }}</option>
                          <option value="rt" @selected($trip['bookedAs'] === 'rt')>{{ __('往復') }}</option>
                        </select>
                      </label>
                      <label title="{{ __('Round Trip（往復一括予約）の税込合計を、フィリピンペソ（PHP）で入力します。') }}">
                        <span class="travel-field-label">RT PHP <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                        <input type="number" name="rtPricePhp" min="0" step="1" value="{{ $trip['rtPricePhp'] }}" />
                      </label>
                      <label title="{{ __('One Way（片道）・日本発 FUK→MNL の税込運賃を PHP で入力します。') }}">
                        <span class="travel-field-label">OW行き PHP <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                        <input type="number" name="owOutPricePhp" min="0" step="1" value="{{ $trip['owOutPricePhp'] }}" />
                      </label>
                      <label title="{{ __('One Way（片道）・帰国 MNL→FUK の税込運賃を PHP で入力します。') }}">
                        <span class="travel-field-label">OW帰り PHP <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                        <input type="number" name="owBackPricePhp" min="0" step="1" value="{{ $trip['owBackPricePhp'] }}" />
                      </label>
                      <label title="{{ __('Round Trip（往復一括予約）の税込合計を、日本円（JPY）で入力します。') }}">
                        <span class="travel-field-label">RT JPY <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                        <input type="number" name="rtPriceJpy" min="0" step="1" value="{{ $trip['rtPriceJpy'] }}" />
                      </label>
                      <label title="{{ __('One Way（片道）・日本発 FUK→MNL の税込運賃を JPY で入力します。') }}">
                        <span class="travel-field-label">OW行き JPY <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                        <input type="number" name="owOutPriceJpy" min="0" step="1" value="{{ $trip['owOutPriceJpy'] }}" />
                      </label>
                      <label title="{{ __('One Way（片道）・帰国 MNL→FUK の税込運賃を JPY で入力します。') }}">
                        <span class="travel-field-label">OW帰り JPY <span class="travel-tip-mark" aria-hidden="true">?</span></span>
                        <input type="number" name="owBackPriceJpy" min="0" step="1" value="{{ $trip['owBackPriceJpy'] }}" />
                      </label>
                      <label class="travel-check">
                        <input type="checkbox" name="outBookedInPhp" value="1" @checked(!empty($trip['outBookedInPhp'])) />
                        {{ __('行きを PHP で購入済') }}
                      </label>
                      <label class="travel-check">
                        <input type="checkbox" name="backBookedInPhp" value="1" @checked(!empty($trip['backBookedInPhp'])) />
                        {{ __('帰りを PHP で購入済') }}
                      </label>
                    </div>
                    <label class="travel-form-notes">{{ __('メモ') }}
                      <textarea name="notes" rows="2">{{ $trip['notes'] }}</textarea>
                    </label>
                    <div class="travel-form-actions">
                      <button type="submit" class="button-link">{{ __('更新') }}</button>
                    </div>
                  </form>
                  <form method="post" action="/travel/trips/{{ $trip['id'] }}/delete" class="travel-delete-form" onsubmit="return confirm(@json(__('この渡航予定を削除しますか？')))">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <button type="submit" class="secondary">{{ __('削除') }}</button>
                  </form>
                </details>
              </article>
            @endforeach
          </div>
        @endif
      </section>

      <section class="panel travel-panel">
        <h2 class="travel-section-title">{{ __('セール／プロモ監視') }}</h2>
        <p class="hint">
          {{ __('Cebu Pacific は公開コードより Seat Sale が本体です。公開まとめ記事から予約期間・渡航期間を自動取得し、新規セール時はアラートします。FUK⇔MNL 対象と税込金額は公式で最終確認してください。') }}
        </p>
        <div class="travel-promo-actions">
          <form method="post" action="/travel/promos/fetch">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <button type="submit" class="button-link">{{ __('今すぐ取得') }}</button>
          </form>
          <span class="hint">{{ __('定期取得: 22:00–08:00 毎30分 / 08:00–22:00 2時間ごと（JST）') }}</span>
        </div>
        @if(!empty($promoWatchSources))
          <ul class="travel-watch-sources">
            @foreach($promoWatchSources as $source)
              <li>
                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['name'] }}</a>
                @if(!empty($source['auto']))
                  <span class="travel-badge">{{ __('自動取得') }}</span>
                @else
                  <span class="travel-badge travel-badge-muted">{{ __('確認用') }}</span>
                @endif
              </li>
            @endforeach
          </ul>
        @endif

        <details class="travel-trip-edit">
          <summary>{{ __('手動で追加（任意）') }}</summary>
          <form method="post" action="/travel/promos" class="travel-form">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <div class="travel-form-grid">
              <label>{{ __('コード／識別子') }}
                <input type="text" name="code" required maxlength="64" />
              </label>
              <label>{{ __('タイトル') }}
                <input type="text" name="title" maxlength="160" />
              </label>
              <label>{{ __('適用') }}
                <select name="appliesTo">
                  @foreach($promoAppliesLabels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                  @endforeach
                </select>
              </label>
              <label>{{ __('状態') }}
                <select name="status">
                  @foreach($promoStatusLabels as $key => $label)
                    <option value="{{ $key }}" @selected($key === 'watching')>{{ $label }}</option>
                  @endforeach
                </select>
              </label>
              <label>{{ __('予約開始') }}
                <input type="date" name="validFrom" />
              </label>
              <label>{{ __('予約終了') }}
                <input type="date" name="validUntil" />
              </label>
              <label>{{ __('ソースURL') }}
                <input type="url" name="sourceUrl" placeholder="https://" />
              </label>
            </div>
            <label class="travel-form-notes">{{ __('メモ') }}
              <textarea name="notes" rows="2"></textarea>
            </label>
            <div class="travel-form-actions">
              <button type="submit" class="button-link">{{ __('プロモを追加') }}</button>
            </div>
          </form>
        </details>

        @if(count($promos ?? []) === 0)
          <p class="hint">{{ __('まだセール情報がありません。「今すぐ取得」を押してください。') }}</p>
        @else
          <div class="travel-promo-list">
            @foreach($promos as $promo)
              <article class="travel-promo-card">
                <header class="travel-promo-head">
                  <h3>
                    <code>{{ $promo['code'] }}</code>
                    <span class="travel-badge">{{ $promo['statusLabel'] }}</span>
                    @if(!empty($promo['autoFetched']))
                      <span class="travel-badge travel-badge-auto">{{ __('自動') }}</span>
                    @endif
                  </h3>
                  <p>
                    {{ $promo['title'] !== '' ? $promo['title'] : '—' }}
                    · {{ $promo['appliesToLabel'] }}
                  </p>
                  <p>
                    {{ __('予約') }}:
                    {{ $promo['validFrom'] ?? '—' }}〜{{ $promo['validUntil'] ?? '—' }}
                    @if($promo['travelFrom'] || $promo['travelUntil'])
                      · {{ __('渡航') }}:
                      {{ $promo['travelFrom'] ?? '—' }}〜{{ $promo['travelUntil'] ?? '—' }}
                    @endif
                  </p>
                  @if($promo['sourceUrl'] !== '')
                    <p><a href="{{ $promo['sourceUrl'] }}" target="_blank" rel="noopener noreferrer">{{ __('ソースを開く') }}</a>
                      @if(!empty($promo['lastSeenAt']))
                        · {{ __('最終確認') }} {{ $promo['lastSeenAt'] }}
                      @endif
                    </p>
                  @endif
                </header>
                @if($promo['notes'] !== '')
                  <p class="travel-trip-notes">{{ $promo['notes'] }}</p>
                @endif
                <details class="travel-trip-edit">
                  <summary>{{ __('編集') }}</summary>
                  <form method="post" action="/travel/promos/{{ $promo['id'] }}/update" class="travel-form">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <div class="travel-form-grid">
                      <label>{{ __('コード／識別子') }}
                        <input type="text" name="code" value="{{ $promo['code'] }}" required maxlength="64" />
                      </label>
                      <label>{{ __('タイトル') }}
                        <input type="text" name="title" value="{{ $promo['title'] }}" maxlength="160" />
                      </label>
                      <label>{{ __('適用') }}
                        <select name="appliesTo">
                          @foreach($promoAppliesLabels as $key => $label)
                            <option value="{{ $key }}" @selected($promo['appliesTo'] === $key)>{{ $label }}</option>
                          @endforeach
                        </select>
                      </label>
                      <label>{{ __('状態') }}
                        <select name="status">
                          @foreach($promoStatusLabels as $key => $label)
                            <option value="{{ $key }}" @selected($promo['status'] === $key)>{{ $label }}</option>
                          @endforeach
                        </select>
                      </label>
                      <label>{{ __('予約開始') }}
                        <input type="date" name="validFrom" value="{{ $promo['validFrom'] }}" />
                      </label>
                      <label>{{ __('予約終了') }}
                        <input type="date" name="validUntil" value="{{ $promo['validUntil'] }}" />
                      </label>
                      <label>{{ __('ソースURL') }}
                        <input type="url" name="sourceUrl" value="{{ $promo['sourceUrl'] }}" />
                      </label>
                    </div>
                    <label class="travel-form-notes">{{ __('メモ') }}
                      <textarea name="notes" rows="2">{{ $promo['notes'] }}</textarea>
                    </label>
                    <div class="travel-form-actions">
                      <button type="submit" class="button-link">{{ __('更新') }}</button>
                    </div>
                  </form>
                  <form method="post" action="/travel/promos/{{ $promo['id'] }}/delete" class="travel-delete-form" onsubmit="return confirm(@json(__('このプロモを削除しますか？')))">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <button type="submit" class="secondary">{{ __('削除') }}</button>
                  </form>
                </details>
              </article>
            @endforeach
          </div>
        @endif
      </section>

      <section class="panel travel-panel">
        <h2 class="travel-section-title">{{ __('運賃スナップショット履歴') }}</h2>
        <p class="hint">{{ __('出発日順に並べています。同じ出発日の料金推移は「記録日時」で比較できます。') }}</p>
        @if(count($snapshots ?? []) === 0)
          <p class="hint">{{ __('まだ履歴がありません。') }}</p>
        @else
          <div class="travel-snapshot-table-wrap">
            <table class="travel-snapshot-table">
              <thead>
                <tr>
                  <th>{{ __('出発日') }}</th>
                  <th>{{ __('帰国日') }}</th>
                  <th>{{ __('渡航') }}</th>
                  <th>PHP</th>
                  <th>JPY</th>
                  <th>{{ __('予算内') }}</th>
                  <th>{{ __('記録日時') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($snapshots as $snap)
                  <tr>
                    <td>{{ $snap['departOn'] ?? '—' }}</td>
                    <td>{{ $snap['returnOn'] ?? '—' }}</td>
                    <td>{{ $snap['tripLabel'] }}</td>
                    <td>
                      RT {{ $snap['rtPricePhp'] !== null ? '₱'.number_format($snap['rtPricePhp']) : '—' }}
                      /
                      OW×2 {{ $snap['owSumPhp'] !== null ? '₱'.number_format($snap['owSumPhp']) : '—' }}
                    </td>
                    <td>
                      RT {{ $snap['rtPriceJpy'] !== null ? '¥'.number_format($snap['rtPriceJpy']) : '—' }}
                      /
                      OW×2 {{ $snap['owSumJpy'] !== null ? '¥'.number_format($snap['owSumJpy']) : '—' }}
                    </td>
                    <td>
                      @if($snap['underBudgetJpy'] === null)
                        —
                      @elseif($snap['underBudgetJpy'])
                        {{ __('内') }}
                      @else
                        {{ __('超') }}
                      @endif
                    </td>
                    <td>{{ $snap['capturedAt'] ?? '—' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>
    </main>
    <script>
      (() => {
        const mode = document.getElementById('travel-fare-table-mode');
        const form = document.getElementById('travel-fare-table-form');
        if (!mode || !form) return;

        const returnFields = form.querySelectorAll('.travel-fare-return-field');
        const sync = () => {
          const isRt = mode.value === 'rt';
          returnFields.forEach((el) => {
            el.classList.toggle('is-hidden', !isRt);
            const input = el.querySelector('input');
            if (input) {
              input.required = isRt;
              if (!isRt) input.value = '';
            }
          });
        };
        mode.addEventListener('change', sync);
        sync();
      })();
    </script>
  </body>
</html>
