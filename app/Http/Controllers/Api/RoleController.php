<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return response()->json(['roles' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:roles,name',
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role = Role::create($request->only('name', 'display_name', 'description'));

        if ($request->has('permissions')) {
            $role->permissions()->sync($request->permissions);
        }

        $role->load('permissions');

        return response()->json(['role' => $role], 201);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        return response()->json(['role' => $role]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'display_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role->update($request->only('display_name', 'description'));

        if ($request->has('permissions')) {
            $role->permissions()->sync($request->permissions);
        }

        $role->load('permissions');

        return response()->json(['role' => $role]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->name === 'admin') {
            return response()->json(['message' => 'ไม่สามารถลบ Role admin ได้'], 403);
        }

        $role->delete();

        return response()->json(['message' => 'ลบ Role สำเร็จ']);
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::all()->groupBy('group');

        return response()->json(['permissions' => $permissions]);
    }

    public function assignRole(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $user = \App\Models\User::findOrFail($request->user_id);
        $user->roles()->sync($request->role_ids);
        $user->load('roles.permissions');

        return response()->json([
            'message' => 'กำหนด Role สำเร็จ',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions(),
            ],
        ]);
    }
}
