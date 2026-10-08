<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Request;
use App\Models\Contractors;
use App\Models\Employees;
use App\Models\Positions;
use App\Models\Sectors;
use App\Models\Users;

final class EmployeesResource extends Resource
{
    public function key(): string { return 'empleados'; }
    public function model(): string { return Employees::class; }
    public function singular(): string { return 'Empleado'; }
    public function plural(): string { return 'Empleados'; }
    public function entity(): string { return 'employee'; }

    public function title(array $row): string
    {
        return $row['last_name'] . ', ' . $row['first_name'];
    }

    public function fields(?array $current): array
    {
        return [
            ['name' => 'last_name', 'label' => 'Apellido', 'type' => 'text', 'required' => true, 'max' => 80],
            ['name' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'max' => 80],
            ['name' => 'dni', 'label' => 'DNI', 'type' => 'text', 'required' => true, 'max' => 12, 'unique' => true, 'col' => 4],
            ['name' => 'file_number', 'label' => 'Legajo', 'type' => 'text', 'max' => 30, 'unique' => true, 'col' => 4],
            ['name' => 'hire_date', 'label' => 'Fecha de ingreso', 'type' => 'date', 'col' => 4],
            ['name' => 'cuil', 'label' => 'CUIL', 'type' => 'cuit', 'col' => 4, 'help' => 'Para la denuncia ante la ART.'],
            ['name' => 'birth_date', 'label' => 'Fecha de nacimiento', 'type' => 'date', 'col' => 4],
            ['name' => 'gender', 'label' => 'Sexo', 'type' => 'select', 'col' => 4, 'options' => ['F' => 'Femenino', 'M' => 'Masculino', 'X' => 'No binario / otro']],
            ['name' => 'address', 'label' => 'Domicilio', 'type' => 'text', 'max' => 191],
            self::ref('position_id', 'Puesto', Positions::class),
            self::ref('sector_id', 'Sector', Sectors::class),
            self::ref('contractor_id', 'Contratista', Contractors::class, false, ['help' => 'Vacío = personal propio de la empresa.']),
            ['name' => 'user_id', 'label' => 'Usuario del sistema', 'type' => 'ref', 'model' => Users::class,
                'options' => fn (?int $id) => array_column(Users::all(), 'name', 'uuid'),
                'help' => 'Opcional: si esta persona además ingresa al sistema.'],
            ['name' => 'phone', 'label' => 'Teléfono', 'type' => 'tel', 'max' => 40],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'max' => 191],
        ];
    }

    public function columns(): array
    {
        return [
            ['label' => 'Empleado', 'value' => fn ($r) => e($r['last_name'] . ', ' . $r['first_name'])
                . '<div class="small text-body-secondary">DNI ' . e($r['dni']) . ($r['file_number'] ? ' · Legajo ' . e($r['file_number']) : '') . '</div>'],
            ['label' => 'Puesto', 'value' => fn ($r) => e($r['position_name'])],
            ['label' => 'Sector', 'value' => fn ($r) => e($r['sector_name'])],
            ['label' => 'Empresa', 'value' => fn ($r) => $r['contractor_name'] ? e($r['contractor_name']) : '<span class="text-body-secondary">Propio</span>'],
        ];
    }

    public function filterControls(): array
    {
        return [
            'sector'   => ['Sector', Sectors::options()],
            'puesto'   => ['Puesto', Positions::options()],
            'tipo'     => ['Tipo', ['propio' => 'Personal propio', 'contratista' => 'De contratistas']],
        ];
    }

    public function rows(Request $request, bool $includeInactive): array
    {
        $filters = [];
        if (($s = Sectors::findByUuid((string) $request->input('sector', ''))) !== null) {
            $filters['sector_id'] = (int) $s['id'];
        }
        if (($p = Positions::findByUuid((string) $request->input('puesto', ''))) !== null) {
            $filters['position_id'] = (int) $p['id'];
        }
        $rows = Employees::list((string) $request->input('q', ''), $includeInactive, $filters, 2000);
        $tipo = $request->input('tipo');
        if ($tipo === 'propio' || $tipo === 'contratista') {
            $rows = array_values(array_filter($rows, fn ($r) => ($r['contractor_id'] === null) === ($tipo === 'propio')));
        }
        return $rows;
    }

    public function toRow(array $data, ?array $current): array
    {
        $row = parent::toRow($data, $current);
        $row['dni'] = $row['dni'] !== null ? preg_replace('/\D/', '', (string) $row['dni']) : null;
        return $row;
    }

    protected function extraValidate(array $data, ?array $current, array $errors): array
    {
        $dni = preg_replace('/\D/', '', (string) ($data['dni'] ?? ''));
        if (!isset($errors['dni']) && $dni !== '' && !preg_match('/^\d{7,8}$/', $dni)) {
            $errors['dni'] = 'DNI: entre 7 y 8 números.';
        } elseif (!isset($errors['dni']) && $dni !== '' && Employees::exists('dni', $dni, $current ? (int) $current['id'] : null)) {
            $errors['dni'] = 'DNI: ya existe otro empleado con ese DNI.';
        }
        return $errors;
    }
}
