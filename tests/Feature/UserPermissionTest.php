<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::SUPER_ADMIN);
    }

    /**
     * Usuario autenticado con un rol que solo tiene los permisos indicados.
     *
     * @param  list<string>  $permissions
     */
    private function actingWith(array $permissions): User
    {
        $role = Role::create(['name' => 'Rol de prueba']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_me_incluye_roles_y_permisos(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Consulta');
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('roles.0.name', 'Consulta')
            ->assertJsonPath('permissions', ['users.view']);
    }

    public function test_el_administrador_tiene_todos_los_permisos(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/me')->assertOk()->assertJsonCount(10, 'permissions');
    }

    public function test_sin_permisos_no_accede_a_usuarios(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users/export', ['all' => true])->assertForbidden();
    }

    public function test_solo_lectura_puede_listar_pero_no_crear(): void
    {
        $this->actingWith(['users.view']);

        $this->getJson('/api/users')->assertOk();
        $this->postJson('/api/users', [
            'name' => 'Nuevo',
            'email' => 'nuevo@test.com',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
        ])->assertForbidden();
    }

    public function test_filtra_usuarios_por_rol(): void
    {
        Sanctum::actingAs($this->admin);
        User::factory(2)->create()->each->assignRole('Consulta');
        User::factory()->create();
        $role = Role::findByName('Consulta');

        $this->getJson("/api/users?role={$role->id}")->assertOk()->assertJsonPath('total', 2);
    }

    public function test_asigna_roles_al_crear_y_editar(): void
    {
        Sanctum::actingAs($this->admin);
        $viewer = Role::findByName('Consulta');

        $id = $this->postJson('/api/users', [
            'name' => 'Nuevo',
            'email' => 'nuevo@test.com',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'roles' => [$viewer->id],
        ])->assertCreated()->assertJsonPath('roles.0.name', 'Consulta')->json('id');

        $this->putJson("/api/users/{$id}", ['name' => 'Nuevo', 'email' => 'nuevo@test.com', 'roles' => []])
            ->assertOk()
            ->assertJsonCount(0, 'roles');
    }

    public function test_sin_roles_assign_no_puede_enviar_roles(): void
    {
        $this->actingWith(['users.view', 'users.update']);
        $user = User::factory()->create();

        $this->putJson("/api/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [Role::findByName('Consulta')->id],
        ])->assertForbidden();

        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_solo_un_administrador_asigna_el_rol_administrador(): void
    {
        $this->actingWith(['users.view', 'users.update', 'roles.assign']);
        $user = User::factory()->create();

        $this->putJson("/api/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [Role::findByName(Role::SUPER_ADMIN)->id],
        ])->assertForbidden();

        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    public function test_no_administrador_no_edita_ni_elimina_administradores(): void
    {
        $this->actingWith(['users.view', 'users.update', 'users.delete']);

        $this->putJson("/api/users/{$this->admin->id}", [
            'name' => 'Hackeado',
            'email' => $this->admin->email,
            'password' => 'nuevaclave1',
            'password_confirmation' => 'nuevaclave1',
        ])->assertForbidden();
        $this->deleteJson("/api/users/{$this->admin->id}")->assertForbidden();

        $this->assertNotSame('Hackeado', $this->admin->fresh()->name);
    }

    public function test_el_administrador_no_puede_quitarse_su_rol(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/users/{$this->admin->id}", [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'roles' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('roles');

        $this->assertTrue($this->admin->fresh()->isSuperAdmin());
    }

    public function test_eliminacion_masiva_de_no_administrador_omite_administradores(): void
    {
        $this->actingWith(['users.view', 'users.delete']);
        $other = User::factory()->create();

        $this->postJson('/api/users/bulk-delete', ['ids' => [$this->admin->id, $other->id]])
            ->assertOk()
            ->assertJson(['deleted' => 1, 'skipped_protected' => 1]);

        $this->assertModelExists($this->admin);
        $this->assertModelMissing($other);
    }

    public function test_el_seeder_hace_administrador_al_usuario_mas_antiguo(): void
    {
        $this->admin->removeRole(Role::SUPER_ADMIN);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue($this->admin->fresh()->isSuperAdmin());
    }
}
