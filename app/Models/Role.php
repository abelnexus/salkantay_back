<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property ?string $description
 */
class Role extends SpatieRole
{
    /** Rol con acceso total: no se puede editar, renombrar ni eliminar. */
    public const SUPER_ADMIN = 'Administrador';

    /**
     * Guard fijo para los roles. Sin esto, spatie usa el guard activo de la petición
     * (`sanctum` tras auth:sanctum) al crear o buscar roles, y no coincide con el de los
     * usuarios y permisos (`web`).
     */
    protected $guard_name = 'web';

    protected $hidden = ['pivot', 'guard_name'];

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }
}
