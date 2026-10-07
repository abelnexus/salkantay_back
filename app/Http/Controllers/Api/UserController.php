<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller implements HasMiddleware
{
    public const PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];

    public const SORTABLE = ['id', 'name', 'email', 'created_at'];

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:users.view', only: ['index', 'show']),
            new Middleware('permission:users.create', only: ['store']),
            new Middleware('permission:users.update', only: ['update']),
            new Middleware('permission:users.delete', only: ['destroy']),
        ];
    }

    /**
     * Lista paginada con búsqueda por nombre/email y orden por columna.
     * Ej: GET /api/users?search=ana&per_page=25&page=2&sort=name&direction=asc&role=3
     */
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'role' => ['nullable', 'integer'],
        ]);

        $users = User::query()
            ->with('roles:id,name')
            ->search($params['search'] ?? null)
            ->withRoleId(isset($params['role']) ? (int) $params['role'] : null)
            ->orderBy($params['sort'] ?? 'id', $params['direction'] ?? 'desc')
            ->paginate($params['per_page'] ?? 10)
            ->withQueryString();

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $roleIds = Arr::pull($data, 'roles');
        $this->ensureCanAssignRoles($request->user(), new User, $roleIds);

        $user = DB::transaction(function () use ($data, $roleIds) {
            $user = User::create($data);
            if ($roleIds !== null) {
                $user->syncRoles($roleIds);
            }

            return $user;
        });

        return response()->json($user->load('roles:id,name'), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('roles:id,name'));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->ensureCanManage($request->user(), $user);

        $data = $request->validated();
        $roleIds = Arr::pull($data, 'roles');
        $this->ensureCanAssignRoles($request->user(), $user, $roleIds);

        // La contraseña solo se cambia si se envía
        if (empty($data['password'])) {
            unset($data['password']);
        }

        DB::transaction(function () use ($user, $data, $roleIds) {
            $user->update($data);
            if ($roleIds !== null) {
                $user->syncRoles($roleIds);
            }
        });

        return response()->json($user->load('roles:id,name'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario.'], 403);
        }

        $this->ensureCanManage($request->user(), $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado']);
    }

    /**
     * Solo un Administrador puede editar o eliminar a otro Administrador
     * (si no, cualquiera con users.update podría cambiarle la contraseña y entrar como él).
     */
    private function ensureCanManage(User $actor, User $target): void
    {
        abort_if(
            $target->isSuperAdmin() && ! $actor->isSuperAdmin(),
            403,
            'Solo un Administrador puede modificar a otro Administrador.',
        );
    }

    /**
     * Valida el cambio de roles. `null` = el request no tocó los roles.
     *
     * @param  list<int>|null  $roleIds
     */
    private function ensureCanAssignRoles(User $actor, User $target, ?array $roleIds): void
    {
        if ($roleIds === null) {
            return;
        }

        abort_unless($actor->can('roles.assign'), 403, 'No tienes permiso para asignar roles.');

        $adminRoleId = Role::where('name', Role::SUPER_ADMIN)->value('id');
        $wasAdmin = $target->exists && $target->isSuperAdmin();
        $willBeAdmin = $adminRoleId !== null && in_array($adminRoleId, array_map(intval(...), $roleIds), true);

        if ($wasAdmin === $willBeAdmin) {
            return;
        }

        abort_unless($actor->isSuperAdmin(), 403, 'Solo un Administrador puede asignar o quitar el rol Administrador.');

        if ($target->is($actor)) {
            throw ValidationException::withMessages(['roles' => ['No puedes quitarte el rol Administrador.']]);
        }
    }
}
