<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincroniza la tabla de permisos con PermissionCatalog y crea el rol Administrador.
 * Se puede ejecutar las veces que haga falta.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = PermissionCatalog::names();

        foreach ($names as $name) {
            Permission::findOrCreate($name);
        }

        // Los permisos que ya no están en el catálogo se eliminan (y se quitan de los roles)
        Permission::whereNotIn('name', $names)->get()->each->delete();

        $admin = Role::findOrCreate(Role::SUPER_ADMIN);
        $admin->update(['description' => 'Acceso total al sistema']);
        $admin->syncPermissions($names);

        // Rol de ejemplo: solo se crea la primera vez para no pisar cambios hechos desde la interfaz
        $viewer = Role::query()->firstOrCreate(['name' => 'Consulta'], ['description' => 'Solo lectura de usuarios']);
        if ($viewer->wasRecentlyCreated) {
            $viewer->givePermissionTo('users.view');
        }

        // Si nadie es administrador todavía (p. ej. BD existente), lo es el usuario más antiguo
        if (! User::role(Role::SUPER_ADMIN)->exists()) {
            User::oldest('id')->first()?->assignRole($admin);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
