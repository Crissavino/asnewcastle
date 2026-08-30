<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
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
    {{-- Splash de carga: el escudo sobre el rojo del club, en vez del negro
         mientras carga el JS. Se va (fade) cuando la app monta (app.jsx). --}}
    <style>
        html, body { background: #E3E5E0; }
        #splash { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center;
            background: #D22233; transition: opacity .35s ease; }
        #splash.gone { opacity: 0; pointer-events: none; }
        #splash img { width: 40%; max-width: 190px; animation: splash-pulse 1.4s ease-in-out infinite; }
        @keyframes splash-pulse { 0%, 100% { transform: scale(1); opacity: .92; } 50% { transform: scale(1.06); opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { #splash img { animation: none; } }
    </style>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body>
    <div id="splash"><img src="/img/crest-white.png" alt="A.S New Castle"></div>
    @inertia
    <noscript>
        ASOCIAȚIA SPORTIVĂ NEW CASTLE (Asociatia Sportiva New Castle) — Voluntari, Ilfov, România. CIF 53035344.
    </noscript>
</body>
</html>
