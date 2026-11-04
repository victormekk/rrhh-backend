<?php

// Catálogo de permisos que se asignan con checks en "Gestión de Roles".
// Cada permiso se guarda en roles.permisos como "modulo.accion" (ej. "empleados.editar").
// El rol Administrador (roles.es_admin) tiene todos, aunque se agreguen nuevos aquí.
//
// Al agregar un permiso nuevo: protege la ruta con ->middleware('permiso:modulo.accion')
// en routes/api.php y usa authStore.puede('modulo.accion') en el frontend.

return [
    [
        'grupo'   => 'General',
        'modulos' => [
            'salarios' => [
                'nombre'      => 'Salarios',
                'descripcion' => 'Sin este permiso no se muestran salarios en Empleados, Información Laboral ni el Dashboard. Planillas y Estadística Laboral sí muestran montos, porque son su contenido.',
                'permisos'    => [
                    'ver' => 'Ver salarios',
                ],
            ],
            'dashboard' => [
                'nombre'   => 'Dashboard',
                'permisos' => [
                    'ver_montos'       => 'Ver gráfico de planillas pagadas',
                    'ver_jubilaciones' => 'Ver próximos a jubilarse (59 años o más)',
                ],
            ],
            'empleados' => [
                'nombre'   => 'Empleados',
                'permisos' => [
                    'ver'           => 'Ver módulo',
                    'ver_historial' => 'Ver historial laboral',
                    'crear'         => 'Crear',
                    'editar'        => 'Editar',
                    'baja'          => 'Dar de baja y reintegrar',
                ],
            ],
            'departamentos' => [
                'nombre'   => 'Departamentos y Cargos',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'crear'    => 'Crear',
                    'editar'   => 'Editar y desactivar',
                    'eliminar' => 'Eliminar permanentemente',
                ],
            ],
            'bancos' => [
                'nombre'   => 'Bancos',
                'permisos' => [
                    'ver'             => 'Ver módulo',
                    'crear'           => 'Crear',
                    'editar'          => 'Editar',
                    'eliminar'        => 'Desactivar',
                    'asignar_cuentas' => 'Asignar cuentas a empleados',
                ],
            ],
            'incidencias' => [
                'nombre'   => 'Incidencias',
                'permisos' => [
                    'ver'    => 'Ver módulo',
                    'crear'  => 'Crear',
                    'editar' => 'Editar',
                    'anular' => 'Anular y restaurar',
                ],
            ],
            'constancias' => [
                'nombre'   => 'Constancias',
                'permisos' => [
                    'laboral'  => 'Generar constancia laboral',
                    'bancaria' => 'Generar constancia bancaria',
                    'voucher'  => 'Generar voucher de pago',
                ],
            ],
            'vacaciones' => [
                'nombre'   => 'Vacaciones',
                'permisos' => [
                    'ver'    => 'Ver módulo',
                    'crear'  => 'Crear',
                    'editar' => 'Editar',
                    'anular' => 'Anular y restaurar',
                ],
            ],
            'cumpleanos' => [
                'nombre'   => 'Cumpleaños',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'exportar' => 'Exportar PDF',
                ],
            ],
        ],
    ],
    [
        'grupo'   => 'Planillas',
        'modulos' => [
            'planillas' => [
                'nombre'   => 'Planillas de Pago',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'crear'    => 'Crear',
                    'editar'   => 'Editar detalle y actualizar marcas',
                    'cerrar'   => 'Cerrar planilla',
                    'eliminar' => 'Eliminar',
                    'exportar' => 'Exportar PDF y Excel',
                ],
            ],
            'aguinaldo' => [
                'nombre'   => 'Planillas Especiales',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'crear'    => 'Crear',
                    'editar'   => 'Editar',
                    'cerrar'   => 'Cerrar planilla',
                    'eliminar' => 'Eliminar',
                    'exportar' => 'Exportar PDF y Excel',
                ],
            ],
            'ingresos_deducciones' => [
                'nombre'   => 'Ingresos/Deducciones',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'crear'    => 'Crear',
                    'editar'   => 'Editar y cambiar estado',
                    'eliminar' => 'Eliminar',
                ],
            ],
            'marcas' => [
                'nombre'   => 'Cargar Marcas',
                'permisos' => [
                    'ver'       => 'Ver módulo',
                    'cargar'    => 'Cargar archivo de marcas',
                    'justificar' => 'Justificar marcas incompletas',
                    'exportar'  => 'Exportar marcas incompletas',
                    'eliminar'  => 'Eliminar marcas',
                ],
            ],
        ],
    ],
    [
        'grupo'   => 'Estadística Laboral',
        'modulos' => [
            'estadistica' => [
                'nombre'   => 'Estadística Laboral',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'exportar' => 'Exportar PDF',
                ],
            ],
            'informacion_laboral' => [
                'nombre'   => 'Información Laboral',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'exportar' => 'Exportar Excel',
                ],
            ],
            'movimientos' => [
                'nombre'   => 'Movimientos de Personal',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'editar'   => 'Marcar liquidaciones',
                    'exportar' => 'Exportar Excel',
                ],
            ],
        ],
    ],
    [
        'grupo'   => 'Configuración',
        'modulos' => [
            'usuarios' => [
                'nombre'   => 'Usuarios',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'crear'    => 'Crear',
                    'editar'   => 'Editar, habilitar y deshabilitar',
                    'eliminar' => 'Eliminar',
                ],
            ],
            'roles' => [
                'nombre'   => 'Gestión de Roles',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'crear'    => 'Crear',
                    'editar'   => 'Editar',
                    'eliminar' => 'Eliminar',
                ],
            ],
            'campos_variables' => [
                'nombre'   => 'Campos Variables',
                'permisos' => [
                    'ver'    => 'Ver módulo',
                    'editar' => 'Editar',
                ],
            ],
            'bitacora' => [
                'nombre'   => 'Bitácora',
                'permisos' => [
                    'ver'      => 'Ver módulo',
                    'exportar' => 'Exportar PDF',
                ],
            ],
        ],
    ],
];
