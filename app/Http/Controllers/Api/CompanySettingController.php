<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Services\Slip2goService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanySettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = CompanySetting::getAll();

        if (!empty($settings['logo'])) {
            $settings['logo_url'] = Storage::disk('public')->url($settings['logo']);
        }

        return response()->json(['settings' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:50',
            'fax' => 'nullable|string|max:50',
            'email' => 'nullable|string|email|max:255',
            'website' => 'nullable|string|max:255',
            'branch' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_branch' => 'nullable|string|max:255',
        ]);

        $keys = ['name', 'tax_id', 'address', 'phone', 'fax', 'email', 'website', 'branch', 'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch'];

        foreach ($keys as $key) {
            if ($request->has($key)) {
                CompanySetting::setValue($key, $request->input($key));
            }
        }

        $settings = CompanySetting::getAll();

        if (!empty($settings['logo'])) {
            $settings['logo_url'] = Storage::disk('public')->url($settings['logo']);
        }

        return response()->json(['settings' => $settings]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        $oldLogo = CompanySetting::getValue('logo');
        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        $path = $request->file('logo')->store('company', 'public');
        CompanySetting::setValue('logo', $path);

        return response()->json([
            'logo' => $path,
            'logo_url' => Storage::disk('public')->url($path),
        ]);
    }

    public function deleteLogo(): JsonResponse
    {
        $logo = CompanySetting::getValue('logo');
        if ($logo && Storage::disk('public')->exists($logo)) {
            Storage::disk('public')->delete($logo);
        }

        CompanySetting::setValue('logo', null);

        return response()->json(['message' => 'ลบโลโก้สำเร็จ']);
    }

    public function getSlip2go(): JsonResponse
    {
        return response()->json([
            'slip2go_api_url' => CompanySetting::getValue('slip2go_api_url', 'https://connect.slip2go.com'),
            'slip2go_secret_key' => CompanySetting::getValue('slip2go_secret_key', ''),
            'slip2go_check_duplicate' => CompanySetting::getValue('slip2go_check_duplicate', 'true'),
        ]);
    }

    public function updateSlip2go(Request $request): JsonResponse
    {
        $request->validate([
            'slip2go_api_url' => 'nullable|string|max:255',
            'slip2go_secret_key' => 'nullable|string|max:500',
            'slip2go_check_duplicate' => 'nullable|in:true,false',
        ]);

        foreach (['slip2go_api_url', 'slip2go_secret_key', 'slip2go_check_duplicate'] as $key) {
            if ($request->has($key)) {
                CompanySetting::setValue($key, $request->input($key));
            }
        }

        return response()->json([
            'slip2go_api_url' => CompanySetting::getValue('slip2go_api_url', ''),
            'slip2go_secret_key' => CompanySetting::getValue('slip2go_secret_key', ''),
            'slip2go_check_duplicate' => CompanySetting::getValue('slip2go_check_duplicate', 'true'),
        ]);
    }

    public function testSlip2go(): JsonResponse
    {
        $service = new Slip2goService();
        if (!$service->isConfigured()) {
            return response()->json(['code' => 'error', 'message' => 'Slip2Go API ยังไม่ได้ตั้งค่า'], 400);
        }

        $result = $service->getAccountInfo();
        return response()->json($result);
    }
}
