@if(app()->isProduction())
    @vite('resources/css/app.css')
@else
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
@endif
