<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserBulkController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Endpoint de prueba para verificar la conexión front <-> back
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Acciones masivas (antes del resource para que no choquen con users/{user})
    Route::post('/users/bulk-delete', [UserBulkController::class, 'destroy'])->middleware('permission:users.delete');
    Route::post('/users/bulk-verify', [UserBulkController::class, 'verify'])->middleware('permission:users.update');
    Route::post('/users/export', [UserBulkController::class, 'export'])->middleware('permission:users.export');

    Route::apiResource('users', UserController::class);

    // Roles y permisos (la matriz va antes del resource para no chocar con roles/{role})
    Route::get('/permissions', [RoleController::class, 'permissions']);
    Route::put('/roles/permissions', [RoleController::class, 'syncMatrix']);
    Route::apiResource('roles', RoleController::class);
});
