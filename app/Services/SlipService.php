<?php

namespace App\Services;

use App\Models\Slip;
use Illuminate\Http\UploadedFile;

class SlipService
{
    public function __construct(private Slip2goService $slip2go)
    {
    }

    /**
     * Verify a slip image via Slip2Go and normalise the extracted fields.
     * Returns an array with keys: raw, ref, verified, status_code, amount,
     * sender_name, sender_bank, sender_account, receiver_*, transfer_date.
     */
    public function verify(UploadedFile $file, ?float $expectedAmount = null): array
    {
        $out = [
            'raw' => null,
            'ref' => null,
            'verified' => false,
            'status_code' => null,
            'amount' => null,
            'sender_name' => null,
            'sender_bank' => null,
            'sender_account' => null,
            'receiver_name' => null,
            'receiver_bank' => null,
            'receiver_account' => null,
            'transfer_date' => null,
        ];

        if (!$this->slip2go->isConfigured()) {
            return $out;
        }

        try {
            $result = $this->slip2go->verifyByImage($file, ['amount' => $expectedAmount]);
            $out['raw'] = $result;
            $out['status_code'] = $result['code'] ?? null;

            if (isset($result['data'])) {
                $d = $result['data'];
                $out['ref'] = $d['transRef'] ?? null;
                $out['verified'] = in_array($out['status_code'], ['200000', '200200'], true);
                $out['amount'] = isset($d['amount']) ? (float) $d['amount'] : null;
                $out['transfer_date'] = $d['dateTime'] ?? null;
                $out['sender_name'] = $d['sender']['account']['name'] ?? null;
                $out['sender_bank'] = $d['sender']['bank']['name'] ?? null;
                $out['sender_account'] = $d['sender']['account']['bank']['account'] ?? null;
                $out['receiver_name'] = $d['receiver']['account']['name'] ?? null;
                $out['receiver_bank'] = $d['receiver']['bank']['name'] ?? null;
                $out['receiver_account'] = $d['receiver']['account']['bank']['account'] ?? null;
            }
        } catch (\Throwable $e) {
            $out['raw'] = ['code' => 'error', 'message' => $e->getMessage()];
            $out['status_code'] = 'error';
        }

        return $out;
    }

    /**
     * Create a slip from an uploaded image, or reuse the existing slip that has
     * the same transRef within the account (prevents duplicate slip uploads).
     *
     * @return array{slip: Slip, reused: bool}
     */
    public function resolveFromUpload(
        UploadedFile $file,
        string $accountType,
        ?int $userId,
        array $manual = [],
        ?float $expectedAmount = null
    ): array {
        $v = $this->verify($file, $expectedAmount);

        // De-duplicate by transRef within the same account.
        if (!empty($v['ref'])) {
            $existing = Slip::withoutGlobalScope('account')
                ->where('account_type', $accountType)
                ->where('slip_ref', $v['ref'])
                ->first();
            if ($existing) {
                return ['slip' => $existing, 'reused' => true];
            }
        }

        $path = $file->store('slips', 'public');

        $slip = Slip::create([
            'account_type' => $accountType,
            'slip_ref' => $v['ref'],
            'slip_image' => $path,
            'slip_verified' => $v['verified'],
            'slip_status_code' => $v['status_code'],
            'slip_data' => $v['raw'],
            'amount' => $v['amount'] ?? ($manual['amount'] ?? 0),
            'sender_name' => $v['sender_name'] ?? ($manual['sender_name'] ?? null),
            'sender_bank' => $v['sender_bank'] ?? ($manual['sender_bank'] ?? null),
            'sender_account' => $v['sender_account'] ?? ($manual['sender_account'] ?? null),
            'receiver_name' => $v['receiver_name'] ?? ($manual['receiver_name'] ?? null),
            'receiver_bank' => $v['receiver_bank'] ?? ($manual['receiver_bank'] ?? null),
            'receiver_account' => $v['receiver_account'] ?? ($manual['receiver_account'] ?? null),
            'transfer_date' => $v['transfer_date'] ?? ($manual['transfer_date'] ?? null),
            'uploaded_by' => $userId,
        ]);

        return ['slip' => $slip, 'reused' => false];
    }
}
