@extends('layouts.admin')

@section('title', 'Purchase #' . $purchase->id)

@section('content')
<div class="breadcrumb">
    <a href="{{ route('admin.purchases.index') }}">&larr; Back to Purchases</a>
    <span>/</span>
    <span>Purchase #{{ $purchase->id }}</span>
</div>

<div class="grid-2">
    <!-- Purchase Info -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Purchase Details</h2>
            <span class="badge badge-{{ $purchase->status }}">{{ $purchase->status }}</span>
        </div>
        <div class="card-body">
            <dl class="dl-grid">
                <dt>Purchase ID</dt>
                <dd><strong>#{{ $purchase->id }}</strong></dd>

                <dt>Status</dt>
                <dd><span class="badge badge-{{ $purchase->status }}">{{ $purchase->status }}</span></dd>

                <dt>Request Key</dt>
                <dd><code>{{ $purchase->request_key }}</code></dd>

                <dt>Price</dt>
                <dd>
                    @if($purchase->service?->price !== null)
                        <strong>${{ number_format($purchase->service->price, 2) }}</strong>
                    @else
                        <span style="color: var(--text-muted);">-</span>
                    @endif
                </dd>

                <dt>Hold Expires At</dt>
                <dd>
                    @if($purchase->hold_expires_at)
                        {{ $purchase->hold_expires_at->format('Y-m-d H:i:s') }}
                        @if($purchase->isHoldExpired())
                            <span style="color: var(--danger-text); font-size: 0.75rem; font-weight: 600;">(Expired)</span>
                        @else
                            <span style="color: var(--success-text); font-size: 0.75rem; font-weight: 600;">(Active)</span>
                        @endif
                    @else
                        <span style="color: var(--text-muted);">None</span>
                    @endif
                </dd>

                <dt>Created At</dt>
                <dd>{{ $purchase->created_at?->format('Y-m-d H:i:s') }} ({{ $purchase->created_at?->diffForHumans() }})</dd>

                <dt>Updated At</dt>
                <dd>{{ $purchase->updated_at?->format('Y-m-d H:i:s') }} ({{ $purchase->updated_at?->diffForHumans() }})</dd>
            </dl>
        </div>
    </div>

    <!-- Related Entities (User & Service) -->
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h2 class="card-title">User Information</h2>
            </div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>User ID</dt>
                    <dd>#{{ $purchase->user?->id }}</dd>

                    <dt>Name</dt>
                    <dd><strong>{{ $purchase->user?->name ?? 'N/A' }}</strong></dd>

                    <dt>Email</dt>
                    <dd>{{ $purchase->user?->email ?? 'N/A' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h2 class="card-title">Service Details</h2>
            </div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>Service ID</dt>
                    <dd>#{{ $purchase->service?->id }}</dd>

                    <dt>Service Name</dt>
                    <dd><strong>{{ $purchase->service?->name ?? 'N/A' }}</strong></dd>

                    <dt>Total Spots</dt>
                    <dd>{{ $purchase->service?->total_spots ?? 'N/A' }} spots</dd>

                    <dt>Availability</dt>
                    <dd style="font-size: 0.8125rem;">
                        {{ $purchase->service?->available_from?->format('Y-m-d H:i') }} &rarr; {{ $purchase->service?->available_until?->format('Y-m-d H:i') }}
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Payment Attempts -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Payment Attempts ({{ $purchase->attempts->count() }})</h2>
    </div>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Attempt #</th>
                    <th>Provider Reference</th>
                    <th>Status</th>
                    <th>Current</th>
                    <th>Created At</th>
                    <th>Updated At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purchase->attempts as $attempt)
                    <tr>
                        <td><strong>Attempt {{ $attempt->attempt_no }}</strong></td>
                        <td><code>{{ $attempt->provider_reference }}</code></td>
                        <td><span class="badge badge-{{ $attempt->status }}">{{ $attempt->status }}</span></td>
                        <td>
                            @if($purchase->current_attempt_id === $attempt->id)
                                <span class="badge badge-confirmed">Current</span>
                            @else
                                <span style="color: var(--text-muted);">-</span>
                            @endif
                        </td>
                        <td>{{ $attempt->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $attempt->updated_at?->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">
                            No payment attempts have been initiated for this purchase.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Payment Events -->
@php
    $allEvents = $purchase->attempts->flatMap->events->sortBy('occurred_at');
@endphp
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Payment Events Log ({{ $allEvents->count() }})</h2>
    </div>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Event ID</th>
                    <th>Attempt Ref</th>
                    <th>Event Type</th>
                    <th>Occurred At</th>
                    <th>Processed At</th>
                    <th>Raw Payload</th>
                </tr>
            </thead>
            <tbody>
                @forelse($allEvents as $event)
                    <tr>
                        <td>
                            <div><strong>#{{ $event->id }}</strong></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);"><code>{{ $event->provider_event_id }}</code></div>
                        </td>
                        <td>
                            <code>{{ $event->attempt?->provider_reference ?? 'N/A' }}</code>
                        </td>
                        <td>
                            <span class="badge badge-{{ $event->event_type === 'success' ? 'confirmed' : ($event->event_type === 'failed' ? 'failed' : 'cancelled') }}">
                                {{ $event->event_type }}
                            </span>
                        </td>
                        <td>{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $event->processed_at ? $event->processed_at->format('Y-m-d H:i:s') : 'Pending' }}</td>
                        <td style="max-width: 400px;">
                            @if($event->raw_payload)
                                <pre><code>{{ json_encode($event->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                            @else
                                <span style="color: var(--text-muted);">No payload</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">
                            No payment webhook events recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
