@php
  $wp = $webPushSettings ?? [
    'enabled' => false,
    'ready' => false,
    'subject' => '',
    'public_key_masked' => '',
    'private_key_masked' => '',
  ];
@endphp
<div class="panel storage-settings" id="web-push">
  <h2>{{ __('Web Push 着信通知') }}</h2>
  <p class="hint">{{ __('アプリを閉じているときでも通話着信を端末通知で届ける設定です。Firebase Console の Web Push 証明書（VAPID）を貼るか、下の「鍵を生成」で作成できます。Android TWA も同じ設定を使います。') }}</p>

  @if(!empty($wp['last_test_message']))
    <p class="hint storage-test-result {{ ($wp['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $wp['last_test_message'] }}
      @if(!empty($wp['last_tested_at']))
        <span class="inline-hint">({{ $wp['last_tested_at'] }})</span>
      @endif
    </p>
  @endif

  <form method="post" action="/settings/api/web-push" class="storage-provider-form" id="web-push-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($wp['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <p class="hint">{{ __('Subject は mailto:管理者メール または https://あなたのドメイン の形式です。保存時は「有効にする」にチェックを入れてください。') }}</p>
    <label>
      {{ __('Subject') }}
      <input type="text" name="subject" value="{{ $wp['subject'] ?? '' }}" autocomplete="off" placeholder="mailto:admin@example.com" />
    </label>
    <label>
      {{ __('VAPID 公開鍵') }}
      <input type="password" name="public_key" id="web-push-public-key" value="" autocomplete="new-password" placeholder="{{ !empty($wp['public_key_masked']) ? __('••••••••（変更するときだけ入力）') : '' }}" />
    </label>
    <label>
      {{ __('VAPID 秘密鍵') }}
      <input type="password" name="private_key" id="web-push-private-key" value="" autocomplete="new-password" placeholder="{{ !empty($wp['private_key_masked']) ? __('••••••••（変更するときだけ入力）') : '' }}" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="web-push-generate-btn">{{ __('鍵を生成') }}</button>
      <button type="button" class="secondary" id="web-push-test-btn">{{ __('設定テスト') }}</button>
      <span class="storage-test-live hint" id="web-push-test-live"></span>
    </div>
  </form>
  <p class="hint">{{ __('端末ごとの通知許可は、各ユーザーがマイページの「通話の着信通知を登録」から行います。管理者は下の通知設定からも登録できます。') }}</p>
  <div class="storage-form-actions">
    <a class="button-link secondary" href="/mypage#web-push-subscribe">{{ __('マイページで端末を登録') }}</a>
    <a class="button-link secondary" href="/settings?section=notifications">{{ __('通知設定を開く') }}</a>
  </div>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('web-push-test-btn');
    const generateBtn = document.getElementById('web-push-generate-btn');
    const testLive = document.getElementById('web-push-test-live');
    const publicInput = document.getElementById('web-push-public-key');
    const privateInput = document.getElementById('web-push-private-key');
    const strings = {
      testing: @json(__('テスト中...')),
      generating: @json(__('生成中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };

    function showLive(message, ok) {
      if (!testLive) return;
      testLive.textContent = message || '';
      testLive.classList.toggle('is-ok', Boolean(ok));
      testLive.classList.toggle('is-fail', !ok);
    }

    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      showLive(strings.testing, false);
      try {
        const res = await fetch('/settings/api/web-push/test', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        showLive(data.message || '', Boolean(data.ok));
      } catch (_) {
        showLive(strings.networkError, false);
      } finally {
        testBtn.disabled = false;
      }
    });

    generateBtn?.addEventListener('click', async () => {
      generateBtn.disabled = true;
      showLive(strings.generating, false);
      try {
        const res = await fetch('/settings/api/web-push/generate', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        if (data.ok && publicInput && privateInput) {
          publicInput.type = 'text';
          privateInput.type = 'text';
          publicInput.value = data.publicKey || '';
          privateInput.value = data.privateKey || '';
        }
        showLive(data.message || '', Boolean(data.ok));
      } catch (_) {
        showLive(strings.networkError, false);
      } finally {
        generateBtn.disabled = false;
      }
    });
  })();
</script>
