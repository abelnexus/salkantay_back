<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Acciones masivas sobre usuarios (ver BulkUserRequest para el formato de la selección).
 */
class UserBulkController extends Controller
{
    /**
     * POST /api/users/bulk-delete
     * Elimina la selección. El usuario autenticado nunca se elimina, y los
     * administradores solo los puede eliminar otro administrador.
     */
    public function destroy(BulkUserRequest $request): JsonResponse
    {
        $selected = $request->selectedUsers();
        $includesSelf = (clone $selected)->whereKey($request->user()->id)->exists();
        $skippedProtected = 0;

        if (! $request->user()->isSuperAdmin()) {
            $skippedProtected = (clone $selected)->role(Role::SUPER_ADMIN)->count();
            $selected->withoutRole(Role::SUPER_ADMIN);
        }
        $ids = $selected->whereKeyNot($request->user()->id)->pluck('id');

        PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $ids)
            ->delete();

        $deleted = User::whereKey($ids)->delete();

        return response()->json([
            'deleted' => $deleted,
            'skipped_self' => $includesSelf,
            'skipped_protected' => $skippedProtected,
        ]);
    }

    /**
     * POST /api/users/bulk-verify  { ..., "verified": true|false }
     * Marca o desmarca el email como verificado.
     */
    public function verify(BulkUserRequest $request): JsonResponse
    {
        $verified = $request->validate(['verified' => ['required', 'boolean']])['verified'];

        $updated = $request->selectedUsers()->update([
            'email_verified_at' => $verified ? now() : null,
        ]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * POST /api/users/export
     * Descarga la selección como CSV (UTF-8 con BOM para que Excel respete tildes).
     */
    public function export(BulkUserRequest $request): StreamedResponse
    {
        $query = $request->selectedUsers()->with('roles:id,name')->orderBy(
            $request->validated('sort') ?? 'id',
            $request->validated('direction') ?? 'asc',
        );

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Nombre', 'Email', 'Roles', 'Email verificado', 'Creado'], escape: '');

            foreach ($query->lazy() as $user) {
                fputcsv($out, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->roles->pluck('name')->implode(', '),
                    $user->email_verified_at?->format('Y-m-d H:i:s') ?? '',
                    $user->created_at?->format('Y-m-d H:i:s'),
                ], escape: '');
            }

            fclose($out);
        }, 'usuarios-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
