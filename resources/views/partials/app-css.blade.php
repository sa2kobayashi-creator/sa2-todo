<link rel="stylesheet" href="{{ asset('app.css') }}?v={{ @filemtime(public_path('app.css')) ?: time() }}" />
