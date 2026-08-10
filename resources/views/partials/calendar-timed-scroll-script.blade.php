{{-- 日・週の時間グリッドを初期表示で 8:00 付近から見せる --}}
<script>
  (function () {
    const START_HOUR = 8

    function scrollTimedCalendarsToMorning() {
      document.querySelectorAll('.cal-timed-scroll').forEach((timedScroll) => {
        const host = timedScroll.closest('.calendar-day-view, .calendar-week-view') || timedScroll
        const hourHeight = parseFloat(getComputedStyle(host).getPropertyValue('--cal-hour-height')) || 48
        timedScroll.scrollTop = hourHeight * START_HOUR
      })
    }

    scrollTimedCalendarsToMorning()
    requestAnimationFrame(scrollTimedCalendarsToMorning)
    window.addEventListener('load', scrollTimedCalendarsToMorning, { once: true })
  })()
</script>
