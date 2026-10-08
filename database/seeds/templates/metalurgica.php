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
        'lesion' => [
            'Herida cortante', 'Herida punzante', 'Contusión / golpe', 'Fractura', 'Esguince / torcedura', 'Luxación',
            'Quemadura térmica', 'Quemadura química', 'Quemadura por arco eléctrico', 'Cuerpo extraño en ojo', 'Conjuntivitis actínica',
            'Amputación', 'Aplastamiento', 'Lumbalgia / sobreesfuerzo', 'Electrocución', 'Intoxicación', 'Hipoacusia', 'Lesiones múltiples',
        ],
        'parte_cuerpo' => [
            'Cabeza', 'Cara', 'Ojos', 'Oídos', 'Cuello', 'Hombro', 'Brazo', 'Codo', 'Antebrazo', 'Muñeca', 'Mano', 'Dedos de la mano',
            'Tórax', 'Abdomen', 'Espalda / columna', 'Cadera', 'Pierna', 'Rodilla', 'Tobillo', 'Pie', 'Dedos del pie', 'Múltiples zonas',
        ],
        'forma_accidente' => [
            'Caída de personas al mismo nivel', 'Caída de personas a distinto nivel', 'Caída de objetos', 'Golpe contra objetos',
            'Golpe / corte con herramienta', 'Atrapamiento entre objetos o partes móviles', 'Proyección de partículas',
            'Contacto con superficies calientes', 'Contacto eléctrico', 'Sobreesfuerzo', 'Atropello o choque con vehículo',
            'Exposición a sustancias', 'Exposición a radiación', 'Accidente de tránsito (in itinere)',
        ],
    ],
    'positions' => [
        'Soldador', 'Operario de plegadora', 'Operario de guillotina', 'Operario de prensa', 'Tornero', 'Armador',
        'Pintor', 'Operador de autoelevador', 'Operador de puente grúa', 'Pañolero', 'Operario de mantenimiento',
        'Electricista', 'Supervisor de producción', 'Encargado de depósito', 'Responsable de calidad',
    ],
    // Checklists (Etapa 11). "*" al principio = ítem crítico (pide foto si no cumple).
    // Por defecto los ítems son Sí / No / No aplica y cumplen con "Sí"; ok_when => 'no' invierte la pregunta.
    'inspections' => [
        'autoelevador' => [
            'name' => 'Pre-uso autoelevador', 'equipment_type' => 'Autoelevador',
            'description' => 'Control antes de cada uso / inicio de turno. Si falla un ítem crítico, no usar el equipo y avisar.',
            'sections' => [
                'Seguridad' => ['*Frenos de servicio funcionan', '*Freno de mano funciona', '*Bocina funciona', '*Alarma y luz de retroceso funcionan',
                    '*Cinturón de seguridad en buen estado', 'Matafuegos a bordo, cargado y precintado'],
                'Mástil y horquillas' => ['*Horquillas sin fisuras ni deformaciones', '*Cadenas de elevación sin daños y lubricadas',
                    'Mástil sube y baja sin trabas', 'Protector de techo (guarda de cabeza) en buen estado'],
                'Estado general' => [['text' => '¿Pierde aceite, combustible o líquido hidráulico?', 'ok_when' => 'no'], 'Neumáticos en buen estado',
                    'Luces delanteras y traseras funcionan', 'Nivel de batería / combustible / gas suficiente', 'Espejos y vidrios en buen estado'],
            ],
        ],
        'puente_grua' => [
            'name' => 'Puente grúa', 'equipment_type' => 'Puente grúa',
            'description' => 'Control antes del uso. No izar cargas si falla un ítem crítico.',
            'sections' => [
                'Seguridad' => ['*Gancho con pestillo de seguridad', '*Fin de carrera de elevación funciona', '*Botonera y parada de emergencia funcionan',
                    'Señal sonora / luminosa funciona', 'Carga máxima visible en la viga'],
                'Elementos de izaje' => ['*Cable o cadena sin hilos cortados, nudos ni corrosión', 'Eslingas y grilletes en buen estado e identificados',
                    'Gancho sin deformación ni desgaste'],
                'Recorrido' => ['Recorrido libre de obstáculos', ['text' => '¿Hay ruidos o movimientos anormales?', 'ok_when' => 'no']],
            ],
        ],
        'amoladoras' => [
            'name' => 'Amoladoras', 'equipment_type' => 'Amoladora',
            'sections' => [
                'Control' => ['*Guarda de protección colocada', '*Disco adecuado a las RPM y sin fisuras', 'Mango auxiliar colocado',
                    'Cable y ficha sin daños', 'Interruptor / llave de bloqueo funciona', 'Operario con protección facial y auditiva'],
            ],
        ],
        'soldadura' => [
            'name' => 'Equipos de soldadura y oxicorte', 'equipment_type' => 'Soldadora',
            'sections' => [
                'Soldadura eléctrica' => ['*Cables y pinza sin daños en la aislación', '*Conexión a tierra correcta', 'Pantalla / máscara en buen estado'],
                'Oxicorte (si aplica)' => ['*Válvulas antirretroceso colocadas', 'Cilindros asegurados con cadena', 'Mangueras sin pérdidas ni empalmes precarios'],
                'Entorno' => ['Ventilación / extracción de humos funciona', 'Mamparas o cortinas colocadas', 'Extintor a menos de 10 m',
                    'Materiales combustibles alejados'],
            ],
        ],
        'orden_limpieza' => [
            'name' => 'Orden y limpieza (5S)', 'scope' => 'sector',
            'description' => 'Recorrida periódica del sector.',
            'sections' => [
                'Circulación' => ['Pasillos despejados y demarcados', ['text' => '¿Hay aceite, virutas o agua en el piso?', 'ok_when' => 'no'],
                    '*Salidas de emergencia libres y señalizadas'],
                'Puestos de trabajo' => ['Materiales estibados de forma segura', 'Herramientas ordenadas en su lugar', 'Residuos clasificados en sus recipientes',
                    'Señalización visible y en buen estado'],
                'Instalaciones' => ['Tableros eléctricos cerrados y señalizados', 'Iluminación suficiente'],
            ],
        ],
        'extintores' => [
            'name' => 'Extintores', 'equipment_type' => 'Extintor',
            'description' => 'Control mensual de cada extintor.',
            'sections' => [
                'Control' => ['*Manómetro en zona verde', '*Precinto y seguro colocados', 'Carga vigente (fecha de vencimiento)',
                    'Acceso libre y señalizado', 'Manguera y boquilla en buen estado', 'Tarjeta de control actualizada'],
            ],
        ],
    ],
];
