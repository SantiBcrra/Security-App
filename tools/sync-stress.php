<?php
declare(strict_types=1);

/**
 * Prueba de estrés de la sincronización offline, por HTTP real contra el servidor (solo LOCAL).
 * Uso: php tools/sync-stress.php {url-base} {empresa} {usuario} {contraseña} [operaciones=500] [cada_cuantas_con_foto=10]
 *
 * Simula un celular que estuvo sin señal: manda las altas en lotes de 50, "pierde" respuestas
 * (reenvía lotes enteros), repite operaciones sueltas, sube fotos por partes con cortes (partes
 * repetidas, reanudación, "completar" repetido) y al final verifica que no haya NADA duplicado y
 * que cada foto guardada tenga exactamente el hash enviado.
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por consola.\n");
}
[$_, $base, $empresa, $usuario, $password] = array_pad($argv, 5, null);
$total = (int) ($argv[5] ?? 500);
$photoEvery = max(1, (int) ($argv[6] ?? 10));
if (!$base || !$empresa || !$usuario || !$password) {
    exit("Uso: php tools/sync-stress.php http://localhost/securityapp empresa usuario contraseña [500] [10]\n");
}
$base = rtrim($base, '/') . '/api/v1';
mt_srand(42);

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function api(string $method, string $url, ?string $token, mixed $body = null, array $query = [], bool $raw = false): array
{
    $ch = curl_init($url . ($query ? '?' . http_build_query($query) : ''));
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = $raw ? 'Content-Type: application/octet-stream' : 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw ? $body : json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $res = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status === 429) { // límite por dispositivo: esperar y reintentar, como el celular
        echo "  (429: límite de pedidos por minuto, espero 61 s…)\n";
        sleep(61);
        return api($method, $url, $token, $body, $query, $raw);
    }
    return [$status, json_decode((string) $res, true) ?? ['raw' => $res]];
}

$t0 = microtime(true);
$device = uuid4();
[$s, $login] = api('POST', "{$base}/auth/login", null, ['empresa' => $empresa, 'usuario' => $usuario, 'password' => $password, 'device_uuid' => $device, 'device_name' => 'sync-stress']);
if ($s !== 200) {
    exit("Login falló ({$s}): " . json_encode($login) . "\n");
}
$token = $login['data']['access_token'];
echo "Login OK. Bajando catálogos…\n";

// Pull completo (como la primera sincronización del celular)
$cursor = null;
$data = ['catalog_items' => [], 'sectors' => []];
do {
    [$s, $page] = api('GET', "{$base}/sync/pull", $token, null, array_filter(['cursor' => $cursor, 'limit' => 300]));
    foreach (['catalog_items', 'sectors'] as $e) {
        foreach ($page['data']['changes'][$e] ?? [] as $row) {
            $data[$e][$row['uuid']] = $row;
        }
    }
    $cursor = $page['data']['cursor'];
} while ($page['data']['has_more']);
$cat = array_values(array_filter($data['catalog_items'], fn ($c) => $c['catalog'] === 'categoria'));
$sev = array_values(array_filter($data['catalog_items'], fn ($c) => $c['catalog'] === 'severidad' && $c['level'] < 4));
$sectors = array_values($data['sectors']);
if (!$cat || !$sev || !$sectors) {
    exit("La empresa necesita catálogos y sectores cargados (correr tools/seed-demo.php antes).\n");
}

// 1) Operaciones generadas "offline"
$ops = [];
for ($i = 1; $i <= $total; $i++) {
    $ops[] = ['op_id' => uuid4(), 'type' => 'observation.create', 'data' => [
        'uuid' => uuid4(), 'category' => $cat[$i % count($cat)]['uuid'], 'severity' => $sev[$i % count($sev)]['uuid'],
        'sector' => $sectors[$i % count($sectors)]['uuid'],
        'description' => "[stress] Reporte offline #{$i} cargado sin señal en planta",
        'created_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200 + $i),
    ]];
}

// 2) Push en lotes de 50 con cortes simulados
$stats = ['lotes' => 0, 'reenvios_lote' => 0, 'ops_repetidas' => 0, 'ok' => 0, 'error' => 0];
$results = [];
foreach (array_chunk($ops, 49) as $batch) { // 49: deja lugar a una operación repetida (máx. 50 por envío)
    // A veces se repite una operación de un lote anterior (el celular no sabía si había llegado)
    if ($results && mt_rand(1, 3) === 1) {
        $batch[] = $ops[array_rand(array_slice($ops, 0, count($results)))];
        $stats['ops_repetidas']++;
    }
    [$s, $res] = api('POST', "{$base}/sync/push", $token, ['operations' => $batch]);
    $stats['lotes']++;
    if (mt_rand(1, 4) === 1) { // "se perdió la respuesta": el celular reenvía el lote entero
        [$s, $res] = api('POST', "{$base}/sync/push", $token, ['operations' => $batch]);
        $stats['reenvios_lote']++;
    }
    if ($s !== 200) {
        exit("Push falló ({$s}): " . json_encode($res) . "\n");
    }
    foreach ($res['data']['results'] as $r) {
        $results[$r['op_id']] = $r;
    }
}
foreach ($results as $r) {
    $stats[$r['status'] === 'ok' ? 'ok' : 'error']++;
}
echo "Push: " . json_encode($stats) . "\n";

// 3) Fotos por partes con cortes
$photoStats = ['fotos' => 0, 'partes' => 0, 'partes_repetidas' => 0, 'reanudaciones' => 0, 'complete_repetidos' => 0];
$expected = []; // obs uuid => [sha256...]
foreach ($ops as $i => $op) {
    if (($i + 1) % $photoEvery !== 0) {
        continue;
    }
    $img = imagecreatetruecolor(1600, 1200);
    for ($k = 0; $k < 400; $k++) {
        imagefilledellipse($img, mt_rand(0, 1600), mt_rand(0, 1200), mt_rand(5, 150), mt_rand(5, 150), imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    }
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    $sha = hash('sha256', $bytes);
    $obsUuid = $op['data']['uuid'];
    $id = uuid4();
    api('POST', "{$base}/uploads", $token, ['upload_uuid' => $id, 'observation_uuid' => $obsUuid, 'name' => "foto{$i}.jpg", 'size' => strlen($bytes), 'sha256' => $sha]);
    $chunk = 256 * 1024;
    $offset = 0;
    while ($offset < strlen($bytes)) {
        [$s, $r] = api('PUT', "{$base}/uploads/{$id}", $token, substr($bytes, $offset, $chunk), ['offset' => $offset], true);
        $photoStats['partes']++;
        if (mt_rand(1, 5) === 1) { // corte: se reenvía la misma parte
            [$s, $r] = api('PUT', "{$base}/uploads/{$id}", $token, substr($bytes, $offset, $chunk), ['offset' => $offset], true);
            $photoStats['partes_repetidas']++;
        }
        if (mt_rand(1, 8) === 1) { // corte largo: se consulta desde dónde reanudar
            [$s, $r] = api('GET', "{$base}/uploads/{$id}", $token);
            $photoStats['reanudaciones']++;
        }
        $offset = (int) ($r['data']['received_bytes'] ?? $offset + $chunk);
    }
    [$s, $done] = api('POST', "{$base}/uploads/{$id}/complete", $token);
    if (mt_rand(1, 3) === 1) {
        [$s, $done] = api('POST', "{$base}/uploads/{$id}/complete", $token);
        $photoStats['complete_repetidos']++;
    }
    if (($done['data']['status'] ?? '') !== 'completed') {
        exit("Foto no completada: " . json_encode($done) . "\n");
    }
    $expected[$obsUuid][] = $sha;
    $photoStats['fotos']++;
}
echo "Fotos: " . json_encode($photoStats) . "\n";

// 4) Verificación: nada duplicado y fotos íntegras
$problems = 0;
$byUuid = [];
$cursor = null;
do {
    [$s, $page] = api('GET', "{$base}/sync/pull", $token, null, array_filter(['cursor' => $cursor, 'limit' => 1000]));
    foreach ($page['data']['changes']['observations'] ?? [] as $o) {
        $byUuid[$o['uuid']] = $o['number'];
    }
    $cursor = $page['data']['cursor'];
} while ($page['data']['has_more']);
$numbers = [];
foreach ($ops as $op) {
    if (!isset($byUuid[$op['data']['uuid']])) {
        echo "FALTA la observación {$op['data']['uuid']}\n";
        $problems++;
        continue;
    }
    $numbers[] = $byUuid[$op['data']['uuid']];
}
foreach ($expected as $obsUuid => $want) {
    [$s, $o] = api('GET', "{$base}/observations/{$obsUuid}", $token);
    $got = array_column($o['data']['photos'] ?? [], 'sha256');
    sort($got);
    sort($want);
    if ($got !== $want) {
        echo "Fotos distintas en {$obsUuid}: esperadas " . count($want) . ', guardadas ' . count($got) . "\n";
        $problems++;
    }
}
// Además: en la base, cuántas observaciones de esta prueba existen (debe ser exactamente $total)
$dupNumbers = count($numbers) - count(array_unique($numbers));
printf("\nVerificación: %d operaciones → %d observaciones distintas (%d repetidas), %d fotos, %d problemas. %.1f s\n",
    count($ops), count(array_unique($numbers)), $dupNumbers, $photoStats['fotos'], $problems, microtime(true) - $t0);
echo ($problems === 0 && $dupNumbers === 0 && count(array_unique($numbers)) === count($ops)) ? "RESULTADO: OK, sin duplicados\n" : "RESULTADO: FALLÓ\n";
exit($problems === 0 && $dupNumbers === 0 ? 0 : 1);
