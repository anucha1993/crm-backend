<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slip;
use App\Services\SlipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlipController extends Controller
{
    /**
     * Slip gallery — list slips with how much has been used / remains available.
     */
    public function index(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type');

        $query = Slip::with(['payments:id,slip_id,order_id,payment_number,amount,status', 'payments.order:id,order_number', 'uploader:id,name'])
            ->where('account_type', $accountType);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('slip_ref', 'like', "%{$search}%")
                  ->orWhere('sender_name', 'like', "%{$search}%")
                  ->orWhere('sender_bank', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('verified_only')) {
            $query->where('slip_verified', true);
        }

        $slips = $query->orderByDesc('created_at')->paginate($request->input('per_page', 20));

        // Filter to slips that still have remaining balance (computed attribute).
        if ($request->boolean('available_only')) {
            $collection = $slips->getCollection()->filter(fn (Slip $s) => $s->remaining_amount > 0.009)->values();
            $slips->setCollection($collection);
        }

        return response()->json($slips);
    }

    public function show(Slip $slip, Request $request): JsonResponse
    {
        $this->ensureAccountMatch($slip, $request);
        $slip->load([
            'payments.order:id,order_number',
            'payments.customer:id,name,code',
            'uploader:id,name',
        ]);

        return response()->json(['slip' => $slip]);
    }

    /**
     * Upload a slip image into the gallery (verify + de-dup by transRef).
     */
    public function store(Request $request, SlipService $slipService): JsonResponse
    {
        $request->validate([
            'slip_image' => 'required|file|mimes:jpg,jpeg,png|max:5120',
            'amount' => 'nullable|numeric|min:0',
        ]);

        $accountType = $request->attributes->get('account_type');

        $resolved = $slipService->resolveFromUpload(
            $request->file('slip_image'),
            $accountType,
            $request->user()->id,
            [],
            $request->filled('amount') ? (float) $request->amount : null
        );

        /** @var Slip $slip */
        $slip = $resolved['slip'];
        $slip->load(['payments.order:id,order_number', 'uploader:id,name']);

        return response()->json([
            'slip' => $slip,
            'reused' => $resolved['reused'],
        ], $resolved['reused'] ? 200 : 201);
    }

    private function ensureAccountMatch(Slip $slip, Request $request): void
    {
        $accountType = $request->attributes->get('account_type');
        if ($slip->account_type !== $accountType) {
            abort(404, 'ไม่พบสลิปในบัญชีปัจจุบัน');
        }
    }
}
