<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }}</title>
    @viteReactRefresh
    @vite('resources/js/panel/main.tsx')
</head>
<body class="min-h-screen bg-slate-50 antialiased">
    <div id="app"></div>
</body>
</html>
