<?php /** Estilos comunes de los imprimibles de incidentes. @var string $docTitle */ ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= e($docTitle) ?></title>
    <style>
        body { font: 10pt/1.35 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #212529; margin: 0; background: #f1f3f5; }
        .page { max-width: 190mm; margin: 0 auto; background: #fff; padding: 14mm; }
        .toolbar { text-align: center; padding: 10px; } .toolbar button { font: inherit; padding: 6px 16px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #212529; padding-bottom: 6px; margin-bottom: 10px; }
        h1 { font-size: 15pt; margin: 0; } h2 { font-size: 10.5pt; margin: 12px 0 4px; background: #f1f3f5; padding: 3px 6px; }
        table { width: 100%; border-collapse: collapse; } td { padding: 3px 6px; vertical-align: top; border-bottom: 1px solid #eee; }
        td.k { width: 32%; color: #6c757d; } .empty { color: #adb5bd; }
        .hash { font-family: monospace; font-size: 7.5pt; color: #6c757d; word-break: break-all; }
        .sign { display: flex; gap: 20mm; margin-top: 16mm; } .sign div { flex: 1; border-top: 1px solid #212529; text-align: center; padding-top: 3px; font-size: 9pt; }
        .note { font-size: 8.5pt; color: #6c757d; }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { padding: 0; } @page { size: A4; margin: 12mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Imprimir o guardar como PDF</button></div>
