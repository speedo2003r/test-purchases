@extends('layouts.admin')

@section('title', 'Purchases')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Filter Purchases</h2>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('admin.purchases.index') }}" class="filter-form">
            <div class="form-group">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" {{ $filters['status'] === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="confirmed" {{ $filters['status'] === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                    <option value="failed" {{ $filters['status'] === 'failed' ? 'selected' : '' }}>Failed</option>
                    <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>

            <div class="form-group">
                <label for="service_id" class="form-label">Service</label>
                <select name="service_id" id="service_id" class="form-select">
                    <option value="">All Services</option>
                    @foreach($services as $service)
                        <option value="{{ $service->id }}" {{ (string)$filters['service_id'] === (string)$service->id ? 'selected' : '' }}>
                            {{ $service->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="user" class="form-label">User (Name, Email, or ID)</label>
                <input type="text" name="user" id="user" value="{{ $filters['user'] }}" placeholder="e.g. John or user@example.com" class="form-control">
            </div>

            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="{{ route('admin.purchases.index') }}" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Purchases ({{ $purchases->total() }})</h2>
    </div>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Purchase ID</th>
                    <th>User</th>
                    <th>Service</th>
                    <th>Status</th>
                    <th>Price</th>
                    <th>Created At</th>
                    <th>Updated At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purchases as $purchase)
                    <tr id="purchase-row-{{ $purchase->id }}">
                        <td>
                            <strong>#{{ $purchase->id }}</strong>
                        </td>
                        <td>
                            <div><strong>{{ $purchase->user?->name ?? 'Unknown User' }}</strong></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $purchase->user?->email }}</div>
                        </td>
                        <td>
                            <strong>{{ $purchase->service?->name ?? 'Unknown Service' }}</strong>
                        </td>
                        <td>
                            <span class="badge badge-{{ $purchase->status }}">{{ $purchase->status }}</span>
                        </td>
                        <td>
                            @if($purchase->service?->price !== null)
                                ${{ number_format($purchase->service->price, 2) }}
                            @else
                                <span style="color: var(--text-muted);">-</span>
                            @endif
                        </td>
                        <td>
                            <div>{{ $purchase->created_at?->format('Y-m-d H:i:s') }}</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $purchase->created_at?->diffForHumans() }}</div>
                        </td>
                        <td>
                            <div>{{ $purchase->updated_at?->format('Y-m-d H:i:s') }}</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $purchase->updated_at?->diffForHumans() }}</div>
                        </td>
                        <td>
                            <a href="{{ route('admin.purchases.show', $purchase) }}" class="btn btn-secondary btn-sm">
                                View Details
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            No purchase transactions found matching the filter criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($purchases->hasPages())
        <div class="card-body" style="border-top: 1px solid var(--border); padding: 1rem 1.5rem;">
            {{ $purchases->links() }}
        </div>
    @endif
</div>
@endsection
