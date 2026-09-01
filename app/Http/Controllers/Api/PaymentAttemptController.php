<?php

namespace App\Http\Controllers\Api;

use App\Actions\StartPaymentAttemptAction;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\FakePaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentAttemptController extends Controller
{
    public function store(
        Request $request,
        Purchase $purchase,
        StartPaymentAttemptAction $action,
        FakePaymentProvider $provider,
    ): JsonResponse {
        abort_unless($purchase->user_id === $request->user()->id, 403);

        $attempt = $action->execute($purchase);

        $provider->charge($attempt);

        return response()->json([
            'attempt_id' => $attempt->id,
            'provider_reference' => $attempt->provider_reference,
            'status' => $attempt->status,
        ], 201);
    }
}
