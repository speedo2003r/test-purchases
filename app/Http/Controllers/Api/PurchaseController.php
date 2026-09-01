<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreatePurchaseAction;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function store(Request $request, Service $service, CreatePurchaseAction $action): JsonResponse
    {
        $data = $request->validate([
            'request_key' => ['required', 'string', 'max:191'],
        ]);

        $purchase = $action->execute($request->user(), $service, $data['request_key']);

        $status = $purchase->wasRecentlyCreated ? 201 : 200;

        return response()->json($this->present($purchase), $status);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        $this->authorizeOwner($purchase);

        return response()->json($this->present($purchase->load('attempts')));
    }

    private function authorizeOwner(Purchase $purchase): void
    {
        abort_unless($purchase->user_id === request()->user()->id, 403);
    }

    private function present(Purchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'status' => $purchase->status,
            'service_id' => $purchase->service_id,
            'hold_expires_at' => $purchase->hold_expires_at?->toIso8601String(),
            'attempts' => $purchase->relationLoaded('attempts')
                ? $purchase->attempts->map(fn ($a) => [
                    'id' => $a->id,
                    'attempt_no' => $a->attempt_no,
                    'status' => $a->status,
                ])
                : null,
        ];
    }
}
