<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff user and role management (Admin / Manager only).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when($request->role, fn ($q, $v) => $q->where('role', $v))
            ->orderBy('name')
            ->paginate($this->perPage($request, 25))
            ->through(fn (User $u) => array_merge($u->toArray(), ['permissions' => $u->permissions()]));

        return $this->ok($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => ['required', Rule::in(Permissions::ROLES)],
            'phone' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
            'employee_no' => ['nullable', 'string', 'max:20', 'regex:' . \App\Services\Integration\MasterCodes::EMPLOYEE, 'unique:users,employee_no'],
        ]);

        $user = User::create($data);

        return $this->ok($user, 'User created successfully', 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'role' => ['sometimes', 'required', Rule::in(Permissions::ROLES)],
            'phone' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
            'employee_no' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:' . \App\Services\Integration\MasterCodes::EMPLOYEE, Rule::unique('users', 'employee_no')->ignore($user->id)],
        ]);

        if ($user->id === $request->user()->id && ((isset($data['is_active']) && !$data['is_active']) || (isset($data['role']) && $data['role'] !== $user->role))) {
            throw new BusinessRuleException('You cannot deactivate your own account or change your own role.');
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        if (isset($data['is_active']) && !$data['is_active']) {
            $user->tokens()->delete();
        }

        return $this->ok($user->fresh(), 'User updated successfully');
    }

    /**
     * Users are deactivated, not deleted, so audit trails keep their author.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw new BusinessRuleException('You cannot deactivate your own account.');
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deactivated successfully'
        ]);
    }
}
