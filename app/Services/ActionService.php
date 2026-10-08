<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Models\ActionAttachments;
use App\Models\ActionEvents;
use App\Models\Actions;
use App\Models\Sectors;
use App\Models\Sequences;
use App\Models\Settings;
use App\Models\Users;
use App\Resources\Resource;
use App\Services\Notify\Notifier;

/**
 * Acciones correctivas y preventivas (CAPA): alta desde cualquier origen, flujo
 * abierta → en curso → cerrada (con evidencia) → verificada por otra persona, y su línea de tiempo.
 */
final class ActionService
{
    public const MODULE = 'acciones';
    public const FILE_TYPES = ImageProcessor::TYPES + ['application/pdf' => 'pdf'];
    public const MAX_FILES = 10;

    /** Fecha de hoy en la zona de la empresa (las fechas límite son locales). */
    public static function today(): string
    {
        return fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d');
    }

    /**
     * Alcance para listar/ver. null = todo. Sin permiso en el módulo igual ve las acciones de las
     * que es responsable (el responsable siempre ve la suya).
     */
    public static function scope(): ?array
    {
        $me = (int) (UserAuth::user()['id'] ?? 0);
        return match (UserAuth::scope(self::MODULE)) {
            'todo'     => null,
            'sectores' => ['sector_ids' => SectorScope::sectorIds(self::MODULE) ?? [], 'user_id' => $me],
            'propios'  => ['own' => $me],
            default    => ['responsible' => $me],
        };
    }

    public static function canView(array $a): bool
    {
        $scope = self::scope();
        if ($scope === null) {
            return true;
        }
        $me = (int) (UserAuth::user()['id'] ?? 0);
        if ($me > 0 && (int) $a['responsible_user_id'] === $me) {
            return true;
        }
        if (isset($scope['responsible'])) {
            return false;
        }
        if ((int) ($a['created_by'] ?? 0) === $me && $me > 0) {
            return true;
        }
        return isset($scope['sector_ids']) && in_array((int) $a['sector_id'], $scope['sector_ids'], true);
    }

    public static function isResponsible(array $a): bool
    {
        $me = (int) (UserAuth::user()['id'] ?? 0);
        return $me > 0 && (int) $a['responsible_user_id'] === $me;
    }

    /** ¿El usuario actual puede hacer esta transición sobre la acción? (sin mirar el estado) */
    public static function canDo(array $a, string $key): bool
    {
        $t = ActionWorkflow::TRANSITIONS[$key] ?? null;
        if ($t === null || !self::canView($a)) {
            return false;
        }
        if (in_array($key, ['verificar', 'rechazar'], true) && (int) ($a['closed_by'] ?? 0) === (int) (UserAuth::user()['id'] ?? -1)) {
            return false; // quien cerró no verifica su propio cierre
        }
        return ($t['owner'] && self::isResponsible($a)) || UserAuth::can(self::MODULE, $t['perm']);
    }

    public static function canEdit(array $a): bool
    {
        return ActionWorkflow::isOpen($a['status']) && self::canView($a) && UserAuth::can(self::MODULE, 'editar');
    }

    /** Usuarios que pueden ser responsables (activos y con la cuenta activada). */
    public static function assignableUsers(): array
    {
        return array_values(array_filter(Users::all(), fn ($u) => Users::canSignIn($u)));
    }

    /**
     * Valida los datos de una acción. Claves: title, description, type, priority, responsible (uuid),
     * due_on, sector (uuid, opcional). @return array{0: array, 1: array<string,string>} [datos, errores]
     */
    public static function validate(array $in, bool $requireFuture = true): array
    {
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) < 5) {
            $errors['title'] = 'Describí la acción a realizar (mínimo 5 caracteres).';
        }
        $type = (string) ($in['type'] ?? 'correctiva');
        $priority = (string) ($in['priority'] ?? 'media');
        if (!isset(ActionWorkflow::TYPES[$type])) {
            $errors['type'] = 'Tipo de acción inválido.';
        }
        if (!isset(ActionWorkflow::PRIORITIES[$priority])) {
            $errors['priority'] = 'Prioridad inválida.';
        }
        $responsible = Users::findByUuid((string) ($in['responsible'] ?? ''));
        if ($responsible === null || !Users::canSignIn($responsible)) {
            $errors['responsible'] = 'Elegí el responsable (un usuario activo).';
        }
        $due = Resource::parseDate((string) ($in['due_on'] ?? ''));
        if ($due === null) {
            $errors['due_on'] = 'Indicá la fecha límite.';
        } elseif ($requireFuture && $due < self::today()) {
            $errors['due_on'] = 'La fecha límite no puede ser anterior a hoy.';
        }
        $sector = null;
        if (($in['sector'] ?? '') !== '') {
            $sector = Sectors::findByUuid((string) $in['sector']);
            if ($sector === null) {
                $errors['sector'] = 'Sector inválido.';
            }
        }
        $data = [
            'title'               => mb_substr($title, 0, 191),
            'description'         => trim((string) ($in['description'] ?? '')) ?: null,
            'type'                => $type,
            'priority'            => $priority,
            'responsible_user_id' => $responsible ? (int) $responsible['id'] : null,
            'due_on'              => $due,
            'sector_id'           => $sector ? (int) $sector['id'] : null,
            'site_id'             => $sector ? (int) $sector['site_id'] : null,
        ];
        return [$data, $errors];
    }

    /**
     * Alta. $data ya validado. Si ya hay una transacción abierta (ej. la de la observación que la
     * origina) se suma a ella. @param list<array{tmp:string, name:?string, upload:bool}> $files
     * @return array{action: array, file_errors: list<string>}
     */
    public static function create(array $data, string $originType, ?int $originId, array $files = []): array
    {
        $id = self::insert($data, $originType, $originId);
        $action = Actions::findById($id);
        $fileErrors = $files ? self::storeFiles($action, $files, 'referencia') : [];
        self::afterCreate($action);
        return ['action' => Actions::findById($id), 'file_errors' => $fileErrors];
    }

    /** Inserta la acción y su primer evento. Usar afterCreate() después del commit. */
    public static function insert(array $data, string $originType, ?int $originId): int
    {
        $db = DB::tenant();
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $number = Sequences::next('actions');
            $id = Actions::create($data + [
                'number'      => $number,
                'origin_type' => isset(ActionWorkflow::ORIGINS[$originType]) ? $originType : 'manual',
                'origin_id'   => $originId,
                'created_by'  => UserAuth::user()['id'] ?? null,
                'status'      => 'abierta',
            ]);
            $responsible = Users::findById((int) $data['responsible_user_id']);
            ActionEvents::add($id, 'created', ['to' => 'abierta', 'data' => [
                'responsable' => $responsible['name'] ?? null, 'fecha_limite' => $data['due_on'], 'prioridad' => $data['priority'],
            ]] + self::actor());
            if ($own) {
                $db->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($own) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function afterCreate(array $action): void
    {
        Audit::tenant('action.create', 'action', $action['uuid'], null, [
            'numero' => Actions::format((int) $action['number']), 'titulo' => $action['title'],
            'origen' => $action['origin_type'], 'fecha_limite' => $action['due_on'],
        ]);
        self::syncOrigin($action);
        Notifier::dispatch('action.assigned', $action);
    }

    /**
     * Cambio de estado. @param array $files evidencia que llega con el cierre
     * @return ?string mensaje de error (null = ok)
     */
    public static function transition(array $a, string $key, array $input, array $files = []): ?string
    {
        $t = ActionWorkflow::TRANSITIONS[$key] ?? null;
        if ($t === null) {
            return 'Acción desconocida.';
        }
        if (!self::canDo($a, $key)) {
            return in_array($key, ['verificar', 'rechazar'], true) && (int) ($a['closed_by'] ?? 0) === (int) (UserAuth::user()['id'] ?? -1)
                ? 'La verificación la tiene que hacer otra persona, no quien cerró la acción.'
                : 'No tenés permiso para esta acción.';
        }
        if (!in_array($a['status'], $t['from'], true)) {
            return self::stateError($a['status'], $t);
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($t['comment'] && mb_strlen($comment) < 5) {
            return 'Escribí el motivo (mínimo 5 caracteres).';
        }
        $now = gmdate('Y-m-d H:i:s');
        $me = UserAuth::user()['id'] ?? null;
        $changes = ['status' => $t['to']];
        $data = null;

        if ($key === 'cerrar') {
            $closure = trim((string) ($input['closure_text'] ?? ''));
            if (mb_strlen($closure) < 10) {
                return 'Contá qué se hizo para resolverla (mínimo 10 caracteres).';
            }
            if (count($files) > self::MAX_FILES) {
                return 'Máximo ' . self::MAX_FILES . ' archivos por vez.';
            }
            $fileErrors = $files ? self::storeFiles($a, $files, 'evidencia') : [];
            if (ActionAttachments::countEvidence((int) $a['id'], (int) $a['cycle']) === 0) {
                return 'Para cerrar hace falta al menos una foto o un PDF de evidencia.' . ($fileErrors ? ' ' . implode(' ', $fileErrors) : '');
            }
            $days = max(1, (int) Settings::get('acciones.dias_verificacion', '15'));
            $changes += ['closed_at' => $now, 'closed_by' => $me, 'closure_text' => $closure,
                'verify_due_on' => (new \DateTimeImmutable(self::today()))->modify("+{$days} days")->format('Y-m-d')];
            $comment = $closure;
        } elseif ($key === 'verificar') {
            $changes += ['verified_at' => $now, 'verified_by' => $me, 'verification_text' => $comment ?: null, 'effective' => 1];
        } elseif ($key === 'rechazar') {
            $due = ($input['due_on'] ?? '') !== '' ? Resource::parseDate((string) $input['due_on']) : null;
            if (($input['due_on'] ?? '') !== '' && ($due === null || $due < self::today())) {
                return 'La nueva fecha límite tiene que ser hoy o posterior.';
            }
            // Nueva vuelta de trabajo: la evidencia del cierre rechazado queda en su ciclo, en la historia.
            $changes += ['cycle' => (int) $a['cycle'] + 1, 'closed_at' => null, 'closed_by' => null, 'closure_text' => null,
                'verify_due_on' => null, 'effective' => null];
            if ($due !== null) {
                $changes['due_on'] = $due;
            }
            $data = ['cierre_rechazado' => $a['closure_text'], 'ciclo' => (int) $a['cycle'], 'nueva_fecha' => $due];
        }

        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $locked = Actions::findById((int) $a['id'], true);
            if (!in_array($locked['status'], $t['from'], true) || (int) $locked['cycle'] !== (int) $a['cycle']) {
                $db->rollBack();
                return self::stateError($locked['status'], $t);
            }
            Actions::update((int) $a['id'], $changes);
            $type = ['tomar' => 'started', 'cerrar' => 'closed', 'verificar' => 'verified', 'rechazar' => 'rejected', 'cancelar' => 'cancelled'][$key];
            ActionEvents::add((int) $a['id'], $type, ['from' => $locked['status'], 'to' => $t['to'], 'comment' => $comment ?: null, 'data' => $data] + self::actor());
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('action.' . $key, 'action', $a['uuid'], ['estado' => $a['status']], ['estado' => $t['to']] + ($data ?? []));
        $fresh = Actions::findById((int) $a['id']);
        self::syncOrigin($fresh);
        $event = ['cerrar' => 'action.closed', 'verificar' => 'action.verified', 'rechazar' => 'action.rejected', 'cancelar' => 'action.cancelled'][$key] ?? null;
        if ($event !== null) {
            Notifier::dispatch($event, $fresh, ['comment' => $key === 'cerrar' ? '' : $comment]);
        }
        return null;
    }

    /** Reasignar, cambiar fecha, prioridad o descripción. Cambiar responsable o fecha pide motivo. */
    public static function update(array $a, array $input): ?string
    {
        if (!self::canEdit($a)) {
            return 'No podés editar esta acción.';
        }
        [$data, $errors] = self::validate($input + ['title' => $a['title']], false);
        unset($data['site_id'], $data['sector_id']); // el sector viene del origen o del alta
        if ($errors) {
            return reset($errors);
        }
        if ($data['due_on'] !== $a['due_on'] && $data['due_on'] < self::today()) {
            return 'La fecha límite no puede ser anterior a hoy.';
        }
        $labels = ['title' => 'Acción', 'description' => 'Detalle', 'type' => 'Tipo', 'priority' => 'Prioridad', 'responsible_user_id' => 'Responsable', 'due_on' => 'Fecha límite'];
        $show = fn (string $col, $v) => match ($col) {
            'responsible_user_id' => Users::findById((int) $v)['name'] ?? null,
            'type'     => ActionWorkflow::TYPES[$v] ?? $v,
            'priority' => ActionWorkflow::PRIORITIES[$v] ?? $v,
            default    => $v,
        };
        $changes = $before = $after = [];
        foreach ($labels as $col => $label) {
            if ((string) ($a[$col] ?? '') !== (string) ($data[$col] ?? '')) {
                $changes[$col] = $data[$col];
                $before[$label] = $show($col, $a[$col]);
                $after[$label] = $show($col, $data[$col]);
            }
        }
        if (!$changes) {
            return 'No hay cambios.';
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if ((isset($changes['responsible_user_id']) || isset($changes['due_on'])) && mb_strlen($comment) < 5) {
            return 'Para cambiar el responsable o la fecha límite escribí el motivo (mínimo 5 caracteres).';
        }
        Actions::update((int) $a['id'], $changes);
        ActionEvents::add((int) $a['id'], 'updated', ['comment' => $comment ?: null, 'data' => ['antes' => $before, 'despues' => $after]] + self::actor());
        Audit::tenant('action.update', 'action', $a['uuid'], $before, $after);
        $fresh = Actions::findById((int) $a['id']);
        self::syncOrigin($fresh);
        if (isset($changes['responsible_user_id'])) {
            Notifier::dispatch('action.assigned', $fresh); // al nuevo responsable
        }
        return null;
    }

    public static function comment(array $a, string $comment): ?string
    {
        $comment = trim($comment);
        if (mb_strlen($comment) < 2) {
            return 'El comentario está vacío.';
        }
        ActionEvents::add((int) $a['id'], 'comment', ['comment' => mb_substr($comment, 0, 5000)] + self::actor());
        return null;
    }

    /** Agregar evidencia antes de cerrar (queda en el ciclo actual). */
    public static function addEvidence(array $a, array $files): array
    {
        if (!self::canAddEvidence($a)) {
            return ['No podés agregar evidencia a esta acción.'];
        }
        return self::storeFiles($a, array_slice($files, 0, self::MAX_FILES), 'evidencia');
    }

    /** ¿Puede agregar evidencia? El responsable o quien puede cerrar, mientras la acción esté abierta. */
    public static function canAddEvidence(array $a): bool
    {
        return ActionWorkflow::isOpen($a['status']) && self::canView($a) && (self::isResponsible($a) || UserAuth::can(self::MODULE, 'cerrar'));
    }

    /** @return list<string> errores por archivo (los válidos se guardan igual) */
    public static function storeFiles(array $a, array $files, string $kind): array
    {
        $errors = [];
        foreach ($files as $f) {
            try {
                self::storeFile($a, $f, $kind, false);
            } catch (\DomainException $e) {
                $errors[] = ($f['name'] ?? 'Archivo') . ': ' . $e->getMessage();
            }
        }
        $saved = count($files) - count($errors);
        if ($saved > 0) {
            ActionEvents::add((int) $a['id'], 'evidence', ['data' => ['archivos' => $saved, 'tipo' => $kind]] + self::actor());
            Actions::update((int) $a['id'], []); // updated_at: la app de campo se entera por la sync
        }
        return $errors;
    }

    /**
     * Guarda un archivo (foto o PDF) como evidencia inmutable del ciclo actual. @return string uuid del adjunto
     * @throws \DomainException tipo o tamaño inválido
     */
    public static function storeFile(array $a, array $f, string $kind, bool $logEvent = true): string
    {
        $tenant = Tenant::current()['uuid'];
        $me = UserAuth::user()['id'] ?? null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp']) ?: '';
        if (isset(ImageProcessor::TYPES[$mime])) {
            $meta = ImageProcessor::store($tenant, $f['tmp'], $f['name'] ?? null, $f['upload'] ?? true, $me, 'actions');
        } else {
            $relative = TenantFiles::storeFile($tenant, $f['tmp'], 'actions/' . gmdate('Y') . '/' . gmdate('m'),
                self::FILE_TYPES, ImageProcessor::MAX_BYTES, $f['upload'] ?? true);
            $full = TenantFiles::path($tenant, $relative);
            $meta = ['path' => $relative, 'original_name' => isset($f['name']) ? mb_substr(basename((string) $f['name']), 0, 191) : null,
                'mime' => $mime, 'size_bytes' => (int) filesize($full), 'sha256' => hash_file('sha256', $full), 'uploaded_by' => $me];
        }
        $uuid = ActionAttachments::create((int) $a['id'], $kind, (int) $a['cycle'], $meta);
        if ($logEvent) {
            ActionEvents::add((int) $a['id'], 'evidence', ['data' => ['archivos' => 1, 'tipo' => $kind]] + self::actor());
            Actions::update((int) $a['id'], []);
        }
        return $uuid;
    }

    /**
     * CSV para Excel (separador ";" y BOM UTF-8, como lo abre Excel en español).
     * @param list<array> $rows filas de Actions::search()
     */
    public static function csv(array $rows, string $today): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Número', 'Acción', 'Estado', 'Vencida', 'Días de atraso', 'Prioridad', 'Tipo', 'Origen', 'Sector', 'Responsable',
            'Fecha límite', 'Creada', 'Cerrada', 'Verificada', 'Cierre'], ';');
        foreach ($rows as $a) {
            $late = ActionWorkflow::isOverdue($a, $today);
            fputcsv($out, [
                Actions::format((int) $a['number']), $a['title'], ActionWorkflow::label($a['status']), $late ? 'Sí' : 'No',
                $late ? (int) (new \DateTimeImmutable($a['due_on']))->diff(new \DateTimeImmutable($today))->format('%a') : '',
                ActionWorkflow::PRIORITIES[$a['priority']] ?? $a['priority'], ActionWorkflow::TYPES[$a['type']] ?? $a['type'],
                ActionWorkflow::ORIGINS[$a['origin_type']] ?? $a['origin_type'], $a['sector_name'] ?? '', $a['responsible_name'],
                date('d/m/Y', strtotime($a['due_on'])), fecha($a['created_at'], 'd/m/Y'), fecha($a['closed_at'], 'd/m/Y'),
                fecha($a['verified_at'], 'd/m/Y'), (string) ($a['closure_text'] ?? ''),
            ], ';');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** Mantiene al día el origen (hoy: la observación) después de cualquier cambio en la acción. */
    public static function syncOrigin(array $a): void
    {
        if ($a['origin_type'] === 'observacion' && $a['origin_id'] !== null) {
            ObservationService::syncActions((int) $a['origin_id']);
        }
    }

    private static function stateError(string $status, array $t): string
    {
        return 'La acción ya está "' . ActionWorkflow::label($status) . '": no se puede "' . mb_strtolower($t['label']) . '". Recargá la página.';
    }

    private static function actor(): array
    {
        $user = UserAuth::user();
        if ($user !== null) {
            return ['user_id' => (int) $user['id'], 'actor_name' => $user['name']];
        }
        $admin = AdminAuth::user();
        return ['user_id' => null, 'actor_name' => $admin ? $admin['name'] . ' (soporte)' : 'Sistema'];
    }
}
