<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Employees;
use App\Resources\Resource;

final class EmployeesImporter extends EntityImporter
{
    public function key(): string { return 'empleados'; }
    public function label(): string { return 'Empleados'; }

    public function columns(): array
    {
        return [
            'dni'            => ['label' => 'DNI', 'required' => true, 'synonyms' => ['documento', 'nro documento', 'n documento', 'numero de documento', 'doc', 'nro doc']],
            'legajo'         => ['label' => 'Legajo', 'synonyms' => ['nro legajo', 'n legajo', 'numero de legajo', 'leg', 'file']],
            'apellido'       => ['label' => 'Apellido', 'synonyms' => ['apellidos']],
            'nombre'         => ['label' => 'Nombre', 'synonyms' => ['nombres']],
            'apellido_nombre'=> ['label' => 'Apellido y nombre', 'synonyms' => ['apellido y nombre', 'nombre completo', 'empleado', 'nombre y apellido', 'apellido nombre', 'trabajador'],
                                 'help' => 'Si viene todo junto: "Pérez, Juan" o "PEREZ JUAN" (primera palabra = apellido).'],
            'puesto'         => ['label' => 'Puesto', 'synonyms' => ['cargo', 'funcion', 'tarea', 'categoria']],
            'sector'         => ['label' => 'Sector', 'synonyms' => ['area', 'sector area', 'ubicacion', 'seccion'],
                                 'help' => 'Nombre del sector, o "Planta > Nave > Sector" si hay nombres repetidos.'],
            'contratista'    => ['label' => 'Contratista', 'synonyms' => ['empresa contratista', 'empresa'], 'help' => 'Vacío = personal propio.'],
            'fecha_ingreso'  => ['label' => 'Fecha de ingreso', 'synonyms' => ['ingreso', 'fecha de ingreso', 'fecha alta', 'alta', 'antiguedad']],
            'telefono'       => ['label' => 'Teléfono', 'synonyms' => ['celular', 'tel', 'movil']],
            'email'          => ['label' => 'Email', 'synonyms' => ['mail', 'correo', 'e mail', 'correo electronico']],
            'cuil'           => ['label' => 'CUIL', 'synonyms' => ['cuit', 'cuil cuit', 'nro cuil']],
            'fecha_nacimiento' => ['label' => 'Fecha de nacimiento', 'synonyms' => ['nacimiento', 'fecha nac', 'f nacimiento']],
            'sexo'           => ['label' => 'Sexo', 'synonyms' => ['genero'], 'help' => 'F, M o X.'],
            'domicilio'      => ['label' => 'Domicilio', 'synonyms' => ['direccion', 'domicilio particular']],
        ];
    }

    public function requiredAnyOf(): array
    {
        return [['apellido', 'apellido_nombre']];
    }

    public function rowKey(array $row): ?string
    {
        $dni = preg_replace('/\D/', '', (string) ($row['dni'] ?? ''));
        return $dni !== '' ? $dni : null;
    }

    public function example(): array
    {
        return ['30111222', '1001', 'Pérez', 'Juan', '', 'Soldador', 'Planta 1 > Nave A > Soldadura', '', '15/03/2021', '11 5555-1234', 'jperez@empresa.com',
            '20301112220', '02/05/1985', 'M', 'Av. Siempre Viva 742, Rosario'];
    }

    private static function gender(string $v): ?string
    {
        $v = mb_strtoupper(trim($v));
        return match (true) {
            $v === '' => null,
            in_array($v, ['F', 'FEMENINO', 'MUJER'], true) => 'F',
            in_array($v, ['M', 'MASCULINO', 'VARON', 'VARÓN', 'HOMBRE'], true) => 'M',
            in_array($v, ['X', 'NO BINARIO', 'OTRO'], true) => 'X',
            default => null,
        };
    }

    private function names(array $row): array
    {
        $last = trim((string) ($row['apellido'] ?? ''));
        $first = trim((string) ($row['nombre'] ?? ''));
        $full = trim((string) ($row['apellido_nombre'] ?? ''));
        if ($last === '' && $full !== '') {
            if (str_contains($full, ',')) {
                [$last, $first] = array_map('trim', explode(',', $full, 2));
            } else {
                $parts = preg_split('/\s+/', $full, 2);
                $last = $parts[0];
                $first = $parts[1] ?? '';
            }
        }
        $fix = fn (string $s) => mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
        return [$fix($last), $fix($first)];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $errors = [];
        $dni = $this->rowKey($row);
        if ($dni === null || !preg_match('/^\d{7,8}$/', $dni)) {
            $errors[] = 'DNI inválido (7 u 8 números).';
        }
        [$last, $first] = $this->names($row);
        if ($last === '' || $first === '') {
            $errors[] = 'Falta apellido o nombre.';
        }
        if (($row['sector'] ?? '') !== '') {
            [, $err] = $ctx->sector($row['sector']);
            if ($err) {
                $errors[] = $err;
            }
        }
        if (($row['fecha_ingreso'] ?? '') !== '' && Resource::parseDate($row['fecha_ingreso']) === null) {
            $errors[] = 'Fecha de ingreso inválida.';
        }
        $cuil = preg_replace('/\D/', '', (string) ($row['cuil'] ?? ''));
        if ($cuil !== '' && !\App\Core\Cuit::isValid($cuil)) {
            $errors[] = 'CUIL inválido.';
        }
        if (($row['fecha_nacimiento'] ?? '') !== '' && Resource::parseDate($row['fecha_nacimiento']) === null) {
            $errors[] = 'Fecha de nacimiento inválida.';
        }
        if (($row['sexo'] ?? '') !== '' && self::gender((string) $row['sexo']) === null) {
            $errors[] = 'Sexo: usá F, M o X.';
        }
        if (($row['email'] ?? '') !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email inválido.';
        }
        $existing = $dni ? Employees::findBy('dni', $dni) : null;
        $legajo = trim((string) ($row['legajo'] ?? ''));
        if ($legajo !== '') {
            $other = Employees::findBy('file_number', $legajo);
            if ($other && (!$existing || (int) $other['id'] !== (int) $existing['id'])) {
                $errors[] = "El legajo {$legajo} ya es de otro empleado (DNI {$other['dni']}).";
            }
        }
        return self::result($errors, $existing ? 'update' : 'create');
    }

    public function apply(array $row, ImportContext $ctx): string
    {
        $dni = $this->rowKey($row);
        [$last, $first] = $this->names($row);
        $data = self::nonEmpty([
            'dni'           => $dni,
            'last_name'     => $last,
            'first_name'    => $first,
            'file_number'   => trim((string) ($row['legajo'] ?? '')),
            'position_id'   => ($row['puesto'] ?? '') !== '' ? $ctx->positionId($row['puesto'], true) : null,
            'sector_id'     => ($row['sector'] ?? '') !== '' ? $ctx->sector($row['sector'])[0] : null,
            'contractor_id' => ($row['contratista'] ?? '') !== '' ? $ctx->contractorId($row['contratista'], true) : null,
            'hire_date'     => ($row['fecha_ingreso'] ?? '') !== '' ? Resource::parseDate($row['fecha_ingreso']) : null,
            'phone'         => trim((string) ($row['telefono'] ?? '')),
            'email'         => mb_strtolower(trim((string) ($row['email'] ?? ''))),
            'cuil'          => preg_replace('/\D/', '', (string) ($row['cuil'] ?? '')),
            'birth_date'    => ($row['fecha_nacimiento'] ?? '') !== '' ? Resource::parseDate($row['fecha_nacimiento']) : null,
            'gender'        => self::gender((string) ($row['sexo'] ?? '')),
            'address'       => trim((string) ($row['domicilio'] ?? '')),
        ]);
        $existing = Employees::findBy('dni', $dni);
        if ($existing) {
            Employees::update((int) $existing['id'], $data + ['is_active' => 1]);
            return 'updated';
        }
        Employees::create($data);
        return 'created';
    }
}
