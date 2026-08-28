{{-- SuperAdmin 専用。個人情報を持たない日次集計。 --}}
@php
  $dash = $siteStats ?? null;
  $days = (int) ($dash['days'] ?? 30);
@endphp
<div class="panel" id="site-stats">
  <h2>{{ __('アクセス統計') }}</h2>
  <p class="hint">{{ __('TOP・申請・ログイン・課金などの日次件数です。個人を特定する情報は保存しません。ボットらしきアクセスは除外します。') }}</p>

  <form method="get" action="/settings" class="auth-form" style="margin-bottom:1rem;">
    <input type="hidden" name="section" value="stats" />
    <label>{{ __('集計期間') }}
      <select name="days" onchange="this.form.submit()">
        <option value="7" @selected($days === 7)>{{ __('直近7日') }}</option>
        <option value="30" @selected($days === 30)>{{ __('直近30日') }}</option>
        <option value="90" @selected($days === 90)>{{ __('直近90日') }}</option>
      </select>
    </label>
  </form>

  @if(empty($dash))
    <p class="hint">{{ __('集計データを表示できません。マイグレーションを実行してください。') }}</p>
  @else
    <p class="hint">{{ __('期間') }}: {{ $dash['from'] }} — {{ $dash['to'] }}</p>

    @foreach(($dash['sections'] ?? []) as $sectionBlock)
      <h3 style="margin-top:1.25rem;">{{ __($sectionBlock['title']) }}</h3>
      <div class="usage-limit-table-wrap">
        <table class="usage-limit-table">
          <thead>
            <tr>
              <th>{{ __('項目') }}</th>
              <th>{{ __('合計') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach(($sectionBlock['rows'] ?? []) as $row)
              <tr>
                <td>{{ __($row['label']) }}</td>
                <td>{{ number_format((int) ($row['total'] ?? 0)) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endforeach

    <h3 style="margin-top:1.5rem;">{{ __('日次（主要）') }}</h3>
    <div class="usage-limit-table-wrap">
      <table class="usage-limit-table">
        <thead>
          <tr>
            <th>{{ __('日付') }}</th>
            <th>{{ __('TOP') }}</th>
            <th>{{ __('申請送信') }}</th>
            <th>{{ __('ログイン') }}</th>
            <th>{{ __('Checkout開始') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach(array_reverse($dash['series'] ?? []) as $day)
            @php
              $v = $day['values'] ?? [];
              $submits = (int) ($v['apply.submit.light'] ?? 0)
                + (int) ($v['apply.submit.standard'] ?? 0)
                + (int) ($v['apply.submit.tenant'] ?? 0);
            @endphp
            <tr>
              <td>{{ $day['date'] }}</td>
              <td>{{ number_format((int) ($v['top.view'] ?? 0)) }}</td>
              <td>{{ number_format($submits) }}</td>
              <td>{{ number_format((int) ($v['login'] ?? 0)) }}</td>
              <td>{{ number_format((int) ($v['checkout.start'] ?? 0)) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
