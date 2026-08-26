@if(is_array($ft))
  <div class="travel-fare-table-result">
    <div class="travel-flight-meta">
      <p class="travel-trip-route">
        <strong>{{ __('フライト情報') }}</strong>
        @if($ft['mode'] === 'ow')
          {{ __('片道') }} {{ $ft['origin'] }}→{{ $ft['destination'] }}
          {{ $ft['departFrom'] }}〜{{ $ft['departTo'] }}
        @else
          {{ __('往復') }} {{ $ft['origin'] }}⇔{{ $ft['destination'] }}
          · {{ __('行き') }} {{ $ft['departFrom'] }}〜{{ $ft['departTo'] }}
          · {{ __('帰り') }} {{ $ft['returnFrom'] }}〜{{ $ft['returnTo'] }}
        @endif
      </p>
      <p class="hint">
        {{ __('航空会社') }}:
        @if(($ft['airlineCode'] ?? '') !== '')
          {{ \App\Support\AirlineName::label((string) $ft['airlineCode']) }}
        @else
          {{ __('指定なし（全社の目安）') }}
        @endif
        · {{ __('金額はキャッシュの目安です') }}
        · {{ $ft['fetchedAt'] }}
      </p>
    </div>

    @foreach(($ft['notes'] ?? []) as $note)
      <p class="hint">{{ $note }}</p>
    @endforeach
    @foreach(($ft['warnings'] ?? []) as $warn)
      <p class="hint travel-quote-warn">{{ $warn }}</p>
    @endforeach

    @if(!empty($ft['cheapest']))
      <div class="travel-fare-cheapest">
        <h3>{{ __('候補フライト（目安）') }}</h3>
        <ul>
          @foreach($ft['cheapest'] as $item)
            <li class="travel-flight-candidate">
              <div>
                @if(($item['airlineLabel'] ?? '') !== '' || !empty($item['airline']))
                  <strong>{{ $item['airlineLabel'] ?? \App\Support\AirlineName::label((string) ($item['airline'] ?? '')) }}</strong>
                @else
                  <strong>{{ __('航空会社不明') }}</strong>
                @endif
                @if($ft['mode'] === 'ow')
                  <span>{{ $item['departOn'] }} {{ $ft['origin'] }}→{{ $ft['destination'] }}</span>
                @else
                  <span>{{ $item['departOn'] }} → {{ $item['returnOn'] }}</span>
                  <span class="hint">({{ ($item['source'] ?? '') === 'calendar' ? __('往復キャッシュ') : __('片道合計') }})</span>
                @endif
              </div>
              <div class="travel-flight-candidate-fare">
                <span class="travel-estimate-label">{{ __('目安') }}</span>
                @if(($ft['currency'] ?? 'JPY') === 'JPY')
                  ¥{{ number_format((int) ($item['priceJpy'] ?? 0)) }}
                @else
                  ₱{{ number_format((int) ($item['pricePhp'] ?? 0)) }}
                @endif
                @if(!empty($item['officialUrl']))
                  · <a href="{{ $item['officialUrl'] }}" target="_blank" rel="noopener noreferrer">{{ $item['officialLabel'] ?: __('航空会社で確認') }}</a>
                @endif
                @if(!empty($item['searchUrl']))
                  · <a href="{{ $item['searchUrl'] }}" target="_blank" rel="noopener noreferrer">{{ __('比較サイトで確認') }}</a>
                @endif
              </div>
              <form method="post" action="/travel/fares/select" class="travel-candidate-select">
                @csrf
                <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                <input type="hidden" name="origin" value="{{ $ft['origin'] }}" />
                <input type="hidden" name="destination" value="{{ $ft['destination'] }}" />
                <input type="hidden" name="mode" value="{{ $ft['mode'] }}" />
                <input type="hidden" name="currency" value="{{ $ft['currency'] }}" />
                <input type="hidden" name="airline" value="{{ $item['airline'] ?? '' }}" />
                <input type="hidden" name="departOn" value="{{ $item['departOn'] ?? '' }}" />
                <input type="hidden" name="returnOn" value="{{ $item['returnOn'] ?? '' }}" />
                <input type="hidden" name="priceJpy" value="{{ $item['priceJpy'] ?? '' }}" />
                <input type="hidden" name="pricePhp" value="{{ $item['pricePhp'] ?? '' }}" />
                <input type="hidden" name="searchUrl" value="{{ $item['searchUrl'] ?? '' }}" />
                <input type="hidden" name="officialUrl" value="{{ $item['officialUrl'] ?? '' }}" />
                <input type="hidden" name="officialLabel" value="{{ $item['officialLabel'] ?? '' }}" />
                <button type="submit" class="secondary">{{ __('表示する') }}</button>
              </form>
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
              <th>{{ __('航空会社') }}</th>
              <th>{{ __('目安 PHP') }}</th>
              <th>{{ __('目安 JPY') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach($ft['rows'] as $row)
              <tr @class([
                'is-cheapest' => !empty($row['isCheapest']),
                'is-filled' => !empty($row['isFilled']),
              ])>
                <td>{{ $row['departOn'] }}</td>
                <td>{{ ($row['airline'] ?? '') !== '' ? \App\Support\AirlineName::label((string) $row['airline']) : '—' }}</td>
                <td>{{ $row['pricePhp'] !== null ? '₱'.number_format($row['pricePhp']) : '—' }}</td>
                <td>{{ $row['priceJpy'] !== null ? '¥'.number_format($row['priceJpy']) : '—' }}</td>
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
          $p = ($ft['currency'] ?? 'JPY') === 'JPY' ? $cell['priceJpy'] : $cell['pricePhp'];
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
                      $price = ($ft['currency'] ?? 'JPY') === 'JPY' ? $cell['priceJpy'] : $cell['pricePhp'];
                    }
                  @endphp
                  <td @class(['is-empty' => $cell === null, 'is-cheapest' => $price !== null && $minPrice !== null && $price === $minPrice])>
                    @if($cell && $price !== null)
                      @if(!empty($cell['airline']))
                        <span class="travel-matrix-airline">{{ \App\Support\AirlineName::name((string) $cell['airline']) }}</span>
                      @endif
                      @if(($ft['currency'] ?? 'JPY') === 'JPY')
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
      <p class="hint">{{ __('往復キャッシュがある場合はその価格、ない場合は行き＋帰りの片道合計を表示しています。金額は目安です。') }}</p>
    @endif
  </div>
@endif
