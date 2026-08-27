@foreach($items as $item)
  <article class="yt-result-card">
    <a class="yt-result-play" href="{{ $item['playHref'] }}">
      <span class="yt-result-thumb">
        @if(!empty($item['thumbUrl']))
          <img src="{{ $item['thumbUrl'] }}" alt="" loading="lazy" />
        @else
          <span class="media-video-thumb-fallback">▶</span>
        @endif
      </span>
      <span class="yt-result-copy">
        <strong class="yt-result-title">{{ $item['title'] ?? '' }}</strong>
        <span class="hint yt-result-channel">
          {{ collect([
            ($item['source'] ?? '') === 'tiktok' ? 'TikTok' : 'YouTube',
            $item['channelTitle'] ?? null,
            $item['publishedAt'] ?? null,
          ])->filter()->implode(' · ') }}
        </span>
      </span>
      <span class="yt-result-play-label">▶ {{ __('再生') }}</span>
    </a>
    <form method="post" action="/video/youtube">
      @csrf
      <input type="hidden" name="returnTo" value="{{ $item['playHref'] }}" />
      <input type="hidden" name="library_id" value="{{ $libraryId }}" />
      <input type="hidden" name="youtube_id" value="{{ $item['youtubeId'] ?? '' }}" />
      <input type="hidden" name="source" value="{{ $item['source'] ?? 'youtube' }}" />
      <input type="hidden" name="title" value="{{ $item['title'] ?? '' }}" />
      <input type="hidden" name="thumb_url" value="{{ $item['thumbUrl'] ?? '' }}" />
      <button type="submit" class="secondary yt-result-save">{{ __('ライブラリに追加') }}</button>
    </form>
  </article>
@endforeach
