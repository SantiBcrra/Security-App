<?php
/** Parte diario del libro de guardia (folios, firmas y verificación de la cadena). @var array $site @var string $day @var array $entries @var array $verify */
use App\Services\Logbook;
$tenant = App\Core\Tenant::current();
$first = $entries ? (int) $entries[0]['folio'] : null;
$last = $entries ? (int) end($entries)['folio'] : null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Libro de guardia · <?= e($site['name']) ?> · <?= e(date('d/m/Y', strtotime($day))) ?></title>
    <style>
        body { font: 9.5pt/1.35 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #212529; margin: 0; background: #f1f3f5; }
        .page { max-width: 190mm; margin: 0 auto; background: #fff; padding: 12mm; }
        .toolbar { text-align: center; padding: 10px; } .toolbar button { font: inherit; padding: 6px 16px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; }
        header { border-bottom: 3px solid #212529; padding-bottom: 6px; margin-bottom: 8px; display: flex; justify-content: space-between; gap: 8mm; }
        h1 { font-size: 15pt; margin: 0; }
        table { width: 100%; border-collapse: collapse; } th { text-align: left; font-size: 8pt; color: #6c757d; border-bottom: 1px solid #adb5bd; }
        td { padding: 3px 4px; vertical-align: top; border-bottom: 1px solid #e9ecef; } td.f { width: 11mm; font-weight: 700; } td.h { width: 12mm; } td.k { width: 26mm; }
        .imp { background: #fdecee; } .sig { height: 14mm; border-bottom: 1px solid #212529; } .muted { color: #6c757d; } .hash { font-family: monospace; font-size: 7pt; color: #6c757d; word-break: break-all; }
        .ok { color: #1e7b45; font-weight: 700; } .bad { color: #b02a37; font-weight: 700; }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { padding: 0; } @page { size: A4; margin: 10mm; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Imprimir</button></div>
<div class="page">
    <header>
        <div><h1>Libro de guardia — parte diario</h1>
            <div><?= e($tenant['name']) ?> · <strong><?= e($site['name']) ?></strong></div></div>
        <div style="text-align: right">
            <div><strong><?= e(date('d/m/Y', strtotime($day))) ?></strong></div>
            <div class="muted"><?= $first ? "Folios {$first} a {$last}" : 'Sin anotaciones' ?></div>
        </div>
    </header>
    <table>
        <thead><tr><th>Folio</th><th>Hora</th><th>Tipo</th><th>Detalle</th><th>Guardia</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
            <tr class="<?= $e['important'] ? 'imp' : '' ?>">
                <td class="f"><?= (int) $e['folio'] ?></td>
                <td class="h"><?= e(fecha($e['occurred_at'], 'H:i')) ?></td>
                <td class="k"><?= e(Logbook::KINDS[$e['kind']] ?? $e['kind']) ?><?= $e['kind'] === 'novedad' && $e['category'] ? '<br><span class="muted">' . e(Logbook::CATEGORIES[$e['category']] ?? '') . '</span>' : '' ?><?= $e['important'] ? '<br><strong>IMPORTANTE</strong>' : '' ?></td>
                <td>
                    <?php if (in_array($e['kind'], ['persona', 'vehiculo'], true)): ?>
                        <strong><?= e(Logbook::label($e)) ?></strong><?= $e['visiting'] ? ' → ' . e($e['visiting']) : '' ?><?= $e['reason'] ? ' · ' . e($e['reason']) : '' ?>
                        <?= $e['badge'] ? ' · tarjeta ' . e($e['badge']) : '' ?><?= $e['load_note'] ? ' · carga: ' . e($e['load_note']) : '' ?>
                        <span class="muted"> · <?= $e['exited_at'] ? 'salió ' . e(fecha($e['exited_at'], 'd/m H:i')) : 'sin salida' ?></span>
                    <?php elseif (in_array($e['kind'], ['salida', 'correccion', 'recepcion'], true)): ?>
                        <span class="muted">Ref. folio <?= (int) $e['ref_folio'] ?>.</span>
                    <?php endif; ?>
                    <?php if ($e['text']): ?><div><?= nl2br(e($e['text'])) ?></div><?php endif; ?>
                    <?php if ($e['kind'] === 'cierre'): $d = json_decode((string) $e['data'], true) ?: []; ?>
                        <div class="muted">Cierre de turno<?= !empty($d['adentro']) ? ' · quedaron adentro: ' . e(implode('; ', array_column($d['adentro'], 'que'))) : '' ?></div>
                    <?php endif; ?>
                    <?php if ($e['signature_path']): ?><img class="sig" src="<?= e(url('/panel/libro/archivo/' . $e['uuid'] . '/firma')) ?>" alt="Firma"><?php endif; ?>
                </td>
                <td><?= e($e['user_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top: 8mm">
        <?php if ($verify['ok']): ?><span class="ok">Libro íntegro:</span> las <?= (int) $verify['checked'] ?> anotaciones del predio hasta el folio <?= (int) $last ?> conservan su huella original
        (cada una encadenada con la anterior).<?php else: ?><span class="bad">ATENCIÓN: alteración detectada en el folio <?= (int) $verify['broken_folio'] ?>.</span><?php endif; ?>
    </p>
    <?php if ($entries): ?><p class="hash">Huella del folio <?= (int) $last ?>: <?= e(end($entries)['hash']) ?></p><?php endif; ?>
    <p class="muted">Generado el <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?> por <?= e(App\Services\UserAuth::user()['name'] ?? '') ?>.</p>
</div>
</body>
</html>
