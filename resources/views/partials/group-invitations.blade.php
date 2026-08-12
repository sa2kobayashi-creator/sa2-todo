@if(!empty($pendingGroupInvitations))
  <div class="panel">
    <h2>{{ __('グループへの招待') }} ({{ count($pendingGroupInvitations) }})</h2>
    <ul class="feature-access-list">
      @foreach($pendingGroupInvitations as $invitation)
        <li>
          <span>
            {{ $invitation['groupName'] ?? __('グループ') }}
            <span class="hint">{{ __('招待者') }}: {{ $invitation['inviterName'] ?? '—' }}</span>
          </span>
          <span style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" action="/group-invitations/{{ $invitation['id'] }}/accept" class="inline-form">
              @csrf
              <button type="submit" class="mini-btn">{{ __('承諾') }}</button>
            </form>
            <form method="post" action="/group-invitations/{{ $invitation['id'] }}/decline" class="inline-form">
              @csrf
              <button type="submit" class="secondary mini-btn">{{ __('辞退') }}</button>
            </form>
          </span>
        </li>
      @endforeach
    </ul>
  </div>
@endif
