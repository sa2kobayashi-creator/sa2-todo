<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ストレージ監視') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-storage'])
    <main class="page-main page-main-narrow sales-estimate-page">
      <div class="panel">
        <h2>{{ __('ストレージ監視・アーカイブ') }}</h2>
        <p class="hint">{{ __('このサーバー全体の使用量です（ユーザーごとの内訳ではありません）。しきい値を超えた分の退避は、サーバーの定期処理（cron）が実行します。写真・動画の原本はロリポップには置きません。') }}</p>
        <dl class="sales-estimate-totals">
          <div>
            <dt>{{ __('R2（現役写真）') }}（{{ $usage['r2_status'] }}）</dt>
            <dd>{{ \App\Services\StorageUsageService::formatBytes((int) $usage['r2_bytes']) }}</dd>
          </div>
          <div>
            <dt>{{ __('メール合算') }}（{{ $usage['mail_status'] }}）</dt>
            <dd>{{ \App\Services\StorageUsageService::formatBytes((int) $usage['mail_bytes']) }}</dd>
          </div>
          <div>
            <dt>{{ __('DB') }}（{{ $usage['db_status'] }}）</dt>
            <dd>{{ \App\Services\StorageUsageService::formatBytes((int) $usage['db_bytes']) }}</dd>
          </div>
        </dl>
        @if(!empty($usage['mail_boxes']))
          <h3>{{ __('メールボックス') }}</h3>
          <ul class="hint">
            @foreach($usage['mail_boxes'] as $box)
              <li>{{ $box['email'] }}: {{ $box['bytes'] === null ? __('計測不可') : \App\Services\StorageUsageService::formatBytes((int) $box['bytes']) }}</li>
            @endforeach
          </ul>
        @endif
      </div>

      <div class="panel">
        <h2>{{ __('最近の実行') }}</h2>
        <table class="sales-estimate-table">
          <thead>
            <tr>
              <th>{{ __('日時') }}</th>
              <th>{{ __('R2') }}</th>
              <th>{{ __('メール退避') }}</th>
              <th>{{ __('写真退避') }}</th>
              <th>{{ __('DB退避') }}</th>
              <th>{{ __('備考') }}</th>
            </tr>
          </thead>
          <tbody>
            @forelse($logs as $log)
              <tr>
                <td>{{ $log->ran_at?->format('Y-m-d H:i') }}</td>
                <td>{{ $log->r2_status }} / {{ \App\Services\StorageUsageService::formatBytes((int) $log->r2_bytes) }}</td>
                <td>{{ $log->mail_archived }}</td>
                <td>{{ $log->photos_archived }}</td>
                <td>{{ $log->db_archived }}</td>
                <td class="hint">{{ $log->backup_key ?: implode(', ', $log->notes ?? []) }}{{ $log->error ? ' '.$log->error : '' }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="hint">{{ __('まだ実行記録がありません。cron の storage:manage を待つか、手動実行してください。') }}</td></tr>
            @endforelse
          </tbody>
        </table>
        <p class="hint">php artisan storage:manage / php artisan storage:backup-db</p>
      </div>
    </main>
  </body>
</html>
