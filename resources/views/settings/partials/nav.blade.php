@php
  use App\Support\FooterNav;
  $catalog = FooterNav::catalog();
  $allowed = FooterNav::allowedKeys($currentUserModel ?? null);
  $selected = FooterNav::normalizeFooterKeys($footerNavSelected ?? null, $currentUserModel ?? null);
@endphp
<div class="panel" id="footer-nav-settings">
  <h2>{{ __('表示メニュー管理') }}</h2>
  <p class="hint">{{ __('表示するメニュー（最大:max件）を選べます。スマートフォン下部メニューとWebヘッダーに反映されます。選ばなかった項目はスマートフォンの「⋮」に入ります（Webでは⋮は表示しません）。', ['max' => FooterNav::MAX_FOOTER]) }}</p>

  <form method="post" action="/settings/nav" class="footer-nav-form">
    @csrf
    <ul class="footer-nav-checklist">
      @foreach($catalog as $key => $item)
        @php
          $can = in_array($key, $allowed, true);
          $checked = in_array($key, $selected, true);
        @endphp
        <li class="footer-nav-check{{ $can ? '' : ' is-disabled' }}">
          <label>
            <input
              type="checkbox"
              name="footer_nav[]"
              value="{{ $key }}"
              @checked($checked)
              @disabled(! $can)
              class="footer-nav-cb"
            />
            <span class="footer-nav-icon" aria-hidden="true">{{ $item['icon'] }}</span>
            <span>{{ __($item['label']) }}</span>
            @unless($can)
              <em class="hint">{{ __('（権限なし）') }}</em>
            @endunless
          </label>
        </li>
      @endforeach
    </ul>
    <p class="hint footer-nav-limit-note" id="footer-nav-limit-note" hidden>
      {{ __('表示メニューは最大 :max 件までです。', ['max' => FooterNav::MAX_FOOTER]) }}
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
    const boxes = Array.from(document.querySelectorAll('.footer-nav-cb:not(:disabled)'))
    const note = document.getElementById('footer-nav-limit-note')
    function sync() {
      const checked = boxes.filter((b) => b.checked)
      if (note) note.hidden = checked.length < max
      boxes.forEach((b) => {
        if (!b.checked && checked.length >= max) b.disabled = true
        else if (!b.dataset.forceDisabled) b.disabled = false
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
