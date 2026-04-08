<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class Slip2goService
{
    private string $apiUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->apiUrl = CompanySetting::getValue('slip2go_api_url', 'https://connect.slip2go.com');
        $this->secretKey = CompanySetting::getValue('slip2go_secret_key', '');
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    /**
     * Verify slip by image file
     */
    public function verifyByImage(UploadedFile $file, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return ['code' => 'error', 'message' => 'Slip2Go API ยังไม่ได้ตั้งค่า'];
        }

        $payload = [];

        // Check duplicate
        $checkDuplicate = CompanySetting::getValue('slip2go_check_duplicate', 'true');
        if ($checkDuplicate === 'true') {
            $payload['checkDuplicate'] = true;
        }

        // Check receiver from company bank settings
        $bankAccount = CompanySetting::getValue('bank_account_number');
        $bankAccountName = CompanySetting::getValue('bank_account_name');
        if ($bankAccount || $bankAccountName) {
            $receiver = [];
            if ($bankAccountName) {
                $receiver['accountNameTH'] = $bankAccountName;
            }
            if ($bankAccount) {
                $receiver['accountNumber'] = str_replace(['-', ' '], '', $bankAccount);
            }
            $payload['checkReceiver'] = [$receiver];
        }

        // Check amount if provided
        if (!empty($options['amount'])) {
            $payload['checkAmount'] = [
                'type' => 'gte',
                'amount' => (string) intval($options['amount']),
            ];
        }

        $httpRequest = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
        ])->attach('file', $file->getContent(), $file->getClientOriginalName());

        if (!empty($payload)) {
            $httpRequest = $httpRequest->attach('payload', json_encode($payload));
        }

        $response = $httpRequest->post($this->apiUrl . '/api/verify-slip/qr-image/info');

        return $response->json() ?? ['code' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ Slip2Go ได้'];
    }

    /**
     * Get account info (test connection)
     */
    public function getAccountInfo(): array
    {
        if (!$this->isConfigured()) {
            return ['code' => 'error', 'message' => 'Slip2Go API ยังไม่ได้ตั้งค่า'];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->get($this->apiUrl . '/api/account/info');

        return $response->json() ?? ['code' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ Slip2Go ได้'];
    }
}
