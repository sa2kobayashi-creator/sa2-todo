{{-- 開閉の好みは次に開いたときも引き継ぐ --}}
<script>
  (() => {
    const store = (() => {
      try {
        window.localStorage.setItem('accordion:probe', '1')
        window.localStorage.removeItem('accordion:probe')

        return window.localStorage
      } catch (_) {
        // プライベートモード等では保存を諦めて既定の開閉のままにする
        return null
      }
    })()
    if (!store) return

    document.querySelectorAll('details.app-accordion[data-accordion-key]').forEach((el) => {
      const key = 'accordion:' + el.dataset.accordionKey
      const saved = store.getItem(key)
      if (saved === 'open') el.open = true
      else if (saved === 'closed') el.open = false

      el.addEventListener('toggle', () => {
        store.setItem(key, el.open ? 'open' : 'closed')
      })
    })
  })()
</script>
