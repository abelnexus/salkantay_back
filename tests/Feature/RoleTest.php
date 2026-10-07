<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::SUPER_ADMIN);
        Sanctum::actingAs($this->admin);
    }

    public function test_lista_roles_con_permisos_y_usuarios(): void
    {
        User::factory(2)->create()->each->assignRole('Consulta');

        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonPath('0.name', Role::SUPER_ADMIN)
            ->assertJsonPath('0.is_system', true)
            ->assertJsonPath('0.users_count', 1)
            ->assertJsonPath('1.name', 'Consulta')
            ->assertJsonPath('1.permissions', ['users.view'])
            ->assertJsonPath('1.users_count', 2);
    }

    public function test_devuelve_catalogo_de_permisos_agrupado(): void
    {
        $this->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonPath('0.module', 'users')
            ->assertJsonPath('0.permissions.0', ['name' => 'users.view', 'label' => 'Ver usuarios']);
    }

    public function test_crea_rol_con_permisos(): void
    {
        $this->postJson('/api/roles', [
            'name' => 'Editor',
            'description' => 'Gestiona usuarios',
            'permissions' => ['users.view', 'users.update'],
        ])->assertCreated()->assertJsonPath('permissions', ['users.update', 'users.view']);

        $this->assertTrue(Role::findByName('Editor')->hasAllPermissions(['users.view', 'users.update']));
    }

    public function test_valida_nombre_duplicado_y_permisos_inexistentes(): void
    {
        $this->postJson('/api/roles', ['name' => 'Consulta', 'permissions' => ['no.existe']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'permissions.0']);
    }

    public function test_edita_rol_y_sincroniza_permisos(): void
    {
        $role = Role::findByName('Consulta');

        $this->putJson("/api/roles/{$role->id}", [
            'name' => 'Lectura',
            'description' => null,
            'permissions' => ['roles.view'],
        ])->assertOk()->assertJsonPath('name', 'Lectura');

        $role->refresh();
        $this->assertTrue($role->hasPermissionTo('roles.view'));
        $this->assertFalse($role->hasPermissionTo('users.view'));
    }

    public function test_el_rol_administrador_no_se_edita_ni_elimina(): void
    {
        $admin = Role::findByName(Role::SUPER_ADMIN);

        $this->putJson("/api/roles/{$admin->id}", ['name' => 'Otro', 'permissions' => []])->assertForbidden();
        $this->deleteJson("/api/roles/{$admin->id}")->assertForbidden();
        $this->putJson('/api/roles/permissions', ['roles' => [['id' => $admin->id, 'permissions' => []]]])
            ->assertForbidden();

        $this->assertModelExists($admin);
    }

    public function test_elimina_rol_y_lo_quita_a_sus_usuarios(): void
    {
        $role = Role::findByName('Consulta');
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->deleteJson("/api/roles/{$role->id}")->assertOk();

        $this->assertModelMissing($role);
        $this->assertFalse($user->fresh()->hasRole('Consulta'));
    }

    public function test_guarda_la_matriz_de_permisos(): void
    {
        $viewer = Role::findByName('Consulta');
        $editor = Role::create(['name' => 'Editor']);

        $this->putJson('/api/roles/permissions', [
            'roles' => [
                ['id' => $viewer->id, 'permissions' => []],
                ['id' => $editor->id, 'permissions' => ['users.view', 'users.update']],
            ],
        ])->assertOk()->assertJsonCount(3);

        $this->assertCount(0, $viewer->fresh()->permissions);
        $this->assertTrue($editor->fresh()->hasAllPermissions(['users.view', 'users.update']));
    }

    public function test_sin_permiso_no_puede_gestionar_roles(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Consulta');
        Sanctum::actingAs($user);

        $this->getJson('/api/roles')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
        $this->postJson('/api/roles', ['name' => 'Hack', 'permissions' => []])->assertForbidden();
    }

    public function test_quien_asigna_roles_puede_listarlos_pero_no_editarlos(): void
    {
        $role = Role::create(['name' => 'Asignador']);
        $role->givePermissionTo('roles.assign');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        $this->getJson('/api/roles')->assertOk();
        $this->getJson('/api/permissions')->assertForbidden();
        $this->putJson("/api/roles/{$role->id}", ['name' => 'X', 'permissions' => []])->assertForbidden();
    }
}
