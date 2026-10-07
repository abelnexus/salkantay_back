<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\UserController;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Selección para acciones masivas. Dos modos:
 *  - Por ids:            { "ids": [1, 2, 3] }
 *  - Todos los del filtro: { "all": true, "search": "ana", "role": 2, "except": [5] }
 */
class BulkUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
            'ids' => [Rule::requiredIf(! $this->boolean('all')), 'array', 'max:1000'],
            'ids.*' => ['integer'],
            'except' => ['sometimes', 'array'],
            'except.*' => ['integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(UserController::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Selecciona al menos un usuario.',
            'ids.max' => 'Puedes seleccionar como máximo :max usuarios a la vez.',
        ];
    }

    /**
     * Query con los usuarios seleccionados.
     *
     * @return Builder<User>
     */
    public function selectedUsers(): Builder
    {
        $data = $this->validated();

        if ($this->boolean('all')) {
            return User::query()
                ->search($data['search'] ?? null)
                ->withRoleId(isset($data['role']) ? (int) $data['role'] : null)
                ->whereNotIn('id', $data['except'] ?? []);
        }

        return User::query()->whereIn('id', $data['ids']);
    }
}
