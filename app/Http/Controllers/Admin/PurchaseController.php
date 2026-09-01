<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        $query = Purchase::query()->with([
            'user:id,name,email',
            'service:id,name,price',
            'attempts',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        } elseif ($request->filled('service')) {
            $serviceInput = $request->input('service');
            if (is_numeric($serviceInput)) {
                $query->where('service_id', (int) $serviceInput);
            } else {
                $query->whereHas('service', fn ($q) => $q->where('name', 'like', "%{$serviceInput}%"));
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        } elseif ($request->filled('user')) {
            $userInput = $request->input('user');
            if (is_numeric($userInput)) {
                $query->where('user_id', (int) $userInput);
            } else {
                $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$userInput}%")->orWhere('email', 'like', "%{$userInput}%"));
            }
        }

        $purchases = $query->latest('id')->paginate(20)->withQueryString();
        $services = Service::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.purchases.index', [
            'purchases' => $purchases,
            'services' => $services,
            'filters' => [
                'status' => $request->input('status', ''),
                'service_id' => $request->input('service_id', $request->input('service', '')),
                'user' => $request->input('user', $request->input('user_id', '')),
            ],
        ]);
    }

    public function show(Purchase $purchase): View
    {
        $purchase->load([
            'user',
            'service',
            'attempts' => fn ($q) => $q->orderBy('attempt_no', 'asc'),
            'attempts.events' => fn ($q) => $q->orderBy('occurred_at', 'asc'),
            'currentAttempt',
        ]);

        return view('admin.purchases.show', [
            'purchase' => $purchase,
        ]);
    }
}
