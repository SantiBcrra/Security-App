<?php
declare(strict_types=1);

namespace App\Services;

/** Catálogos configurables de cada empresa (todos en la tabla catalog_items). */
final class Catalogs
{
    public const DEFINITIONS = [
        'tipo_riesgo' => ['slug' => 'riesgos', 'singular' => 'Tipo de riesgo', 'plural' => 'Tipos de riesgo', 'new' => 'Nuevo tipo de riesgo'],
        'categoria'   => ['slug' => 'categorias', 'singular' => 'Categoría', 'plural' => 'Categorías', 'new' => 'Nueva categoría'],
        'severidad'   => ['slug' => 'severidades', 'singular' => 'Severidad', 'plural' => 'Severidades', 'new' => 'Nueva severidad', 'levels' => true],
        'causa'       => ['slug' => 'causas', 'singular' => 'Causa', 'plural' => 'Causas', 'new' => 'Nueva causa'],
        'tipo_equipo' => ['slug' => 'tipos-equipo', 'singular' => 'Tipo de equipo', 'plural' => 'Tipos de equipo', 'new' => 'Nuevo tipo de equipo'],
        // Incidentes (Etapa 12)
        'lesion'          => ['slug' => 'lesiones', 'singular' => 'Naturaleza de la lesión', 'plural' => 'Naturalezas de lesión', 'new' => 'Nueva naturaleza de lesión'],
        'parte_cuerpo'    => ['slug' => 'partes-cuerpo', 'singular' => 'Parte del cuerpo', 'plural' => 'Partes del cuerpo', 'new' => 'Nueva parte del cuerpo'],
        'forma_accidente' => ['slug' => 'formas-accidente', 'singular' => 'Forma del accidente', 'plural' => 'Formas del accidente', 'new' => 'Nueva forma del accidente'],
    ];
}
