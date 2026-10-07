<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Admin', 'email' => 'admin@example.com']);
        $this->admin->assignRole(Role::SUPER_ADMIN);
        Sanctum::actingAs($this->admin);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', '')->getJson('/api/users')->assertUnauthorized();
    }

    public function test_lista_paginada(): void
    {
        User::factory(30)->create();

        $this->getJson('/api/users?per_page=5&page=2')
            ->assertOk()
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('total', 31)
            ->assertJsonCount(5, 'data');
    }

    public function test_rechaza_per_page_no_permitido(): void
    {
        $this->getJson('/api/users?per_page=7')->assertUnprocessable();
    }

    public function test_busca_por_nombre_o_email(): void
    {
        User::factory()->create(['name' => 'Rosa Quispe', 'email' => 'rosa@test.com']);
        User::factory()->create(['name' => 'Juan Pérez', 'email' => 'quispe.juan@test.com']);
        User::factory()->create(['name' => 'Otro', 'email' => 'otro@test.com']);

        $this->getJson('/api/users?search=quispe')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_ordena_por_columna(): void
    {
        User::factory()->create(['name' => 'Zoe']);
        User::factory()->create(['name' => 'Ana']);

        $this->getJson('/api/users?sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Admin')
            ->assertJsonPath('data.1.name', 'Ana');
    }

    public function test_crea_usuario(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Nuevo',
            'email' => 'nuevo@test.com',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
        ])->assertCreated()->assertJsonMissingPath('password');

        $user = User::where('email', 'nuevo@test.com')->firstOrFail();
        $this->assertTrue(Hash::check('secreto123', $user->password));
    }

    public function test_valida_al_crear(): void
    {
        $this->postJson('/api/users', [
            'name' => '',
            'email' => 'admin@example.com',
            'password' => '123',
            'password_confirmation' => '456',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_muestra_usuario(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/users/{$user->id}")->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_actualiza_sin_cambiar_password(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;

        $this->putJson("/api/users/{$user->id}", [
            'name' => 'Editado',
            'email' => $user->email,
            'password' => '',
        ])->assertOk()->assertJsonPath('name', 'Editado');

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_actualiza_password(): void
    {
        $user = User::factory()->create();

        $this->putJson("/api/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'nuevaclave1',
            'password_confirmation' => 'nuevaclave1',
        ])->assertOk();

        $this->assertTrue(Hash::check('nuevaclave1', $user->fresh()->password));
    }

    public function test_no_permite_email_duplicado_al_editar(): void
    {
        $user = User::factory()->create();

        $this->putJson("/api/users/{$user->id}", [
            'name' => $user->name,
            'email' => 'admin@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_elimina_usuario_y_sus_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('web');

        $this->deleteJson("/api/users/{$user->id}")->assertOk();

        $this->assertModelMissing($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_no_puede_eliminarse_a_si_mismo(): void
    {
        $this->deleteJson("/api/users/{$this->admin->id}")->assertForbidden();

        $this->assertModelExists($this->admin);
    }
}
