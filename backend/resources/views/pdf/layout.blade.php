{{-- Plantilla comun de los PDF (evidencia y reportes). dompdf: CSS 2.1, sin recursos externos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>@yield('titulo')</title>
<style>
    @page { margin: 72px 48px 56px 48px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #2c2c2c; line-height: 1.45; }
    header.fijo { position: fixed; top: -52px; left: 0; right: 0; height: 34px; border-bottom: 1px solid #d8d8d8; }
    header.fijo .marca { font-size: 11pt; font-weight: bold; color: #212120; }
    header.fijo .marca span { color: #3f9fa1; }
    header.fijo .doc { float: right; font-size: 8pt; color: #777; margin-top: 3px; }
    footer.fijo { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 7pt; color: #777; }
    h1 { font-size: 15pt; margin: 0 0 4px 0; color: #212120; }
    h2 { font-size: 11pt; margin: 18px 0 6px 0; padding-bottom: 3px; border-bottom: 1px solid #e3e3e3; color: #212120; }
    .sub { color: #666; margin: 0 0 12px 0; }
    table { width: 100%; border-collapse: collapse; }
    table.datos td { padding: 4px 6px; vertical-align: top; border-bottom: 1px solid #efefef; }
    table.datos td.k { width: 30%; color: #666; }
    table.lista th { text-align: left; font-size: 8pt; color: #555; background: #f3f3f1; padding: 5px 6px; border-bottom: 1px solid #d8d8d8; }
    table.lista td { padding: 5px 6px; border-bottom: 1px solid #efefef; vertical-align: top; font-size: 8.5pt; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; word-wrap: break-word; }
    .ok { color: #2f7d4f; font-weight: bold; }
    .alerta { color: #b3261e; font-weight: bold; }
    .etiqueta { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 7.5pt; border: 1px solid #ccc; }
    .confirmado { background: #fbe9e7; border-color: #e5a39b; color: #9d2b1f; }
    .pendiente { background: #fff6e0; border-color: #e8c877; color: #7a5a06; }
    .descartado { background: #f1f1f1; color: #555; }
    .aviso { border: 1px solid #e8c877; background: #fffaf0; padding: 8px 10px; margin: 10px 0; font-size: 8.5pt; }
    .vacio { color: #888; font-style: italic; }
    .texto p { margin: 0 0 7px 0; }
</style>
</head>
<body>
<header class="fijo">
    <span class="marca">VERA <span>·</span> Verificación de Exposición a Riesgo Adverso</span>
    <span class="doc">@yield('titulo')</span>
</header>
<footer class="fijo">Generado el {{ $generado_en }} (hora de El Salvador). Documento confidencial: contiene datos personales tratados por encargo del sujeto obligado.</footer>

@yield('contenido')
</body>
</html>
