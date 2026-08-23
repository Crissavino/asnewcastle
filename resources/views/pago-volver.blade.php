<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Volviendo a la app…</title>
    <style>
        body { margin: 0; font-family: -apple-system, system-ui, 'Segoe UI', Roboto, sans-serif;
            background: #F1F2EF; color: #121212; display: flex; min-height: 100vh;
            align-items: center; justify-content: center; text-align: center; }
        .box { padding: 32px 24px; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        p { font-size: 14px; color: #565b57; margin: 4px 0; }
        a { display: inline-block; margin-top: 16px; color: #fff; background: #D22233;
            text-decoration: none; padding: 12px 20px; border-radius: 3px; font-weight: 700; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Volviendo a la app…</h1>
        <p>Si no volvés solo en unos segundos, tocá el botón.</p>
        <a id="back" href="#">Volver a la app</a>
    </div>
    <script>
        // Reabre la app por el esquema propio: /pago/volver?to=cuota&suscripcion=ok
        // → asnewcastle://cuota?suscripcion=ok
        (function () {
            var p = new URLSearchParams(window.location.search);
            var to = p.get('to') || 'cuota';
            p.delete('to');
            var qs = p.toString();
            var deep = 'asnewcastle://' + to + (qs ? '?' + qs : '');
            document.getElementById('back').href = deep;
            window.location.href = deep;
        })();
    </script>
</body>
</html>
