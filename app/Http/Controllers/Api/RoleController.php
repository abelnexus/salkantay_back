<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            // Quien asigna roles a usuarios necesita poder listarlos
            new Middleware('permission:roles.view|roles.assign', only: ['index']),
            new Middleware('permission:roles.view', only: ['show', 'permissions']),
            new Middleware('permission:roles.create', only: ['store']),
            new Middleware('permission:roles.update', only: ['update', 'syncMatrix']),
            new Middleware('permission:roles.delete', only: ['destroy']),
        ];
    }

    /**
     * GET /api/roles
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('id')
            ->get();

        return response()->json($roles->map($this->present(...)));
    }

    /**
     * GET /api/permissions
     * Catálogo de permisos agrupado por módulo.
     */
    public function permissions(): JsonResponse
    {
        return response()->json(PermissionCatalog::grouped());
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data) {
            $role = Role::create(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });

        return response()->json($this->present($role->loadCount('users')), 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json($this->present($role->loadCount('users')));
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $this->ensureEditable($role);
        $data = $request->validated();

        DB::transaction(function () use ($role, $data) {
            $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->syncPermissions($data['permissions']);
        });

        return response()->json($this->present($role->loadCount('users')));
    }

    /**
     * PUT /api/roles/permissions  { "roles": [{ "id": 2, "permissions": ["users.view"] }, ...] }
     * Guarda la matriz rol × permiso de una sola vez.
     */
    public function syncMatrix(Request $request): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.id' => ['required', 'integer', 'distinct', Rule::exists(Role::class, 'id')],
            'roles.*.permissions' => ['present', 'array'],
            'roles.*.permissions.*' => ['string', Rule::in(PermissionCatalog::names())],
        ]);

        $roles = Role::findMany(array_column($data['roles'], 'id'))->keyBy('id');
        $roles->each($this->ensureEditable(...));

        DB::transaction(function () use ($data, $roles) {
            foreach ($data['roles'] as $item) {
                $roles[$item['id']]->syncPermissions($item['permissions']);
            }
        });

        return $this->index();
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->ensureEditable($role);

        $role->delete();

        return response()->json(['message' => "Rol {$role->name} eliminado"]);
    }

    /**
     * El rol Administrador tiene todos los permisos siempre y no se puede tocar.
     */
    private function ensureEditable(Role $role): void
    {
        abort_if($role->isSuperAdmin(), 403, 'El rol Administrador no se puede modificar ni eliminar.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->isSuperAdmin(),
            'permissions' => $role->isSuperAdmin()
                ? PermissionCatalog::names()
                : $role->permissions->pluck('name')->sort()->values(),
            'users_count' => $role->users_count,
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }
}
