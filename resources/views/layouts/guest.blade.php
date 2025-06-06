<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Barmada - Bar Management Dashboard</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- General Styles -->
        <link href="{{ asset('css/general-dark.css') }}" rel="stylesheet">
        
        <!-- Scripts -->
        @vite(['resources/js/app.js'])
    </head>
    <body>
        {{ $slot }}
    </body>
</html>
