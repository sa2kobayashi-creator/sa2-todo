{{-- フラッシュを1回表示したら URL の notice/error を除去（リロードで再表示しない） --}}
<script>
  (function () {
    try {
      var params = new URLSearchParams(window.location.search)
      if (!params.has('notice') && !params.has('error')) return
      params.delete('notice')
      params.delete('error')
      var q = params.toString()
      var next = window.location.pathname + (q ? '?' + q : '') + window.location.hash
      window.history.replaceState({}, '', next)
    } catch (_) {}
  })()
</script>
