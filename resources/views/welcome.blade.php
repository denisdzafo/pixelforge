<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name') }}</title>
    <link rel="icon" href="data:,">

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="antialiased">
<div id="app"></div>
</body>
</html>
