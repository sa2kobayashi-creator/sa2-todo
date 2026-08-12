<script>
  (() => {
    try {
      @if(!empty($legalReadKey))
        localStorage.setItem(@json($legalReadKey), '1');
      @endif
    } catch (_) {}
  })();
</script>
