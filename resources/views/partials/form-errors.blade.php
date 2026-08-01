@if($errors->any())
  <div class="banner error form-errors" role="alert">
    <strong>{{ __('入力内容を確認してください。') }}</strong>
    <ul class="form-errors-list">
      @foreach($errors->all() as $message)
        <li>{{ $message }}</li>
      @endforeach
    </ul>
  </div>
@endif
