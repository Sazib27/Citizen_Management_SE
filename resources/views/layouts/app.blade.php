<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Citizen Services Portal')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 text-gray-900 antialiased">
    <header class="bg-white shadow-sm">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="{{ url('/') }}" class="font-bold text-gray-900">Citizen Services Portal</a>
            <span class="text-xs text-gray-500">Public verification page</span>
        </div>
    </header>

    <main>
        @yield('content')
    </main>
</body>
</html>
