<?php
// Plantilla de rubro: industria metalúrgica / metalmecánica.
// Catálogos: tipo_riesgo, categoria, severidad, causa, tipo_equipo. Ítems = nombre o array con más datos.
return [
    'name' => 'Metalúrgica / metalmecánica',
    'catalogs' => [
        'tipo_riesgo' => [
            'Caída de personas a distinto nivel', 'Caída de personas al mismo nivel', 'Caída de objetos o materiales',
            'Golpes contra objetos', 'Cortes con chapas, rebabas o herramientas', 'Atrapamiento por partes móviles de máquinas',
            'Proyección de partículas', 'Quemaduras (soldadura, oxicorte, piezas calientes)', 'Humos y gases de soldadura',
            'Radiación (arco eléctrico)', 'Ruido', 'Vibraciones', 'Riesgo eléctrico', 'Incendio o explosión',
            'Movimiento de cargas suspendidas (puente grúa, aparejos)', 'Atropello o choque con vehículos (autoelevadores)',
            'Sobreesfuerzo / manipulación manual de cargas', 'Posturas forzadas / ergonómico', 'Contacto con sustancias químicas',
            'Espacios confinados', 'Orden y limpieza deficiente',
        ],
        'categoria' => [
            ['name' => 'Acto inseguro', 'description' => 'Acción u omisión de una persona que puede causar un accidente.'],
            ['name' => 'Condición insegura', 'description' => 'Estado del lugar, equipo o instalación que puede causar un accidente.'],
            ['name' => 'Buena práctica', 'description' => 'Conducta segura que vale la pena reconocer.'],
        ],
        'severidad' => [
            ['name' => 'Baja', 'level' => 1, 'color' => '#198754', 'description' => 'Sin lesión probable o lesión leve sin baja.'],
            ['name' => 'Media', 'level' => 2, 'color' => '#ffc107', 'description' => 'Lesión con atención médica, sin baja prolongada.'],
            ['name' => 'Alta', 'level' => 3, 'color' => '#fd7e14', 'description' => 'Lesión con baja o daño material importante.'],
            ['name' => 'Crítica', 'level' => 4, 'color' => '#dc3545', 'description' => 'Puede causar lesión grave, incapacitante o mortal.'],
        ],
        'causa' => [
            'Falta de capacitación', 'Falta de uso de EPP', 'EPP inadecuado o en mal estado', 'Falta de orden y limpieza',
            'Procedimiento inexistente o inadecuado', 'No se respetó el procedimiento', 'Equipo o herramienta defectuosa',
            'Falta de protecciones o resguardos en máquinas', 'Falta de señalización', 'Iluminación deficiente',
            'Apuro / presión por producción', 'Distracción o exceso de confianza', 'Falta de supervisión', 'Mantenimiento deficiente',
        ],
        'tipo_equipo' => [
            'Autoelevador', 'Puente grúa', 'Aparejo / tecle', 'Amoladora', 'Soldadora', 'Equipo de oxicorte', 'Prensa',
            'Plegadora', 'Guillotina', 'Torno', 'Fresadora', 'Taladro de pie', 'Sierra sin fin', 'Compresor',
            'Andamio', 'Escalera', 'Plataforma elevadora', 'Extintor', 'Tablero eléctrico',
        ],
    ],
    'positions' => [
        'Soldador', 'Operario de plegadora', 'Operario de guillotina', 'Operario de prensa', 'Tornero', 'Armador',
        'Pintor', 'Operador de autoelevador', 'Operador de puente grúa', 'Pañolero', 'Operario de mantenimiento',
        'Electricista', 'Supervisor de producción', 'Encargado de depósito', 'Responsable de calidad',
    ],
];
