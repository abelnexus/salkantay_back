<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserBulkTest extends TestCase
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

    public function test_elimina_por_ids_sin_tocar_al_usuario_actual(): void
    {
        $users = User::factory(3)->create();
        $keep = User::factory()->create();
        $users->first()->createToken('web');

        $this->postJson('/api/users/bulk-delete', ['ids' => [...$users->pluck('id'), $this->admin->id]])
            ->assertOk()
            ->assertJson(['deleted' => 3, 'skipped_self' => true]);

        $this->assertModelExists($this->admin);
        $this->assertModelExists($keep);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_elimina_todos_los_del_filtro_con_excepciones(): void
    {
        $a = User::factory()->create(['name' => 'Rosa Quispe']);
        $b = User::factory()->create(['name' => 'Luis Quispe']);
        $c = User::factory()->create(['name' => 'Ana Quispe']);
        $other = User::factory()->create(['name' => 'Pedro']);

        $this->postJson('/api/users/bulk-delete', ['all' => true, 'search' => 'quispe', 'except' => [$c->id]])
            ->assertOk()
            ->assertJson(['deleted' => 2, 'skipped_self' => false]);

        $this->assertModelMissing($a);
        $this->assertModelMissing($b);
        $this->assertModelExists($c);
        $this->assertModelExists($other);
    }

    public function test_requiere_ids_si_no_es_todos(): void
    {
        $this->postJson('/api/users/bulk-delete', [])->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    public function test_marca_y_desmarca_verificado(): void
    {
        $users = User::factory(2)->unverified()->create();

        $this->postJson('/api/users/bulk-verify', ['ids' => $users->pluck('id'), 'verified' => true])
            ->assertOk()
            ->assertJson(['updated' => 2]);
        $this->assertNotNull($users->first()->fresh()->email_verified_at);

        $this->postJson('/api/users/bulk-verify', ['ids' => $users->pluck('id'), 'verified' => false])->assertOk();
        $this->assertNull($users->first()->fresh()->email_verified_at);
    }

    public function test_exporta_csv_de_la_seleccion(): void
    {
        $user = User::factory()->create(['name' => 'José Ñañez', 'email' => 'jose@test.com']);
        User::factory()->create(['email' => 'no-exportar@test.com']);

        $response = $this->postJson('/api/users/export', ['ids' => [$user->id]]);

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFID,Nombre,Email", $csv);
        $this->assertStringContainsString('"José Ñañez",jose@test.com', $csv);
        $this->assertStringNotContainsString('no-exportar@test.com', $csv);
    }

    public function test_exporta_todos_los_del_filtro(): void
    {
        User::factory(3)->create();

        $csv = $this->postJson('/api/users/export', ['all' => true])->streamedContent();

        $this->assertCount(5, array_filter(explode("\n", $csv))); // cabecera + 4 usuarios
    }
}
