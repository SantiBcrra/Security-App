<?php
declare(strict_types=1);

/**
 * Etapa 10: las observaciones que ya tenían "acción asignada" (columnas assigned_user_id /
 * action_text / action_due_on) pasan a tener una acción CAPA de verdad.
 * - Observación cerrada o descartada → acción "verificada", marcada como migrada.
 * - Cualquier otro estado → acción "abierta" con el mismo responsable y fecha.
 * Idempotente: se saltean las observaciones que ya tienen alguna acción.
 */
return function (PDO $db): void {
    $rows = $db->query("SELECT o.* FROM observations o
        WHERE o.assigned_user_id IS NOT NULL AND o.action_text IS NOT NULL AND o.deleted_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.origin_type = 'observacion' AND a.origin_id = o.id)
        ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }
    $uuid = function (): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    };
    $assignedBy = $db->prepare("SELECT user_id, created_at FROM observation_events WHERE observation_id = ? AND type = 'assignment' ORDER BY id DESC LIMIT 1");
    $insert = $db->prepare('INSERT INTO actions (uuid, number, title, description, type, priority, origin_type, origin_id, site_id, sector_id,
            responsible_user_id, created_by, due_on, status, closed_at, closure_text, verified_at, verification_text, effective, created_at, updated_at)
        VALUES (?, ?, ?, NULL, \'correctiva\', \'media\', \'observacion\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
    $event = $db->prepare('INSERT INTO action_events (uuid, action_id, type, to_status, comment, actor_name, created_at)
        VALUES (?, ?, \'migrated\', ?, ?, \'Sistema\', UTC_TIMESTAMP())');

    foreach ($rows as $o) {
        $db->beginTransaction();
        try {
            $db->exec("INSERT IGNORE INTO sequences (name, value) VALUES ('actions', 0)");
            $number = (int) $db->query("SELECT value FROM sequences WHERE name = 'actions' FOR UPDATE")->fetchColumn() + 1;
            $db->prepare("UPDATE sequences SET value = ? WHERE name = 'actions'")->execute([$number]);
            $assignedBy->execute([(int) $o['id']]);
            $by = $assignedBy->fetch(PDO::FETCH_ASSOC) ?: ['user_id' => null, 'created_at' => $o['updated_at']];
            $done = in_array($o['status'], ['cerrada', 'descartada'], true);
            $closedAt = $done ? ($o['closed_at'] ?? $o['updated_at']) : null;
            $insert->execute([
                $uuid(), $number, mb_substr(trim((string) $o['action_text']), 0, 191), (int) $o['id'], $o['site_id'], $o['sector_id'],
                (int) $o['assigned_user_id'], $by['user_id'], $o['action_due_on'] ?? substr((string) $o['updated_at'], 0, 10),
                $done ? 'verificada' : 'abierta', $closedAt, $done ? 'Cerrada antes de la Etapa 10 (sin evidencia registrada).' : null,
                $closedAt, $done ? 'Migrada: la observación ya estaba cerrada.' : null, $done ? 1 : null, $by['created_at'],
            ]);
            $event->execute([$uuid(), (int) $db->lastInsertId(), $done ? 'verificada' : 'abierta',
                'Acción creada a partir de la asignación de la observación (migración Etapa 10).']);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
};
