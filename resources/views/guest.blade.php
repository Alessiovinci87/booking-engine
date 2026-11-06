<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @viteReactRefresh
    @vite('resources/js/guest/main.tsx')
</head>
<body class="min-h-screen bg-white antialiased">
    <div id="app"></div>
</body>
</html>
