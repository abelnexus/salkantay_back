<?php

namespace App\Support;

/**
 * Catálogo de permisos del sistema, agrupados por módulo.
 *
 * Los permisos se definen aquí (no desde la interfaz) porque cada uno se usa en el código
 * para proteger rutas. Tras agregar o quitar uno, sincroniza la BD con:
 *   php artisan db:seed --class=RolesAndPermissionsSeeder
 */
final class PermissionCatalog
{
    public const MODULES = [
        'users' => [
            'label' => 'Usuarios',
            'permissions' => [
                'users.view' => 'Ver usuarios',
                'users.create' => 'Crear usuarios',
                'users.update' => 'Editar usuarios y marcar verificados',
                'users.delete' => 'Eliminar usuarios',
                'users.export' => 'Exportar usuarios a CSV',
            ],
        ],
        'roles' => [
            'label' => 'Roles y permisos',
            'permissions' => [
                'roles.view' => 'Ver roles y permisos',
                'roles.create' => 'Crear roles',
                'roles.update' => 'Editar roles y sus permisos',
                'roles.delete' => 'Eliminar roles',
                'roles.assign' => 'Asignar roles a usuarios',
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_merge(...array_map(
            fn (array $module) => array_keys($module['permissions']),
            array_values(self::MODULES),
        ));
    }

    /**
     * Catálogo en formato lista para la API.
     *
     * @return list<array{module: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function grouped(): array
    {
        return array_map(
            fn (string $module, array $data) => [
                'module' => $module,
                'label' => $data['label'],
                'permissions' => array_map(
                    fn (string $name, string $label) => ['name' => $name, 'label' => $label],
                    array_keys($data['permissions']),
                    $data['permissions'],
                ),
            ],
            array_keys(self::MODULES),
            self::MODULES,
        );
    }
}
