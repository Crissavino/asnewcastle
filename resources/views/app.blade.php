<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#D22233">

    {{-- Nombre legal del club en el HTML servido: Meta lo exige en la web
         para asociar el dominio al negocio en la verificación de WhatsApp. --}}
    <meta name="description" content="App oficial de ASOCIAȚIA SPORTIVĂ NEW CASTLE (Asociatia Sportiva New Castle), club de fútbol de Voluntari, Ilfov, România. CIF 53035344.">
    <meta property="og:site_name" content="ASOCIAȚIA SPORTIVĂ NEW CASTLE">
    <meta property="og:title" content="ASOCIAȚIA SPORTIVĂ NEW CASTLE — A.S New Castle">

    <title inertia>{{ config('app.name') }}</title>

    <link rel="icon" href="/img/crest.png" type="image/png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/img/icons/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="New Castle">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Archivo:wght@400;500;600;700&family=Rubik:wght@500;600;700&display=swap" rel="stylesheet">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body>
    @inertia
    <noscript>
        ASOCIAȚIA SPORTIVĂ NEW CASTLE (Asociatia Sportiva New Castle) — Str. Emil Racoviță 27C, parter, ap. 2, Voluntari, Ilfov 077190, România. CIF 53035344.
    </noscript>
</body>
</html>
