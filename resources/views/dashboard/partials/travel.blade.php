@if(!empty($travelSummary) && !empty($canTravel))
  <section class="dash-travel dash-quick-panel" aria-label="{{ __('航空') }}">
    <details class="app-accordion" data-accordion-key="dash-travel">
    <summary class="app-accordion-summary dash-quick-summary">
      <h2 class="dash-travel-title">{{ __('航空') }}</h2>
      <span class="app-accordion-caret" aria-hidden="true">▾</span>
    </summary>
    <div class="app-accordion-body dash-quick-modal-body">
    <div class="dash-quick-modal-sheet">
    <div class="dash-quick-modal-bar">
      <strong>{{ __('航空') }}</strong>
      <button type="button" class="dash-quick-modal-close" data-close-dash-quick aria-label="{{ __('閉じる') }}">×</button>
    </div>
    <div class="dash-travel-head">
      <p class="dash-travel-lead">{{ __('フライトの目安運賃を探せます。運賃は目安です。') }}</p>
      <a class="button-link secondary" href="/travel">{{ __('航空へ') }}</a>
    </div>
    </div>
    </div>
    </details>
  </section>
@endif
