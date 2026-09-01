<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Purchase::query()->with(['user:id,name,email', 'service:id,name,price', 'attempts']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $purchases = $query->latest()->paginate(25);

        return response()->json($purchases);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        $purchase->load(['user:id,name,email', 'service', 'attempts.events']);

        return response()->json($purchase);
    }
}
