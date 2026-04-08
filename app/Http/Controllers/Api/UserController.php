<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with('roles');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $perPage = $request->input('per_page', 20);
        $users = $query->latest()->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'display_name' => $r->display_name,
                ]),
                'created_at' => $user->created_at,
            ];
        });

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
        ]);

        if ($request->has('role_ids')) {
            $user->roles()->sync($request->role_ids);
        }

        $user->load('roles');

        return response()->json(['user' => $this->formatUser($user)], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles');

        return response()->json(['user' => $this->formatUser($user)]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $data = $request->only('name', 'email');
        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $user->update($data);

        if ($request->has('role_ids')) {
            $user->roles()->sync($request->role_ids);
        }

        $user->load('roles');

        return response()->json(['user' => $this->formatUser($user)]);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->hasRole('admin') && User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->count() <= 1) {
            return response()->json(['message' => 'ไม่สามารถลบผู้ดูแลระบบคนสุดท้ายได้'], 403);
        }

        $user->roles()->detach();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'ลบผู้ใช้สำเร็จ']);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
            ]),
            'created_at' => $user->created_at,
        ];
    }
}
