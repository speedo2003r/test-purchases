# Purchase Transaction & Reservation System

A production-grade, highly reliable reservation and payment processing backend built with Laravel, designed to strictly prevent overselling under extreme concurrency, enforce idempotent payment webhook handling, and provide both a JSON API and a minimal read-only web administration dashboard.

---

## Table of Contents
1. [Core Architectural Invariants](#core-architectural-invariants)
2. [Quick Start & Setup](#quick-start--setup)
3. [End-to-End System Cycle (With Examples)](#end-to-end-system-cycle-with-examples)
   - [Step 1: Create Purchase (Reserve Spot)](#step-1-create-purchase-reserve-spot)
   - [Step 2: Start Payment Attempt](#step-2-start-payment-attempt)
   - [Step 3: Process Payment Webhook (Auto-Signed HMAC)](#step-3-process-payment-webhook-auto-signed-hmac)
   - [Step 4: Verify Purchase Status](#step-4-verify-purchase-status)
   - [Step 5: Background Hold Expiration](#step-5-background-hold-expiration)
4. [Postman Collection](#postman-collection)
5. [Admin Web Interface Walkthrough](#admin-web-interface-walkthrough)
6. [Running Automated & Concurrency Tests](#running-automated--concurrency-tests)
7. [State Machine & Architecture Summary](#state-machine--architecture-summary)

---

## Core Architectural Invariants

1. **Zero-Overselling Guarantee:** Service capacity is protected using database row-level locking (`SELECT ... FOR UPDATE`) inside transactions. Even with 50+ concurrent requests competing for the last available spot, capacity is never exceeded.
2. **Idempotency Across Layers:** 
   - Purchase creation enforces a unique `request_key` constraint.
   - Payment webhooks enforce a unique `provider_event_id` constraint.
   - Duplicate requests or webhook deliveries are safely acknowledged as no-ops without double-charging or corrupting state.
3. **Deterministic State Machine:** Purchases transition strictly through `PurchaseStateMachine` (`pending` -> `confirmed` | `failed` | `cancelled`). Once in a terminal state (`confirmed`, `cancelled`), no out-of-order or late webhook can modify the purchase state.
4. **Cryptographic Webhook Authentication:** Incoming provider webhooks require an `X-Payment-Signature` header verified using `hash_equals(HMAC-SHA256(secret, raw_payload))`.

---

## Quick Start & Setup

### Prerequisites
- PHP 8.4+ / Composer 2.x
- Docker & Docker Compose (or MySQL 8.0 instance)

### 1. Clone & Install Dependencies
```bash
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Environment Configuration
Ensure your `.env` contains the database credentials and the webhook shared secret:
```dotenv
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_PORT=8000

# Docker Sail user mapping (matches host UID/GID)
WWWUSER=1000
WWWGROUP=1000

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=mysql
FORWARD_DB_PORT=2894
DB_PORT=3306
DB_DATABASE=purchase_test
DB_USERNAME=sail
DB_PASSWORD=secret

# Webhook Verification Secret
PAYMENT_WEBHOOK_SECRET=local-dev-webhook-secret-change-in-production
```

### 3. Start Containers with Laravel Sail
```bash
./vendor/bin/sail up -d
```
*Containers started:*
- **Laravel App:** `http://localhost:8000`
- **MySQL 8.0:** `localhost:2894` (internal `3306`)
- **phpMyAdmin:** `http://localhost:1354`

### 4. Run Migrations & Seed Demo Data
```bash
./vendor/bin/sail artisan migrate --seed
```

This creates:
- **Admin User:** `admin@example.com` / `password` (`is_admin = true`)
- **Regular User:** `user@example.com` / `password` (`is_admin = false`)
- **Sample Service:** "Weekend Photography Workshop" ($299.00, 10 spots available)
- Prints **Bearer tokens** (`ADMIN_TOKEN` and `USER_TOKEN`) for API calls.

### 5. Start Background Workers
In separate terminal tabs:
```bash
# Terminal 1: Queue worker (processes payment result jobs asynchronously)
./vendor/bin/sail artisan queue:work

# Terminal 2: Scheduler (expires stale holds automatically every 30s)
./vendor/bin/sail artisan schedule:work
```

---

## End-to-End System Cycle (With Examples)

Here is the complete transaction lifecycle from initial reservation to confirmation.

```
+-----------------------------------------------------------------------------------+
| 1. Create Purchase   --> 2. Payment Attempt --> 3. Gateway Webhook --> 4. Confirm |
| (Hold Spot for 15m)     (Unique Provider Ref)   (HMAC Verified Event)  (Terminal) |
+-----------------------------------------------------------------------------------+
```

### Step 1: Create Purchase (Reserve Spot)
The customer initiates a purchase for Service ID `1`. A unique UUID `request_key` is passed to ensure idempotency.

**Request:**
```bash
curl -s -X POST http://localhost:8000/api/services/1/purchases \
  -H "Authorization: Bearer <USER_TOKEN>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "request_key": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d"
  }' | jq .
```

**Response (HTTP 201 Created):**
```json
{
  "id": 1,
  "user_id": 2,
  "service_id": 1,
  "status": "pending",
  "request_key": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "current_attempt_id": null,
  "hold_expires_at": "2026-09-01T22:15:00.000000Z",
  "created_at": "2026-09-01T22:00:00.000000Z",
  "updated_at": "2026-09-01T22:00:00.000000Z"
}
```
*Note: The spot is now temporarily reserved for 15 minutes. No other buyer can take this spot during the hold.*

---

### Step 2: Start Payment Attempt
The customer proceeds to checkout. This creates a new payment attempt associated with the purchase.

**Request:**
```bash
curl -s -X POST http://localhost:8000/api/purchases/1/payment-attempts \
  -H "Authorization: Bearer <USER_TOKEN>" \
  -H "Accept: application/json" | jq .
```

**Response (HTTP 201 Created):**
```json
{
  "id": 1,
  "purchase_id": 1,
  "attempt_no": 1,
  "provider_reference": "pay_att_8f73b61a90c2",
  "status": "pending",
  "created_at": "2026-09-01T22:01:00.000000Z",
  "updated_at": "2026-09-01T22:01:00.000000Z"
}
```

---

### Step 3: Process Payment Webhook (Auto-Signed HMAC)
When payment completes at the gateway, the provider sends a webhook to `/api/webhooks/payments`.

The payload is signed using `HMAC-SHA256`:
$$\text{Signature} = \text{HMAC-SHA256}(\text{PAYMENT\_WEBHOOK\_SECRET}, \text{raw\_body})$$

**Example Payload:**
```json
{
  "provider_event_id": "evt_998877665544",
  "provider_reference": "pay_att_8f73b61a90c2",
  "event_type": "success",
  "occurred_at": "2026-09-01T22:02:00Z",
  "raw_payload": {
    "status": "paid",
    "amount": 299.00,
    "currency": "USD"
  }
}
```

**Computing the Signature (Bash Example):**
```bash
SECRET="local-dev-webhook-secret-change-in-production"
BODY='{"provider_event_id":"evt_998877665544","provider_reference":"pay_att_8f73b61a90c2","event_type":"success","occurred_at":"2026-09-01T22:02:00Z","raw_payload":{"status":"paid","amount":299.00,"currency":"USD"}}'
SIGNATURE=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

# Send Signed Webhook
curl -s -X POST http://localhost:8000/api/webhooks/payments \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Payment-Signature: $SIGNATURE" \
  -d "$BODY" | jq .
```

**Response (HTTP 202 Accepted):**
```json
{
  "status": "acknowledged"
}
```

---

### Step 4: Verify Purchase Status
The queue worker asynchronously processes the event, runs it through `PurchaseStateMachine`, and transitions the purchase to `confirmed`.

**Customer Status Check:**
```bash
curl -s http://localhost:8000/api/purchases/1 \
  -H "Authorization: Bearer <USER_TOKEN>" \
  -H "Accept: application/json" | jq .
```

**Response (HTTP 200 OK):**
```json
{
  "id": 1,
  "user_id": 2,
  "service_id": 1,
  "status": "confirmed",
  "request_key": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "current_attempt_id": 1,
  "hold_expires_at": "2026-09-01T22:15:00.000000Z",
  "created_at": "2026-09-01T22:00:00.000000Z",
  "updated_at": "2026-09-01T22:02:05.000000Z"
}
```

---

### Step 5: Background Hold Expiration
If a customer creates a purchase but abandons it without paying, the background scheduler automatically cancels the purchase once `hold_expires_at` has passed:

```bash
./vendor/bin/sail artisan app:expire-purchase-holds
```
- Purchase status transitions from `pending` -> `cancelled`.
- The reserved spot is immediately released back to available inventory.

---

## Postman Collection

A fully configured Postman collection with automated scripts is available in the repository root:
[`Purchase_System_API.postman_collection.json`](./Purchase_System_API.postman_collection.json)

### Key Features of the Postman Collection:
1. **Automated Variable Chaining:** Creating a purchase automatically captures and sets `{{purchase_id}}` and `{{provider_reference}}`.
2. **Built-in HMAC Pre-Request Scripts:** The Webhook requests automatically calculate and attach the `X-Payment-Signature` header using CryptoJS.
3. **Pre-configured Environments:** Includes default variables for `base_url`, `user_token`, `admin_token`, `service_id`, and `webhook_secret`.

### How to Import:
1. Open **Postman** -> Click **Import** (top left).
2. Select `Purchase_System_API.postman_collection.json`.
3. Execute the requests sequentially in folder `1. Customer Flow`, `2. Payment Provider Webhook`, and `3. Admin API`.

---

## Admin Web Interface Walkthrough

A minimal, responsive read-only web administration dashboard is built using Blade templates.

### 1. Accessing the Dashboard
- **URL:** [http://localhost:8000/admin/purchases](http://localhost:8000/admin/purchases)
- **Login URL:** [http://localhost:8000/login](http://localhost:8000/login)
- **Admin Credentials:** `admin@example.com` / `password`

### 2. Features & Capabilities
1. **Purchases Index (`/admin/purchases`):**
   - Displays Purchase ID, User (Name & Email), Service Name, Status badge (`pending`, `confirmed`, `failed`, `cancelled`), Price, Created At, and Updated At.
   - **Real-Time Filters:** Filter table rows by status dropdown, service dropdown, or user search.
   - Direct link to inspect details.
2. **Transaction Detail Page (`/admin/purchases/{id}`):**
   - Detailed purchase parameters (Hold expiration status, Request Key, Price).
   - User Profile & Service Availability summary cards.
   - **Payment Attempts Table:** Displays all attempts with attempt numbers, provider references, and statuses.
   - **Payment Events Log:** Chronological table of all webhook events received, complete with timestamps and formatted raw JSON payloads.
3. **Authorization & RBAC:**
   - Non-admin users (e.g. `user@example.com`) attempting to access `/admin/*` receive an immediate **HTTP 403 Forbidden**.
   - No direct state-mutating endpoints exist in the admin UI, ensuring the state machine is never bypassed.

---

## Running Automated & Concurrency Tests

The test suite includes Unit tests, Feature tests, and real multi-process Concurrency tests using `pcntl_fork`.

### 1. Run Complete Test Suite
```bash
php artisan test
```

### 2. Test Suite Breakdown

| Test Suite | Total Tests | Description |
|---|---|---|
| **Unit** | 6 | `PurchaseStateMachine`: all valid transitions and terminal state locks |
| **Feature** | 39 | Actions, API endpoints, HMAC webhook auth, hold expiry, Admin Web UI & filters |
| **Concurrency** | 3 | Real multi-process concurrency verification |
| **Total** | **48 Tests (139 Assertions)** | **100% Passing** |

### 3. Concurrency Tests Only
```bash
php artisan test tests/Concurrency
```
- **50 Concurrent Buyers:** 50 parallel child processes attempt to buy the last 10 available spots simultaneously. Verified that exactly 10 succeed and 40 are rejected.
- **20 Duplicate Webhooks:** 20 parallel processes deliver the same payment success event. Verified that exactly one confirms the purchase and 19 are safely handled as idempotent no-ops.
- **20 Duplicate Requests:** 20 parallel processes submit the same `request_key`. Verified that exactly one purchase record is created.

---

## State Machine & Architecture Summary

```
POST /api/services/{id}/purchases
  -> PurchaseController -> CreatePurchaseAction
       -> SELECT ... FOR UPDATE on services row
       -> lazy expire stale holds
       -> count open (pending+confirmed) purchases <= total_spots
       -> INSERT purchase (unique request_key)
       -> commit & release lock

POST /api/purchases/{id}/payment-attempts
  -> PaymentAttemptController -> StartPaymentAttemptAction
       -> SELECT ... FOR UPDATE on purchase row
       -> verify pending status & hold validity
       -> INSERT payment_attempt (unique provider_reference)

POST /api/webhooks/payments
  -> verifySignature (HMAC-SHA256 constant-time comparison)
  -> PaymentWebhookController -> ProcessPaymentEventAction (queued)
       -> INSERT payment_event (unique provider_event_id)
       -> SELECT ... FOR UPDATE on purchase row
       -> verify attempt matching & hold validity
       -> PurchaseStateMachine.nextStatus() (enforce terminal state)
       -> UPDATE purchase & attempt status
```
