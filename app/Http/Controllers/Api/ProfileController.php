<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
        ]);

        $user->update($request->only('name', 'email'));
        $user->load('roles.permissions');

        return response()->json([
            'message' => 'อัปเดตข้อมูลสำเร็จ',
            'user' => $this->formatUser($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
                'errors' => ['current_password' => ['รหัสผ่านปัจจุบันไม่ถูกต้อง']],
            ], 422);
        }

        $user->update(['password' => $request->password]);

        return response()->json(['message' => 'เปลี่ยนรหัสผ่านสำเร็จ']);
    }

    private function formatUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->toArray(),
            'permissions' => $user->getAllPermissions(),
        ];
    }
}
