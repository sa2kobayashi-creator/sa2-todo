@php
  use App\Support\FooterNav;
  $catalog = FooterNav::catalog();
  $allowed = FooterNav::allowedKeys($currentUserModel ?? null);
  $footerSelected = FooterNav::normalizeFooterKeys($footerNavSelected ?? null, $currentUserModel ?? null);
  $headerSelected = FooterNav::normalizeHeaderKeys($headerNavSelected ?? null, $currentUserModel ?? null);
@endphp
<div class="panel" id="footer-nav-settings">
  <h2>{{ __('表示メニュー管理') }}</h2>
  <p class="hint">{{ __('メニューごとに、スマートフォン下部とWeb（PC）ヘッダーの表示を選べます。スマートフォンは最大:max件まで。選ばなかった項目はスマートフォンの「⋮」に入ります（Webでは⋮は表示しません）。', ['max' => FooterNav::MAX_FOOTER]) }}</p>

  <form method="post" action="/settings/nav" class="footer-nav-form">
    @csrf
    <div class="footer-nav-head" aria-hidden="true">
      <span class="footer-nav-head-label">{{ __('メニュー') }}</span>
      <span class="footer-nav-head-col">{{ __('スマホ') }}</span>
      <span class="footer-nav-head-col">{{ __('Web') }}</span>
    </div>
    <ul class="footer-nav-checklist">
      @foreach($catalog as $key => $item)
        @php
          $can = in_array($key, $allowed, true);
          $footerChecked = in_array($key, $footerSelected, true);
          $headerChecked = in_array($key, $headerSelected, true);
        @endphp
        <li class="footer-nav-check{{ $can ? '' : ' is-disabled' }}">
          <div class="footer-nav-row">
            <span class="footer-nav-item">
              <span class="footer-nav-icon" aria-hidden="true">{{ $item['icon'] }}</span>
              <span>{{ __($item['label']) }}</span>
              @unless($can)
                <em class="hint">{{ __('（権限なし）') }}</em>
              @endunless
            </span>
            <label class="footer-nav-col">
              <input
                type="checkbox"
                name="footer_nav[]"
                value="{{ $key }}"
                @checked($footerChecked)
                @disabled(! $can)
                class="footer-nav-cb footer-nav-cb-mobile"
                @unless($can) data-force-disabled="1" @endunless
                aria-label="{{ __(':name（スマートフォン）', ['name' => __($item['label'])]) }}"
              />
            </label>
            <label class="footer-nav-col">
              <input
                type="checkbox"
                name="header_nav[]"
                value="{{ $key }}"
                @checked($headerChecked)
                @disabled(! $can)
                class="footer-nav-cb footer-nav-cb-web"
                @unless($can) data-force-disabled="1" @endunless
                aria-label="{{ __(':name（Web）', ['name' => __($item['label'])]) }}"
              />
            </label>
          </div>
        </li>
      @endforeach
    </ul>
    <p class="hint footer-nav-limit-note" id="footer-nav-limit-note" hidden>
      {{ __('スマートフォンの表示メニューは最大 :max 件までです。', ['max' => FooterNav::MAX_FOOTER]) }}
    </p>
    <div class="form-actions">
      <button type="submit">{{ __('保存') }}</button>
      <button type="submit" name="reset" value="1" class="secondary">{{ __('既定に戻す') }}</button>
    </div>
  </form>
</div>
<script>
  (function () {
    const max = {{ (int) FooterNav::MAX_FOOTER }}
    const boxes = Array.from(document.querySelectorAll('.footer-nav-cb-mobile:not([data-force-disabled])'))
    const note = document.getElementById('footer-nav-limit-note')
    function sync() {
      const checked = boxes.filter((b) => b.checked)
      if (note) note.hidden = checked.length < max
      boxes.forEach((b) => {
        if (!b.checked && checked.length >= max) b.disabled = true
        else b.disabled = false
      })
    }
    boxes.forEach((b) => b.addEventListener('change', () => {
      const checked = boxes.filter((x) => x.checked)
      if (checked.length > max) {
        b.checked = false
      }
      sync()
    }))
    sync()
  })()
</script>
