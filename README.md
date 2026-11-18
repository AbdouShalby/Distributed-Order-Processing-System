# Production-Inspired Distributed Order Processing Architecture Lab

> A production-inspired distributed order processing showcase demonstrating **concurrency control**, **distributed locking**, **async payment processing**, and **real-time event broadcasting** as a backend architecture lab.

[![CI Pipeline](https://github.com/AbdouShalby/Distributed-Order-Processing-System/actions/workflows/ci.yml/badge.svg)](https://github.com/AbdouShalby/Distributed-Order-Processing-System/actions)
[![codecov](https://codecov.io/gh/AbdouShalby/Distributed-Order-Processing-System/branch/main/graph/badge.svg)](https://codecov.io/gh/AbdouShalby/Distributed-Order-Processing-System)
[![Tests](https://img.shields.io/badge/tests-87_passed_(358_assertions)-brightgreen)](https://github.com/AbdouShalby/Distributed-Order-Processing-System/actions)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)

This lab focuses on transactional correctness, durable idempotency, distributed
locking, queue/worker reliability, real process concurrency, lease-based
recovery, and representative failure-window reasoning. It uses a simulated
provider, includes operational scheduler wiring, and makes no live-payment or
exactly-once claim.

## Demo video

https://github.com/user-attachments/assets/4548fc59-e90c-4a06-9f4b-838f92e5f6b0

A narrated walkthrough of about 100 seconds: the architecture, the two stock locks, durable idempotency, the payment state machine, a real k6 run, the tests and CI.

There is no hosted demo: the stack needs MySQL, Redis, queue workers and a scheduler. The video shows a real local run instead, and the steps below reproduce it.

### Verified local run (2026-11-18)

Commit `fd116be`, `grafana/k6:1.0.0`, 50 virtual users per test. These are correctness results only; latency and throughput are not reported. The full run, with environment details, is in [`load-tests/results.md`](load-tests/results.md).

| Test | Result |
|------|--------|
| Oversell: 50 buyers, product with stock 1 | 1 order created (201), 49 rejected (409), stock 1 to 0 |
| Idempotency: 50 copies of one request, same key, same moment | 1 order created (201), 49 replays of the original order (200), 0 errors, one `idempotency_requests` row |

### Reproduce it locally

Complete the [Quick Start](#quick-start) first, then from the repository root:

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec redis redis-cli FLUSHALL
docker network ls   # find <compose-project>_dops-network

docker run --rm --network <compose-project>_dops-network \
  -e BASE_URL=http://nginx \
  -v "$(pwd)/load-tests:/scripts:ro" \
  grafana/k6:1.0.0 run /scripts/oversell-test.js
```

The API is rate limited to 60 requests per minute per client, so run the two tests a minute apart or clear Redis between them. Reset, then run the idempotency test:

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec redis redis-cli FLUSHALL

docker run --rm --network <compose-project>_dops-network \
  -e BASE_URL=http://nginx \
  -v "$(pwd)/load-tests:/scripts:ro" \
  grafana/k6:1.0.0 run /scripts/idempotency-test.js
```

Expected: oversell gives 1 created and 49 rejected; idempotency gives 1 created (201), 49 replays (200) and 0 errors.

---

## Table of Contents

- [Demo video](#demo-video)
- [What This Project Proves](#what-this-project-proves)
- [What This Project Does Not Prove](#what-this-project-does-not-prove)
- [Tech Stack](#tech-stack)
- [System Architecture](#system-architecture)
- [Clean Architecture](#clean-architecture)
- [Database Schema](#database-schema)
- [Order Lifecycle & State Machine](#order-lifecycle--state-machine)
- [Distributed Locking Strategy](#distributed-locking-strategy)
- [Queue & Worker Strategy](#queue--worker-strategy)
- [Real-Time WebSocket Broadcasting](#real-time-websocket-broadcasting)
- [API Reference](#api-reference)
- [Concurrency Properties & Tested Boundaries](#concurrency-properties--tested-boundaries)
- [Security](#security)
- [Observability](#observability)
- [Load Testing Scripts & Evidence Status](#load-testing-scripts--evidence-status)
- [CI Pipeline](#ci-pipeline)
- [Environment Configuration](#environment-configuration)
- [Getting Started](#getting-started)
- [Project Structure](#project-structure)
- [Architecture Documentation](#architecture-documentation)
- [Design Decisions](#design-decisions)
- [Scaling Strategy](#scaling-strategy)
- [Future Improvements](#future-improvements)

---

## What This Project Proves

| Proven property | Repository evidence |
|-----------------|---------------------|
| Durable HTTP idempotency | A database reservation binds each key to a canonical fingerprint; HTTP tests cover `201` creation, `200` replay, `409` conflict, and a real-MySQL unique-index race |
| Atomic worker claiming | Conditional MySQL updates allow one independently scheduled worker actor to claim eligible work |
| Lease expiry recovery | Active leases resist takeover; expired `LEASED` work can be reclaimed with its stored provider identity |
| Duplicate-delivery suppression | Real Redis queue duplicates and post-success redelivery do not repeat the simulated provider's logical side effect |
| Atomic cancellation | One conditional transition restores inventory once; concurrent and rollback tests verify the boundary |
| Legacy reconciliation policy | Real-MySQL tests cover `LEASED`, `RECONCILIATION_REQUIRED`, explicit `SAFE_TO_RECOVER` approval, and no automatic ambiguous replay |
| Operational scheduler execution | An isolated runtime observes a real `schedule:work` minute tick enqueue expired work through MySQL and Redis |
| Representative hard-crash window | A worker child is terminated with `SIGKILL` after the simulated provider ledger persists; recovery reuses the same identity and side effect |
| Real infrastructure concurrency | The isolated suite uses MySQL, Redis, nginx/PHP-FPM, Redis barriers, independent PHP actors, and real queue delivery |

These are controlled, reproducible showcase tests. They establish the listed
properties at the exercised boundaries, not universal distributed-system or
payment guarantees.

## What This Project Does Not Prove

- It is not a production payment system, production-ready service, or record of
  real customer payments. The payment provider is simulated.
- It does not provide exactly-once execution, guaranteed delivery, guaranteed
  recovery, or arbitrary crash safety. Queue delivery is at least once.
- API authentication is not implemented. Broadcast authorization callbacks are
  present, but end-to-end authenticated private-channel access is not proven.
- Cache, queue, locks, sessions, and scheduler mutexes still share one
  eviction-prone Redis instance; a transactional outbox is not implemented.
- The committed k6 results ([`load-tests/results.md`](load-tests/results.md))
  are correctness results from one local run, not a performance benchmark; no
  latency or throughput figures are claimed.
- This repository contains no 30k-concurrency or production-scale proof; any
  such experience would require separate, attributable evidence.

---

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **API** | Laravel 12 / PHP 8.4 | REST API with Clean Architecture |
| **Database** | MySQL 8.0 | ACID transactions, row-level locking |
| **Shared coordination/data plane** | Redis 7 | Cache, queue, locks, sessions, and scheduler mutex |
| **WebSocket** | Laravel Reverb | Real-time order status broadcasting |
| **Reverse Proxy** | Nginx | FastCGI routing and security headers |
| **Worker** | Supervisor (2 procs) | Async job processing |
| **Scheduler** | Laravel `schedule:work` | Scheduled recovery process |
| **Containers** | Docker Compose (7 services) | Production-inspired runtime wiring |
| **Load Testing** | k6 | Concurrency & stress testing |
| **CI** | GitHub Actions (4 jobs) | Lint, Unit, Feature, Docker Build |

---

## System Architecture

```
Client ──HTTP──▶ Nginx :8000 ──FastCGI──▶ PHP-FPM API ──SQL──▶ MySQL 8
                                                │                ▲
                                                │                │
                                                ▼                │
                                  Shared Redis 7 (cache/queue/lock/
                                  session/scheduler mutex)       │
                                      │             ▲            │
                          queue jobs  ▼             │ enqueue    │ scan
                               Worker (2 procs)     Scheduler ────┘
                                      │
                                      └──broadcast──▶ Reverb :8080 ──▶ Client
```

### Request Flow

```
1. Client ──▶ POST /api/orders
2. Nginx  ──▶ PHP-FPM (OrderController)
3. Controller validates input; the use case creates a canonical SHA-256 fingerprint
4. Fast durable-request and legacy-order lookups resolve obvious replay/conflict paths
5. DB transaction inserts/locks the idempotency reservation and compares fingerprint
6. Only the reservation owner acquires sorted Redis product locks, then:
   ├── SELECT ... FOR UPDATE (products)
   ├── validate/decrement inventory and calculate the server-side total
   ├── INSERT PENDING order + items and complete the reservation
   └── COMMIT order, inventory, and idempotency result together
7. Only the creator dispatches ProcessOrderJob after commit; held Redis locks are released
8. Return 201 Created to the transaction winner; an exact durable replay returns
   200 OK and conflicting key reuse returns 409 Conflict
   ─── async ───
9. Worker picks job from the Redis queue
10. Atomically claim PENDING or an eligible expired/approved PROCESSING row
11. Persist token + lease + stable provider identity, then call the simulator
12. Token-check PAID/FAILED completion, then broadcast via Reverb
```

---

## Clean Architecture

The codebase follows **Clean Architecture** principles — the domain layer has zero framework dependencies:

```
┌──────────────────────────────────────────────────────┐
│  HTTP Layer (Controllers, Middleware, Requests)       │──── Framework (Laravel)
├──────────────────────────────────────────────────────┤
│  Application Layer (Use Cases, DTOs, EventHandlers)  │──── Orchestration
├──────────────────────────────────────────────────────┤
│  Domain Layer (Entities, VOs, Enums, Interfaces,     │──── Pure PHP (no Laravel)
│               Services)                              │
├──────────────────────────────────────────────────────┤
│  Infrastructure (Eloquent, Redis, Queue, Broadcast,  │──── Implements Domain contracts
│                  Cache, Mappers, Models)              │
└──────────────────────────────────────────────────────┘
```

| Layer | Depends On | Contains |
|-------|-----------|----------|
| **Domain** | Nothing | `Order` entity, `OrderItem` VO, `OrderStatus` enum, repository interfaces, exceptions, `InventoryService` |
| **Application** | Domain | `CreateOrderUseCase`, `ProcessOrderUseCase`, `CancelOrderUseCase`, DTOs, EventHandlers |
| **Infrastructure** | Domain + Laravel | `EloquentOrderRepository`, `RedisDistributedLock`, `SimulatedPaymentGateway`, `ProcessOrderJob`, broadcast events, `CacheService`, Mappers, Eloquent Models |
| **HTTP** | Application | `OrderController`, `CreateOrderRequest`, `TraceIdMiddleware` |

**Dependency Rule**: Dependencies point inward — infrastructure implements domain interfaces, never the reverse.

---

## Database Schema

### Entity-Relationship

| Table | Correctness-relevant fields | Role |
|---|---|---|
| `users` | `id`, `email` | Seeded showcase actors; the public API is not authenticated |
| `products` | `price DECIMAL(10,2)`, `stock UNSIGNED` | Authoritative price and inventory rows locked during order creation |
| `orders` | `status`, `total_amount`, `idempotency_key`, `request_fingerprint`, claim token/timestamps/attempt, provider key, recovery state, `cancelled_at` | Business result and worker/cancellation state machine |
| `order_items` | `order_id`, `product_id`, `quantity`, `unit_price` | Immutable price snapshot and inventory quantity |
| `idempotency_requests` | unique key, SHA-256 fingerprint, reservation status, unique nullable `order_id` | Database authority for create-order replay/conflict decisions |
| `simulated_payment_operations` | unique provider key, order/amount/status, request count, side-effect count | Durable ledger for the simulated provider only |

`users 1—N orders 1—N order_items N—1 products` is the business path.
Each completed `idempotency_requests` row maps to at most one order, and each
provider identity maps to one simulated payment operation. See the complete
Mermaid ER diagram in [`docs/architecture.md`](docs/architecture.md).

### Index Strategy

| Table | Index | Type | Purpose |
|-------|-------|------|---------|
| `orders` | `idx_orders_idempotency` | **UNIQUE** | Idempotency key dedup |
| `orders` | `idx_orders_user` | B-Tree | Filter by user |
| `orders` | `idx_orders_status` | B-Tree | Filter by status |
| `orders` | `idx_orders_recoverable_processing` | Composite B-Tree | Find expired `LEASED` work without treating ambiguous legacy rows as recoverable |
| `order_items` | `idx_order_items_order` | B-Tree | Join with orders |
| `order_items` | `idx_order_items_product` | B-Tree | Join with products |
| `products` | `idx_products_stock` | B-Tree | Stock availability queries |
| `idempotency_requests` | key unique index | **UNIQUE** | Serialize create requests for one logical key |
| `idempotency_requests` | `order_id` unique foreign key | **UNIQUE** | Bind one completed reservation to one order |
| `simulated_payment_operations` | provider key unique index | **UNIQUE** | Collapse simulated-provider retries to one logical operation |

### Money Handling

- All monetary values use `DECIMAL(10,2)` — **never** `FLOAT`
- Server-side calculation via `bcmul()` / `bcadd()` with 2 decimal precision
- `unit_price` snapshot stored in `order_items` at time of purchase (price changes don't affect past orders)
- Client-submitted totals are **ignored** — always recalculated from DB prices

---

## Order Lifecycle & State Machine

```
                    ┌───────────┐
                    │  PENDING   │
                    └─────┬─────┘
                          │
                ┌─────────┼─────────┐
                │                   │
          ┌─────▼─────┐     ┌──────▼──────┐
          │ PROCESSING │     │  CANCELLED   │
          └─────┬─────┘     └─────────────┘
                │                 ▲
          ┌─────┼─────┐           │
          │           │     (only from PENDING,
    ┌─────▼──┐  ┌────▼───┐  stock restored)
    │  PAID   │  │ FAILED  │
    └────────┘  └────────┘
```

### State Transitions

| From | To | Trigger | Side Effect |
|------|----|---------|-------------|
| `PENDING` | `PROCESSING` | Worker picks job | — |
| `PENDING` | `CANCELLED` | User cancel API | Stock restored atomically |
| `PROCESSING` | `PAID` | Payment succeeds (80%) | Broadcast `OrderPaid` |
| `PROCESSING` | `FAILED` | Payment fails (20%) | Broadcast `OrderFailed` |
| `PAID` | `REFUNDED` | Refunds reach the paid total | — |

**Terminal States**: `FAILED`, `CANCELLED`, `REFUNDED` — no further transitions allowed. `PAID` accepts refunds until fully refunded.

### State Machine Implementation

```php
enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    public function canTransitionTo(self $new): bool
    {
        return match ($this) {
            self::PENDING    => in_array($new, [self::PROCESSING, self::CANCELLED]),
            self::PROCESSING => in_array($new, [self::PAID, self::FAILED]),
            self::PAID, self::FAILED, self::CANCELLED => false,
        };
    }
}
```

---

## Distributed Locking Strategy

### Two-Layer Protection

```
Layer 1: Redis Distributed Lock (reduces avoidable contention)
    │
    ▼
Layer 2: DB SELECT ... FOR UPDATE (authoritative transaction boundary)
```

Why both? The Redis lock reduces contention and gives bounded retry behavior.
The MySQL transaction and row locks remain the inventory-correctness authority;
the Redis lock alone is not treated as a correctness guarantee.

### Lock Implementation

| Parameter | Value | Rationale |
|-----------|-------|-----------|
| **Lock key** | `inventory:product:{id}` | Per-product granularity |
| **TTL** | 10 seconds | Fixed showcase coordination budget; MySQL remains authoritative if it is outlived |
| **Token** | Random UUID | Prevents releasing another request's lock |
| **Acquire** | `SET key token NX EX 10` | Atomic set-if-not-exists |
| **Release** | Lua script (atomic) | Only delete if token matches |

### Lua Atomic Release Script

```lua
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
```

Why Lua? A plain GET + DEL is two operations — another process could acquire the lock between them. Lua executes atomically in Redis.

### Redis Circular-Wait Prevention

When an order has multiple products, locks are acquired in **ascending `product_id`** order:

```
Order: [product_id: 5, product_id: 2, product_id: 8]
Lock order: 2 → 5 → 8 (sorted ascending)
```

This removes circular wait from the Redis multi-lock acquisition path. MySQL
rows are currently locked in payload item order, so the database's deadlock
detection plus the transaction's three-attempt retry remain relevant if the
Redis TTL is outlived. If any Redis lock fails, all previously acquired Redis
locks are released immediately.

### Jittered Exponential Backoff

```
Attempt 1:   0ms (immediate)
Attempt 2: 100ms × 2⁰ × (1 ± 25% jitter) =  75 – 125ms
Attempt 3: 100ms × 2¹ × (1 ± 25% jitter) = 150 – 250ms
Attempt 4: 100ms × 2² × (1 ± 25% jitter) = 300 – 500ms
Attempt 5: 100ms × 2³ × (1 ± 25% jitter) = 600 – 1000ms
Attempt 6: 100ms × 2⁴ × (1 ± 25% jitter) = 1200 – 2000ms
────────────────────────────────────────────────
Total retry window: ~2.5 – 3.9 seconds
If still locked: return 409 Conflict
```

The ±25% random jitter prevents **thundering herd** — when many requests retry at the exact same intervals, they keep colliding.

---

## Queue & Worker Strategy

### Job Dispatch Safety

```php
ProcessOrderJob::dispatch($orderId)->afterCommit();
```

The `->afterCommit()` ensures the job is only pushed to Redis **after** the DB transaction commits. Without this, the worker might process a job for an order that doesn't exist yet (race condition).

### Worker Processing Pipeline

```
1. Atomically claim PENDING, or reclaim PROCESSING after its lease expires
2. Persist a unique claim token, lease deadline, attempt count, and stable payment key
3. Call the payment provider with that stable key
4. Complete PAID/FAILED only when the persisted claim token still matches
5. On a retryable provider error, expire the current lease and let the queue retry
6. On an ambiguous timeout, retry with the same provider key and reconcile the durable result
7. Broadcast and run completion handlers only after the token-checked DB completion succeeds
```

### Retry Configuration

| Parameter | Value | Purpose |
|-----------|-------|---------|
| `tries` | 3 | Max attempts before `failed_jobs` table |
| `backoff` | `[1, 3, 5]` seconds | Staged delay between retries |
| `max_time` | 3600 seconds | Recycle a queue worker after one hour; Supervisor restarts it |
| Supervisor procs | 2 | Parallel job processing |

### Delivery and Idempotency Semantics

The Redis queue provides **at-least-once** delivery. Database conditional updates make order claims and completions single-winner operations. A new claim creates `order-payment:v1:{orderId}` when no provider identity exists; every retry preserves and reuses the identity already stored, regardless of its historical format. A stale worker cannot finalize an order after another worker has reclaimed it. Queue delivery and provider effects remain separate operations, so correctness still depends on the payment provider honoring its idempotency contract.

The default Docker runtime has one dedicated `scheduler` service running
`php /var/www/artisan schedule:work`. Every minute it invokes
`orders:recover-expired --limit=100`; Laravel's existing five-minute overlap
mutex prevents two executions of that command from running at the same time.
The command reports its recovered/enqueued count to container stdout:

```bash
docker compose logs scheduler
```

This is production-inspired runtime wiring for a showcase, not a live payment
recovery guarantee. The default Compose file defines one scheduler and no
replicas or second scheduler mechanism.

### Payment Failure Windows (Milestone 2A.4)

The worker stores one provider identity on the order when it wins the atomic
claim. Lease-based recovery reuses that identity; it never derives a new key for
a retry. The exact behavior depends on the durable boundary reached:

| Window | Durable order state | Simulated provider operation | Retry and identity | Reconciliation |
|---|---|---|---|---|
| Crash before provider persistence | `PROCESSING` with an active `LEASED` claim until expiry | Absent | Allowed after lease expiry with the already stored provider identity | Not required for this observed simulated-provider case; a real integration must establish that the provider did not accept the call |
| Crash after provider persistence but before `PAID` | `PROCESSING` with the original claim and lease | Durable `SUCCEEDED` or `DECLINED` row | Allowed after lease expiry using the same identity; the simulated ledger returns the existing operation | Required for a real provider unless its lookup/idempotent replay contract resolves the durable outcome |
| Provider timeout with unknown outcome | `PROCESSING`; the caught exception expires the matching `LEASED` claim | May exist, but an external outcome must not be invented | The scheduler may requeue it; retry uses the same identity and the simulator's idempotent replay returns its durable result | The simulator resolves this path automatically; a real provider integration must require authoritative lookup or an equivalent idempotent-replay contract |
| Crash after `PAID` commit | Terminal `PAID` | Durable `SUCCEEDED` row | Payment retry is not allowed; duplicate delivery is skipped | Payment reconciliation is normally unnecessary, but downstream notification/handler delivery is outside this proof and has no transactional outbox |
| Retry after payment failure | A durable decline is terminal `FAILED`; a pre-acceptance transport exception leaves `PROCESSING` with an expired `LEASED` claim | `DECLINED` for a business failure; absent for the demonstrated pre-acceptance failure | Declines are not retried; a transport failure may retry with the same stored identity | The simulator can retry; a real provider path needs an explicit evidence/reconciliation policy |

The reproducible hard-crash fixture pauses a real Redis queue worker at an
explicit latch after the simulated provider ledger commits, then a separate PHP
process sends `SIGKILL`. The recovery command and real workers converge the order
to `PAID` with two provider requests, one logical provider row, and one side
effect. This proves that controlled representative window only; it is not a
claim of arbitrary crash safety or guaranteed recovery for real payment systems.

### Legacy `PROCESSING` Reconciliation (Milestone 2A.2)

This repository is a **showcase / production-inspired architecture lab**. The
legacy path models a representative failure mode: an older worker persisted
`PROCESSING`, but the historical schema did not record enough evidence to prove
whether the simulated payment provider had been called.

The schema migration adds the marker, and a separate idempotent data migration
classifies state without inventing historical payment data:

| Recovery state | Meaning | Automatic behavior |
|---|---|---|
| `LEASED` | Complete current token, timestamps, attempt, lease, and provider key exist | An expired lease may be reclaimed with the same durable payment identity; an active lease cannot be stolen |
| `RECONCILIATION_REQUIRED` | Historical or incomplete `PROCESSING`; payment outcome is unknown | Never queued or charged automatically |
| `SAFE_TO_RECOVER` | An operator explicitly recorded authoritative evidence that payment never started | The next recovery-command run may queue it; the atomic claim creates the first durable provider identity |

The operator workflow is deliberately small and explicit:

```bash
# List ambiguous historical work
php artisan orders:legacy-processing

# Only after authoritative external evidence proves payment never started
php artisan orders:allow-legacy-retry <order-id> --confirmed-no-payment

# Queue expired current leases and explicitly approved legacy work
php artisan orders:recover-expired
```

`--confirmed-no-payment` is an operator assertion, not evidence discovered by
this repository. If the historical result is paid, declined, or still unknown,
the item remains reconciliation-required and must be resolved without replaying
an ambiguous payment. Rolling the migration back removes the classification
column; it cannot reconstruct historical truth, so unresolved cases should be
exported or reconciled first.

**Showcase behavior proven by tests:** a real-MySQL backfill is idempotent,
terminal states stay untouched, ambiguous rows cannot reach the simulated
provider, active leases cannot be stolen, and an explicitly approved row can be
recovered through a real Redis queue worker with one stable provider identity.

**Production-inspired decisions:** database-authoritative conditional claims,
lease-based recovery, durable idempotency, and conservative handling of unknown
provider outcomes. **Assumptions and limitations:** the provider is simulated,
operator evidence is outside the lab, automatic reconciliation of known external
results is not implemented, and these tests do not establish fitness for live
payment operations or recovery across every possible crash boundary.

### Known Gap & Mitigation

If the app crashes between DB commit and `afterCommit()` execution, the job is never dispatched. The order stays `PENDING` forever. **Future mitigation**: Transactional Outbox pattern — write the job to a DB table in the same transaction, then a poller pushes it to Redis.

Other explicit limits in Milestone 2:

- cache, queue, and locks still share the repository's eviction-prone Redis;
  separation/no-eviction coordination belongs to Milestone 3;
- if workers remain down, repeated scheduler ticks can enqueue repeated recovery
  attempts and grow the queue; business idempotency protects side effects, not
  queue size;
- if Redis or MySQL is unavailable, the affected scheduler tick can fail and is
  visible in scheduler logs; the next tick retries after connectivity returns;
- `restart: unless-stopped` restarts an unexpectedly exited scheduler container,
  but does not guarantee recovery across every infrastructure failure;
- the simulated provider ledger demonstrates the required idempotency contract,
  but a real gateway must independently honor the stable provider key;
- API authentication and a transactional outbox are not implemented yet.

---

## Real-Time WebSocket Broadcasting

### Technology

**Laravel Reverb** — a self-hosted runtime using Laravel's official package; no
third-party WebSocket SaaS is required by this showcase.

### Channel & Events

| Event | Channel | Payload | Trigger |
|-------|---------|---------|---------|
| `OrderPaid` | `orders.{userId}` (private) | `{ order_id, total, paid_at }` | Token-checked payment completion succeeds |
| `OrderFailed` | `orders.{userId}` (private) | `{ order_id, reason, failed_at }` | Token-checked decline completion succeeds |
| `OrderCancelled` | `orders.{userId}` (private) | `{ order_id, cancelled_at }` | Conditional cancellation succeeds |

- Channel callbacks compare the authenticated user ID to `{userId}`, but this
  repository does not implement or test the API authentication flow required
  for an end-to-end private-channel security claim
- Completion broadcasts are emitted only after the token-checked order update succeeds
- Event classes implement `ShouldBroadcast`; delivery is queued and is not
  transactionally coupled to the order row because no outbox exists

### Docker Container

```yaml
reverb:
  build: ...
  command: php artisan reverb:start --host=0.0.0.0 --port=8080
  ports:
    - "8080:8080"
  depends_on:
    - redis
```

---

## API Reference

**Base URL**: `http://localhost:8000/api`

All API responses include an `X-Trace-Id` header. The middleware generates a
UUID when the request omits the header; a caller-supplied value is echoed as-is.

### Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| `POST` | `/api/orders` | Create order (idempotent) | None (showcase limitation) |
| `GET` | `/api/orders/{id}` | Get order details | None (showcase limitation) |
| `GET` | `/api/orders?user_id=&status=&page=` | List orders (paginated) | None (showcase limitation) |
| `POST` | `/api/orders/{id}/cancel` | Cancel pending order | None (showcase limitation) |
| `GET` | `/api/products` | List all products | None |
| `GET` | `/api/health` | Health check (DB + Redis) | None |

### Create Order

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "user_id": 1,
    "idempotency_key": "order-abc-123",
    "items": [
      {"product_id": 1, "quantity": 2},
      {"product_id": 3, "quantity": 1}
    ]
  }'
```

**201 Created** (first request):
```json
{
  "data": {
    "id": 1,
    "user_id": 1,
    "status": "PENDING",
    "total_amount": "2029.97",
    "idempotency_key": "order-abc-123",
    "items": [
      { "product_id": 1, "quantity": 2, "unit_price": "999.99" },
      { "product_id": 3, "quantity": 1, "unit_price": "29.99" }
    ],
    "created_at": "2026-02-23T10:30:00.000000Z"
  }
}
```

**200 OK** (duplicate `idempotency_key`): Returns the existing order — no new order created.

Both success responses include `Idempotency-Replayed: false|true` and
`meta.idempotency_replayed`. Reusing the key with a different canonical request
(`user_id` or normalized `product_id`/`quantity` items) returns **409 Conflict**
with `error_code: IDEMPOTENCY_CONFLICT`.

### Get Order

```bash
curl http://localhost:8000/api/orders/1
```

**200 OK**:
```json
{
  "data": {
    "id": 1,
    "user_id": 1,
    "status": "PAID",
    "total_amount": "2029.97",
    "idempotency_key": "order-abc-123",
    "cancelled_at": null,
    "created_at": "2026-02-23T10:30:00Z",
    "updated_at": "2026-02-23T10:30:05Z",
    "items": [
      { "product_id": 1, "quantity": 2, "unit_price": "999.99" },
      { "product_id": 3, "quantity": 1, "unit_price": "29.99" }
    ]
  }
}
```

**404 Not Found**:
```json
{
  "message": "Order not found.",
  "error_code": "NOT_FOUND"
}
```

### List Orders (Paginated)

```bash
curl "http://localhost:8000/api/orders?user_id=1&status=PAID&page=1&per_page=15"
```

**200 OK**:
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "status": "PAID",
      "total_amount": "2029.97",
      "idempotency_key": "order-abc-123",
      "cancelled_at": null,
      "created_at": "2026-02-23T10:30:00Z",
      "updated_at": "2026-02-23T10:30:05Z",
      "items": [
        { "product_id": 1, "quantity": 2, "unit_price": "999.99" },
        { "product_id": 3, "quantity": 1, "unit_price": "29.99" }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 1,
    "last_page": 1
  }
}
```

Supports filtering by `user_id` and `status`. Pagination: `per_page` default 15, max 50.

### Cancel Order

```bash
curl -X POST http://localhost:8000/api/orders/1/cancel
```

**200 OK** (PENDING → CANCELLED):
```json
{
  "data": {
    "id": 1,
    "user_id": 1,
    "status": "CANCELLED",
    "total_amount": "2029.97",
    "idempotency_key": "order-abc-123",
    "cancelled_at": "2026-02-23T10:35:00Z",
    "created_at": "2026-02-23T10:30:00Z",
    "updated_at": "2026-02-23T10:35:00Z",
    "items": [
      { "product_id": 1, "quantity": 2, "unit_price": "999.99" },
      { "product_id": 3, "quantity": 1, "unit_price": "29.99" }
    ]
  }
}
```

- Only `PENDING` orders can be cancelled
- Stock is restored atomically in a DB transaction
- Re-cancelling an already cancelled order returns `200 OK` (idempotent)

**422 Not Cancellable** (order is PROCESSING/PAID/FAILED):
```json
{
  "message": "Order with status PAID cannot be cancelled.",
  "error_code": "INVALID_TRANSITION"
}
```

### List Products

```bash
curl http://localhost:8000/api/products
```

**200 OK**:
```json
{
  "data": [
    { "id": 1, "name": "Laptop Pro", "price": "999.99", "stock": 50, "created_at": "2026-02-23T10:00:00Z" },
    { "id": 2, "name": "Wireless Mouse", "price": "29.99", "stock": 200, "created_at": "2026-02-23T10:00:00Z" },
    { "id": 3, "name": "USB-C Hub", "price": "49.99", "stock": 100, "created_at": "2026-02-23T10:00:00Z" }
  ]
}
```

### Health Check

```bash
curl http://localhost:8000/api/health
```

**200 OK**:
```json
{
  "status": "ok",
  "services": {
    "database": "connected",
    "redis": "connected"
  },
  "timestamp": "2026-02-23T10:30:00+00:00"
}
```

**503 Service Unavailable** (degraded):
```json
{
  "status": "degraded",
  "services": {
    "database": "connected",
    "redis": "disconnected"
  },
  "timestamp": "2026-02-23T10:30:00+00:00"
}
```

### Validation Rules

| Field | Rules |
|-------|-------|
| `user_id` | Required, must exist in `users` table |
| `idempotency_key` | Required, string, max 255 chars |
| `items` | Required, array, min 1 element |
| `items.*.product_id` | Required, must exist in `products` table |
| `items.*.quantity` | Required, integer, min 1 |

### Error Responses

| Status | Response field/value | When |
|--------|----------------------|------|
| `409` | `error: insufficient_stock` | Not enough stock for requested quantity |
| `409` | `error_code: LOCK_CONFLICT` | Could not acquire distributed lock after bounded retries |
| `409` | `error_code: IDEMPOTENCY_CONFLICT` | Same idempotency key, different canonical request |
| `422` | Laravel `errors` object | Invalid request payload |
| `422` | `error_code: INVALID_TRANSITION` | Order is not cancellable |
| `404` | `error_code: NOT_FOUND` | Order or resource not found |
| `429` | `error_code: RATE_LIMIT_EXCEEDED` | IP-based API limit exceeded (60 req/min) |

### Error Response Examples

**409 Insufficient Stock**:
```json
{
  "message": "Insufficient stock for product ID 5. Requested: 10, available: 3.",
  "error": "insufficient_stock"
}
```

**409 Lock Conflict**:
```json
{
  "message": "Could not acquire lock. Please retry.",
  "error_code": "LOCK_CONFLICT"
}
```

**422 Validation Error**:
```json
{
  "message": "The items field is required.",
  "errors": {
    "items": ["The items field is required."],
    "user_id": ["The user id field is required."]
  }
}
```

**429 Rate Limited**:
```json
{
  "message": "Too many requests. Please slow down.",
  "retry_after": 60
}
```

---

## Concurrency Properties & Tested Boundaries

### Overselling Prevention

```
Client A ─┐                     Client B ─┐
          │ POST /orders               │ POST /orders
          │ (product_id:1, qty:1)      │ (product_id:1, qty:1)
          │                            │
          ▼                            ▼
     Redis LOCK ✅              Redis LOCK ❌ (retry with jitter)
          │                            │
     DB: stock=1 → 0              wait 100ms ± 25ms ...
     INSERT order                  wait 200ms ± 50ms ...
     COMMIT                            │
     RELEASE LOCK                      ▼
                               Redis LOCK ✅
                                    │
                               DB: stock=0 → FAIL
                               409 Conflict
```

### Idempotency Flow

```
Request A (key="abc", fingerprint=F) ─┐
                                      ├─▶ atomic DB reservation ─▶ one order
Request B (key="abc", fingerprint=F) ─┘                         ├─▶ 201 creator
                                                                └─▶ 200 replay

Request C (key="abc", fingerprint=G) ─▶ 409 IDEMPOTENCY_CONFLICT
```

`idempotency_requests` is the database authority. Its unique key serializes
concurrent duplicates inside the protected transaction, and its SHA-256 request
fingerprint binds the key to the intended parameters. The order and completed
idempotency record commit together. The legacy unique constraint on
`orders.idempotency_key` remains a defensive fallback for external/older writers.

### Cancel Safety

```
Can cancel:    PENDING → CANCELLED (stock restored in same transaction)
Cannot cancel: PROCESSING (worker may be mid-payment)
Cannot cancel: PAID / FAILED / CANCELLED (terminal states)
Idempotent:    CANCELLED → CANCELLED returns 200 OK
```

Cancellation is claimed with one conditional database update. Only that winner
restores stock, and the status transition plus every stock increment share one
transaction. Any restoration failure rolls the entire cancellation back.

---

## Security

| Measure | Implementation |
|---------|---------------|
| **Rate Limiting** | 60 req/min per IP via Laravel `ThrottleRequests` middleware (configurable) |
| **Input Validation** | `CreateOrderRequest` validates create payloads; list filters use controller validation; numeric order routes are constrained |
| **Server-Side Pricing** | Total always calculated from DB prices — client values ignored |
| **Decimal Precision** | `DECIMAL(10,2)` + `bcmath` — zero floating-point errors |
| **Idempotency** | Durable DB reservation + request fingerprint; unique indexes are database backstops |
| **SQL Injection** | Request data is passed through Eloquent/query-builder bindings; no raw SQL is constructed from request input |
| **Mass Assignment** | All models use explicit `$fillable` whitelist |
| **Trace Propagation** | A UUID is generated when absent; a client-supplied opaque value is echoed and propagated to job/handler logs |
| **Error Masking** | No stack traces in production — generic messages with error codes; details logged server-side |
| **Security Headers** | `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection` via Nginx |

This table is not an authentication claim. Order endpoints currently trust the
caller-supplied `user_id`; authorization and ownership enforcement remain future
work.

---

## Observability

### Structured Logging

The `structured` channel writes JSON lines with consistent fields. The default
stack also writes the normal Laravel `single` log, and container stderr is not
claimed to be uniformly JSON-formatted:

```json
{
  "event": "order.created",
  "order_id": 42,
  "user_id": 1,
  "trace_id": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2026-02-23T10:30:00Z"
}
```

### Distributed Tracing

- Every request gets an `X-Trace-Id`: generated UUID when absent, otherwise the
  client-provided value
- Trace ID is carried through the create use case, queued worker job, and
  completion-handler logs
- Broadcast payloads do not currently carry the trace ID, so this is useful
  correlation rather than a complete distributed-tracing implementation

### Health Monitoring

`GET /api/health` checks:
- **Database**: MySQL connection via `DB::connection()->getPdo()`
- **Redis**: `Redis::ping()` response verification
- Returns `200 OK` or `503 Service Unavailable` (degraded)

---

## Load Testing Scripts & Evidence Status

Three k6 scripts are included. Their thresholds are executable expectations, not
current benchmark results:

| Script | Workload | Pass condition encoded by the script |
|---|---|---|
| `oversell-test.js` | 50 VUs/iterations race for seeded product 5 (`stock=1`) | one `201`, forty-nine `409` responses |
| `idempotency-test.js` | 50 VUs/iterations send one key and one payload | one `201`, no unexpected status; the summary also reports `200` replays |
| `high-load-test.js` | ramp to 50 VUs over 2.5 minutes | p95 `<500ms`, p99 `<1000ms`, HTTP failure rate `<10%`, accepted business outcomes `>80%` |

### Reproducible Command Workflow

Prerequisites are Docker with Compose, Git, and k6. No paid API or external
payment account is required. k6 installation options are documented at
[grafana.com/docs/k6](https://grafana.com/docs/k6/latest/set-up/install-k6/).
Complete the [Quick Start](#quick-start) first (or run `make setup` when GNU
Make is available), including the shared dependency-volume initialization.

```bash
# Reset before each contention scenario so product 5 is back at stock=1.
docker compose exec app php artisan migrate:fresh --seed
k6 run -e BASE_URL=http://localhost:8000 load-tests/oversell-test.js

docker compose exec app php artisan migrate:fresh --seed
k6 run -e BASE_URL=http://localhost:8000 load-tests/idempotency-test.js

docker compose exec app php artisan migrate:fresh --seed
k6 run -e BASE_URL=http://localhost:8000 \
  --out json=load-tests/high-load-current.json \
  load-tests/high-load-test.js

docker compose down
```

The scripts default to `http://localhost:80`, while this repository's Compose
runtime publishes Nginx at port `8000`; pass `BASE_URL` as shown. The JSON output
path above is evidence generated for a run and should not be committed without a
documented date, git commit, host/runtime description, exact command, and summary.

### Current Benchmark Status

[`load-tests/results.md`](load-tests/results.md) records a real local run of
`oversell-test.js` and `idempotency-test.js` on 2026-11-18 against commit
`fd116be` with `grafana/k6:1.0.0`: one order created and forty-nine rejected
for the last unit (stock 1 → 0), and one order created with forty-nine
replays of it for fifty concurrent copies of the same request (zero errors).
It is correctness evidence only; response times on that workstation are not
representative and are not reported. `high-load-test.js` was not re-run, and
earlier notes without a run date, commit or raw output were excluded. The API
is rate limited to 60 requests per minute, so run the scenarios a minute apart
or clear Redis between them. The real MySQL/Redis PHPUnit process-concurrency
suite below remains the main concurrency evidence.

---

## Testing Strategy

Unit and feature suites cover domain logic, use cases, HTTP lifecycle, recovery,
and rollback behavior. A separate isolated integration suite uses real MySQL,
real Redis, real HTTP, and independently scheduled PHP processes for contention.

### Test Pyramid

```
                ┌───────────────────┐
                │  Integration      │  21 tests
                │  (MySQL + Redis)  │  Real HTTP/process/queue races
                ├───────────────────┤
                │  Feature Tests    │  26 tests
                │  (HTTP + DB)      │  Fast lifecycle/recovery regressions
                ├───────────────────┤
                │  Application      │  23 tests
                │  Unit Tests       │  Use cases with mocked repos
                ├───────────────────┤
                │  Domain           │  38 tests (incl. data providers)
                │  Unit Tests       │  Pure logic, no framework
                └───────────────────┘
```

### Test Categories

#### 1. Domain Unit Tests (Pure Logic — No Framework)

| Test File | Tests | What It Validates |
|-----------|-------|------------------|
| `OrderStatusTest` | 3 methods (20 data-provider cases) | State machine transitions — 4 valid paths (PENDING→PROCESSING, PENDING→CANCELLED, PROCESSING→PAID, PROCESSING→FAILED), 11 invalid paths blocked, 3 terminal states verified |
| `OrderEntityTest` | 14 | Entity behavior — state transitions via `markAsProcessing/Paid/Failed/Cancelled()`, `InvalidOrderTransitionException` for illegal transitions, `isCancellable()` / `isProcessable()` guards, `calculateTotal()` with bcmath precision |
| `OrderItemTest` | 4 | Value object — constructor getters, `getLineTotal()` calculation, bcmath precision (3 × 0.10 = 0.30, no IEEE 754 float errors) |

> **Key Design**: Domain tests extend `PHPUnit\Framework\TestCase` directly — zero Laravel boot, zero I/O. They run in < 1 second.

#### 2. Application Unit Tests (Use Cases with Mocked Repositories)

| Test File | Tests | What It Validates |
|-----------|-------|------------------|
| `CreateOrderRequestFingerprintTest` | 2 | Item-order normalization and business-parameter sensitivity |
| `CreateOrderUseCaseTest` | 10 | Creator/replay/conflict paths, legacy unique-index reconciliation, locks, stock, decimal totals, and cleanup |
| `ProcessOrderUseCaseTest` | 6 | Atomic claim, decline, duplicate delivery, stale token, pre-provider failure, and ambiguous timeout identity reuse |
| `CancelOrderUseCaseTest` | 5 | Single-winner restore, repeated cancellation, non-cancellable/missing orders, and failure-side-effect suppression |

> **Key Design**: Repositories and external services are Mockery doubles. `Queue::fake()` and `Event::fake()` verify side effects without I/O.

#### 3. Feature Tests (Full HTTP Lifecycle)

| Test File | Tests | What It Validates |
|-----------|-------|------------------|
| `OrderLifecycleTest` | 13 | Complete CRUD through HTTP — POST create (201), replay (200), fingerprint conflict (409), validation/stock errors, GET show/list, cancellation, and trace propagation |
| `HealthCheckTest` | 2 | `/api/health` — 200 OK with services connected, 503 degraded when Redis is down (facade mock) |
| `ConcurrencyTest` | 3 | **Race-condition safety** — see next section |
| `AtomicCancellationRollbackTest` | 1 | Inventory failure rolls back both cancellation state and stock changes |
| `WorkerRecoveryTest` | 5 | Fast regression coverage for duplicate delivery, leases, retries, and recovery sweep |
| `SchedulerConfigurationTest` | 2 | One default scheduler, minutely recovery, overlap mutex, and stdout wiring |

#### 4. Fast Race-Safety Tests

The `ConcurrencyTest` class validates the system's **distributed locking and stock integrity** under contention:

| Test | Scenario | Assertion |
|------|----------|-----------|
| `it_prevents_overselling_under_concurrent_requests` | 10 deterministic calls for a product with stock = 1 | **1 succeeds** (201), **9 rejected** (409), final stock = **0** |
| `idempotency_key_prevents_duplicate_orders` | 5 deterministic calls with the same idempotency key | **1 created** (201), **4 return existing** (200), one stock decrement |
| `concurrent_cancels_are_idempotent` | 5 repeated cancel calls on the same order | All return **200**, one stock restoration, final status = CANCELLED |

> **Scope**: These are fast deterministic regression tests. They do not prove
> process concurrency or real Redis/MySQL behavior; that is the responsibility
> of the isolated integration suite below.

#### 5. Real MySQL/Redis Process-Concurrency Tests

`tests/Integration/ConcurrentCorrectnessTest.php` starts independent actor
processes behind Redis barriers and verifies committed MySQL state after:

- identical and conflicting concurrent HTTP idempotency requests;
- duplicate workers, live/expired leases, and stale claim tokens;
- real Redis queue duplicate delivery and crash/redelivery recovery;
- provider failure-before-persist and ambiguous timeout-after-persist retries;
- concurrent cancellation and process-vs-cancel races;
- real Redis lock contention and post-success duplicate delivery.

`tests/Integration/Milestone2A4FailureWindowTest.php` adds two narrower,
mutation-sensitive scenarios:

- an older/external application actor commits between the HTTP pre-check and
  order insert; a MySQL named-lock latch and test-only insert-attempt observer
  prove two connections reached the `orders` unique index with the same request
  fingerprint, while the HTTP loser returns a 200 replay and stock changes once;
- a real Redis queue worker is stopped with `SIGKILL` after simulated-provider
  persistence and before `PAID`; lease recovery reuses the same provider identity
  and duplicate delivery leaves one logical side effect.

Both tests emit `M2A4_ARTIFACT` JSON containing actor count, synchronization
point, order/key identity, provider identity, side-effect count, recovery result,
and final status. No credentials are included.

`IntegrationTestCase` refuses SQLite, array/sync drivers, and the in-memory lock
implementation so this suite cannot silently degrade into a fake concurrency run.

### Running Tests

Start the default showcase with the [Quick Start](#quick-start) (or
`make setup`) before running the fast commands below.

```bash
# Run the fast SQLite/array/sync suite
docker compose exec app php artisan test

# Unit tests only (fast — no database)
docker compose exec app php artisan test --testsuite=Unit

# Feature tests only (fast SQLite/in-memory infrastructure profile)
docker compose exec app php artisan test --testsuite=Feature

# With coverage report
docker compose exec app php artisan test --coverage

# Isolated MySQL + Redis + nginx + independent PHP actor processes.
# Reads APP_KEY from .env (created by the Quick Start's key:generate).
docker compose -p dops-m2-validation -f docker-compose.integration.yml up \
  --build --abort-on-container-exit --exit-code-from test

# Remove the isolated integration containers and volumes
docker compose -p dops-m2-validation -f docker-compose.integration.yml down -v

# Scheduler runtime: real minute tick, MySQL, Redis, and one real worker
make test-scheduler
# If Compose remains attached after "OK (1 test, 6 assertions)", press Ctrl+C.
make scheduler-down

# Stop the default showcase (database/Redis named volumes are retained)
docker compose down
```

### Coverage

| Metric | Value |
|--------|-------|
| **Current numeric coverage** | Not remeasured after Milestone 2A; no stale percentage is claimed |
| **Unit Test Coverage Scope** | Domain + Application layers |
| **Feature Test Coverage Scope** | HTTP controllers, middleware, jobs, event listeners |
| **CI Upload** | Unit and feature coverage are uploaded to Codecov on pushes to `main`; the badge is the external source for the latest completed CI run |

---

## CI Pipeline

GitHub Actions workflow with **4 parallel jobs**:

```
┌─────────────────┐  ┌──────────────┐  ┌───────────────┐  ┌──────────────┐
│ Lint & Config   │  │  Unit Tests  │  │ Feature Tests │  │ Docker Build │
│ Smoke           │  │              │  │               │  │              │
│ composer install│  │ Pure domain  │  │ SQLite + fake │  │ Build images │
│ Pint --test     │  │ logic tests  │  │ Redis/queue   │  │ Start + list │
│ route smoke*    │  │ (no DB)      │  │ Full HTTP     │  │ containers   │
└─────────────────┘  └──────────────┘  └───────────────┘  └──────────────┘
```

| Job | What It Validates |
|-----|------------------|
| **Lint** | Composer install and Pint style. The current `route:list` command is non-blocking because the workflow ends it with `|| true` |
| **Unit Tests** | 61 tests — domain entities, state machine, and application use cases with doubles |
| **Feature Tests** | 26 tests — fast HTTP lifecycle, recovery, idempotency, scheduler wiring, and health using the `phpunit.xml` SQLite/array/sync profile |
| **Docker Build** | Builds the Compose images, starts the services, and prints `docker compose ps`; it does not assert application health |

**Observed on 2026-08-29:** the local fast suite passed 87 tests / 358
assertions. The separate real-infrastructure profile passed 21 tests / 183
assertions, its focused Milestone 2A.4 run passed 2 tests / 40 assertions, and
the isolated scheduler runtime passed 1 test / 6 assertions. The current GitHub
Actions workflow does **not** run the real-infrastructure or scheduler suites;
use the documented Compose commands for those checks.

No PHPStan or Psalm configuration exists in this repository. Current static
gates are PHP syntax, Composer validation, route resolution, and Pint; they
should not be described as type-level static analysis.

---

## Environment Configuration

The `.env.example` file contains all configuration variables. Copy it to `.env` before first run:

```bash
cp .env.example .env
```

### Variable Reference

| Variable | Default | Description |
|----------|---------|-------------|
| **App** | | |
| `APP_NAME` | `Distributed Order Processing System` | Application name (displayed in logs) |
| `APP_ENV` | `local` | Environment: `local`, `production`, `testing` |
| `APP_KEY` | *(empty)* | Generate a local key explicitly with `php artisan key:generate` |
| `APP_DEBUG` | `true` | Enable debug mode — **must be `false` in production** |
| `APP_URL` | `http://localhost:8000` | Base URL for route generation |
| **Database** | | |
| `DB_CONNECTION` | `mysql` | Database driver |
| `DB_HOST` | `mysql` | Database hostname — `mysql` is the Docker service name |
| `DB_PORT` | `3306` | MySQL port |
| `DB_DATABASE` | `dops` | Database name (created by Docker automatically) |
| `DB_USERNAME` | `dops_user` | Database user |
| `DB_PASSWORD` | `dops_password` | Database password |
| **Redis** | | |
| `REDIS_HOST` | `redis` | Redis hostname — `redis` is the Docker service name |
| `REDIS_PORT` | `6379` | Redis port |
| `REDIS_PASSWORD` | `null` | Redis password (no auth in development) |
| **Queue & Broadcast** | | |
| `QUEUE_CONNECTION` | `redis` | Job queue driver: `redis` in the default showcase or `sync` in the fast test profile |
| `BROADCAST_CONNECTION` | `reverb` | WebSocket driver |
| `CACHE_STORE` | `redis` | Cache backend |
| **Reverb (WebSocket)** | | |
| `REVERB_APP_ID` | `dops-local` | Synthetic local Reverb application ID |
| `REVERB_APP_KEY` | `dops-local-key` | Synthetic local Reverb public key |
| `REVERB_APP_SECRET` | `dops-local-secret` | Synthetic local Reverb secret placeholder |
| `REVERB_HOST` | `reverb` | Internal Docker service host used by Laravel publishers |
| `REVERB_PORT` | `8080` | Reverb server port |
| `VITE_REVERB_HOST` | `localhost` | Browser-facing host for local WebSocket clients |

### Configuration and Secret Hygiene

- `APP_KEY` is empty. The `dops-local*` Reverb values are synthetic placeholders;
  generate unique values for any non-disposable environment.
- Database passwords in Compose and `.env.example` are explicit local/integration
  test defaults, not deployment credentials.
- `.env` and `.env.*` are ignored while `.env.example` remains tracked. Do not
  place a real token or credential in Compose, test fixtures, or documentation.
- The CI workflow references `CODECOV_TOKEN` through GitHub Secrets and generates
  a temporary Laravel key; no credential value is embedded in the workflow.

---

## Getting Started

### Prerequisites

- Docker & Docker Compose
- Git
- GNU Make is optional; every Make target is shown as its underlying Docker command
- k6 is required only for the load scripts, not for application or correctness tests

### Quick Start

```bash
# Clone
git clone https://github.com/AbdouShalby/distributed-order-processing-system.git
cd distributed-order-processing-system

# Copy environment file
cp .env.example .env
# PowerShell equivalent: Copy-Item .env.example .env

# Build the PHP image, initialize the shared dependency volume,
# install Composer dependencies, then start all 7 services
docker compose build
docker compose run --rm --no-deps --user 0 app chown 1000:1000 /var/www/vendor
docker compose run --rm --no-deps app composer install --no-interaction
docker compose up -d --wait

# Generate the local Laravel key
docker compose exec app php artisan key:generate

# Run migrations & seed sample data
docker compose exec app php artisan migrate:fresh --seed

# Verify everything works
curl http://localhost:8000/api/health
# → {"status":"ok","services":{"database":"connected","redis":"connected"}}

curl http://localhost:8000/api/products
# → 5 seeded products; product 5 has stock=1 for contention demonstrations

# Create your first order
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id":1,"idempotency_key":"my-first-order","items":[{"product_id":1,"quantity":1}]}'
# → 201 Created, status: PENDING

# Poll order status; the worker processes it asynchronously
curl http://localhost:8000/api/orders/1
# → initially PENDING/PROCESSING, eventually PAID or FAILED in the simulator

# Run all tests
docker compose exec app php artisan test
# → 87 passed (358 assertions) in the current local suite

# Stop everything
docker compose down
```

### Makefile Shortcuts

```bash
make setup    # build + shared dependency volume + up + key + migrate + seed
make test     # run all tests
make test-integration # isolated real MySQL/Redis process-concurrency suite
make integration-down # remove only that integration project's runtime
make logs-scheduler   # follow scheduled recovery output
make test-scheduler   # real MySQL/Redis execution; minute-tick wait up to 75s
make scheduler-down   # remove only the isolated scheduler test runtime
make down     # stop all containers
```

### Docker Services

| Service | Image | Port | Purpose |
|---------|-------|------|---------|
| `app` | PHP 8.4-FPM Alpine | — | API (via Nginx) |
| `nginx` | Nginx Alpine | `8000:80` | Reverse proxy |
| `mysql` | MySQL 8.0 | `33061:3306` | Database |
| `redis` | Redis 7 Alpine | `63790:6379` | Lock + Queue + Cache |
| `worker` | PHP 8.4-FPM + Supervisor | — | 2 queue workers |
| `scheduler` | PHP 8.4-FPM Alpine | — | Runs the minutely scheduled recovery command |
| `reverb` | PHP 8.4-FPM | `8080:8080` | WebSocket server |

The four PHP services mount one Compose-managed `vendor-data` volume. This keeps
dependencies readable by their non-root user on Linux, macOS, and Docker Desktop
without requiring a host PHP/Composer installation. `make setup` initializes the
volume ownership before Composer writes it. Use `docker compose down -v` only
when you intentionally want to remove database, Redis, and dependency volumes.

The worker consumes Redis jobs; the scheduler only discovers and enqueues
recoverable work. They are separate processes so worker outages do not stop the
recovery scan. During a prolonged worker outage, repeated scans may add duplicate
jobs and grow the backlog. Atomic claims and durable provider idempotency protect
business side effects when those jobs are eventually consumed, not queue growth.

The reproducible scheduler verification uses an isolated Compose project with
real MySQL and Redis. It leaves the worker stopped while an expired lease is
discovered on a real minute tick, verifies no simulated-payment effect occurred,
then starts one real queue-worker process and verifies one side effect with the
pre-existing provider idempotency identity. Combined scheduler/test logs are
printed by `make test-scheduler`; `make scheduler-down` removes that project's
containers, network, and volumes.

Observed in the isolated runtime:

- with no persistent worker service, the scheduler enqueued one expired lease
  and no simulated-payment operation existed before the test started its worker;
- with Redis stopped, the overlap-mutex lookup failed visibly and
  `schedule:run` exited non-zero; after Redis restarted it returned healthy;
- with MySQL stopped, the recovery child exited non-zero and Laravel logged the
  scheduled-command failure; the long-running scheduler stayed alive, so logs
  are the failure signal rather than container liveness alone;
- an administrative Docker/Compose stop suppresses restart, while
  `restart: unless-stopped` configures restart for an unexpected process exit.
  The runtime test verifies the resolved policy but does not inject an arbitrary
  PID 1 crash.

---

## Project Structure

```
.
├── app/
│   ├── Domain/                              # Pure business logic (zero Laravel deps)
│   │   ├── Order/
│   │   │   ├── Entities/Order.php           # Order aggregate root
│   │   │   ├── Enums/OrderStatus.php        # State machine with canTransitionTo()
│   │   │   ├── Contracts/                   # Order + idempotency repository interfaces
│   │   │   ├── ValueObjects/                # OrderItem, reservation/record/claim
│   │   │   └── Exceptions/                  # Lifecycle + idempotency conflict
│   │   ├── Inventory/
│   │   │   ├── Contracts/                   # ProductRepositoryInterface, DistributedLockInterface
│   │   │   ├── Exceptions/                  # InsufficientStock, LockAcquisition
│   │   │   └── Services/
│   │   │       └── InventoryService.php     # Stock validation, decrement, restore (pure domain logic)
│   │   └── Payment/
│   │       ├── Contracts/PaymentGatewayInterface.php
│   │       └── ValueObjects/PaymentResult.php
│   │
│   ├── Application/                         # Use cases orchestrating domain logic
│   │   ├── UseCases/
│   │   │   ├── CreateOrder/                 # Lock → validate → decrement → save → dispatch
│   │   │   ├── ProcessOrder/                # Claim/lease/recovery/reconciliation use cases
│   │   │   └── CancelOrder/                 # Conditional claim → restore → broadcast
│   │   ├── Idempotency/                     # Canonical create-request fingerprint
│   │   ├── DTOs/                            # Create DTO/result/response
│   │   └── EventHandlers/                   # Domain event handlers (structured logging + hooks)
│   │       ├── OrderCreatedHandler.php      # Fires after order creation
│   │       ├── OrderPaidHandler.php         # Fires after payment success
│   │       ├── OrderFailedHandler.php       # Fires after payment failure
│   │       └── OrderCancelledHandler.php    # Fires after order cancellation
│   │
│   ├── Infrastructure/                      # Framework implementations
│   │   ├── Locking/
│   │   │   ├── RedisDistributedLock.php     # SET NX EX + Lua release + jittered backoff
│   │   │   └── InMemoryDistributedLock.php  # Test double (no Redis needed in tests)
│   │   ├── Persistence/
│   │   │   ├── Repositories/
│   │   │   │   ├── EloquentOrderRepository.php
│   │   │   │   ├── EloquentProductRepository.php
│   │   │   │   └── EloquentIdempotencyRepository.php
│   │   │   └── Mappers/
│   │   │       ├── OrderMapper.php          # Eloquent ↔ Domain entity mapping
│   │   │       └── ProductMapper.php        # Eloquent ↔ Domain array mapping
│   │   ├── Cache/
│   │   │   └── CacheService.php             # Prefixed cache with TTL constants + domain key generators
│   │   ├── PaymentGateway/
│   │   │   └── SimulatedPaymentGateway.php  # 80/20 success/fail, 50-200ms delay
│   │   ├── Queue/Jobs/
│   │   │   └── ProcessOrderJob.php          # Queued job with 3 retries, [1,3,5]s backoff
│   │   └── Broadcasting/Events/
│   │       ├── OrderPaidEvent.php           # ShouldBroadcast → private orders.{userId}
│   │       ├── OrderFailedEvent.php
│   │       └── OrderCancelledEvent.php
│   │
│   ├── Models/                              # Eloquent models (mass-assignment protected)
│   │   ├── User.php
│   │   ├── Product.php                      # $fillable, casts, scopes, relationships
│   │   ├── Order.php                        # OrderStatus enum cast, scopes, relationships
│   │   ├── OrderItem.php                    # Line total accessor, relationships
│   │   └── SimulatedPaymentOperation.php    # Simulator idempotency ledger
│   │
│   ├── Http/                                # Thin controllers (no business logic)
│   │   ├── Controllers/
│   │   │   ├── OrderController.php          # CRUD + explicit create/replay/conflict HTTP status
│   │   │   ├── ProductController.php
│   │   │   └── HealthController.php         # DB + Redis health probes
│   │   ├── Requests/CreateOrderRequest.php  # Validation rules
│   │   └── Middleware/TraceIdMiddleware.php  # X-Trace-Id propagation
│   │
│   └── Providers/
│       └── DomainServiceProvider.php        # Interface → Implementation bindings
│
├── database/
│   ├── migrations/                          # 11 migrations, including 2A correctness metadata
│   └── seeders/DatabaseSeeder.php           # 5 products + 3 showcase users
│
├── tests/
│   ├── Unit/
│   │   ├── Application/                    # Use case tests with mocked dependencies
│   │   │   ├── CreateOrderUseCaseTest.php   # 10 tests
│   │   │   ├── ProcessOrderUseCaseTest.php  # 6 tests
│   │   │   ├── CancelOrderUseCaseTest.php   # 5 tests
│   │   │   └── CreateOrderRequestFingerprintTest.php # 2 tests
│   │   └── Domain/Order/
│   │       ├── OrderEntityTest.php           # 14 tests — transitions, totals, precision
│   │       ├── OrderItemTest.php             # 4 tests — immutability, line totals
│   │       └── OrderStatusTest.php           # 20 tests — valid/invalid transitions, terminal
│   ├── Feature/                             # 26 fast SQLite/fake-infrastructure tests
│   └── Integration/                         # 21 real MySQL/Redis/process tests + scheduler group
│
├── docker/
│   ├── php/Dockerfile                       # PHP 8.4-FPM Alpine + extensions + Supervisor
│   ├── nginx/default.conf                   # Reverse proxy + security headers
│   └── supervisor/worker.conf               # 2 worker processes, auto-restart
│
├── load-tests/                              # k6 scripts
│   ├── oversell-test.js                     # 50 VUs → stock=1 → exactly 1 wins
│   ├── idempotency-test.js                  # 50 VUs → same key → exactly 1 created
│   └── high-load-test.js                    # Ramp to 50 VUs → p95 < 500ms
│
├── .github/workflows/ci.yml                # 4-job CI pipeline
├── docker-compose.yml                       # 7 services, including one scheduler
├── docs/
│   └── architecture.md                      # Mermaid diagrams and explicit boundaries
├── Makefile                                 # setup, test, down shortcuts
└── README.md                                # You are here
```

---

## Architecture Documentation

Detailed Mermaid diagrams are available in [`docs/architecture.md`](docs/architecture.md):

| Diagram | What It Shows |
|---------|--------------|
| **System Architecture Overview** | All 7 Docker services and their connections |
| **Clean Architecture Layers** | Dependency flow: HTTP → Application → Domain ← Infrastructure |
| **Order Lifecycle State Machine** | PENDING → PROCESSING → PAID/FAILED, PENDING → CANCELLED |
| **Atomic Cancellation Flow** | Conditional winner, one inventory restoration, rollback, and process-race result |
| **Request Flow (Sequence)** | Full POST /api/orders flow: lock → transaction → dispatch → async payment |
| **Distributed Locking Strategy** | Two-layer protection: Redis lock + DB FOR UPDATE |
| **Docker Infrastructure** | Container topology and port mappings |
| **Real Integration Verification** | MySQL/Redis/nginx topology, independent actors, deterministic Redis barriers, and queue-worker crash control |
| **Database ER Diagram** | Business tables plus durable idempotency and simulated-provider ledgers |
| **Queue & Worker Pipeline** | Job dispatch → worker processing → broadcast flow |
| **Payment Crash Boundaries** | Controlled before/after-provider-persistence windows and identity reuse |
| **Legacy Reconciliation** | `LEASED`, `RECONCILIATION_REQUIRED`, and explicitly approved recovery paths |

> All diagrams render natively on GitHub. For local viewing, use a Mermaid-compatible editor or [mermaid.live](https://mermaid.live).

---

## Design Decisions

| Decision | Alternative Considered | Why This Approach |
|----------|----------------------|-------------------|
| **Redis lock + DB `FOR UPDATE`** | DB locks only | Redis reduces contention; the MySQL transaction remains the correctness authority |
| **Durable `idempotency_requests` record + orders unique index** | Redis-only or controller pre-check | The DB row serializes concurrent callers and binds the key to a request fingerprint; the orders index remains a legacy/external-writer backstop |
| **Ascending Redis product-lock ordering** | Random lock order | Removes circular wait from the Redis multi-lock path; MySQL deadlock detection/retry remains relevant |
| **Jittered exponential backoff** | Fixed retry interval | ±25% jitter prevents thundering herd when many requests retry simultaneously |
| **Direct stock decrement** | 2-phase reservation (`reserved_stock` column) | Simpler, fewer failure modes — reservation adds complexity without proportional benefit here |
| **`dispatch()->afterCommit()`** | `DB::afterCommit(fn => dispatch())` | Works correctly with `Queue::fake()` in tests; closure-based approach doesn't fire in test environment |
| **Server-side total (bcmath)** | Trust client total | Never trust the client — `bcmul`/`bcadd` for exact decimal arithmetic |
| **Simulated payment ledger** | Real gateway integration | A deterministic, inspectable idempotency contract makes representative retry/crash windows reproducible without implying live-gateway safety |
| **Fast fake + separate real integration profile** | Redis in every unit test | Unit/feature tests stay deterministic; `phpunit.integration.xml` refuses fake drivers and exercises Redis/MySQL with independent processes |
| **Clean Architecture layers** | Standard Laravel MVC | Domain logic is framework-independent, testable in isolation, swappable infrastructure |
| **Fixed 10s lock TTL, no watchdog** | Lock-extension heartbeat ("watchdog", à la Redisson) | The showcase keeps the coordination mechanism small and relies on MySQL row locks for correctness. If a transaction outlives the TTL, two callers may hold the Redis lock concurrently; any design relying on Redis alone would require a different lease-extension/fencing strategy |

---

## Scaling Strategy

| Component | Current | Scale Path |
|-----------|---------|------------|
| **API (PHP-FPM)** | 1 container | Horizontal — stateless, add containers behind Nginx |
| **Workers** | 2 processes (Supervisor) | Increase `numprocs` or add worker containers |
| **Redis** | One shared eviction-prone instance | First separate cache from queue/lock/session coordination and select appropriate persistence/eviction policies; clustering requires separate failure-semantics validation |
| **MySQL** | Single instance | Read replicas for GET endpoints, primary for writes |
| **Reverb** | Single instance; scaling disabled | Redis-backed horizontal scaling is a future option and is not configured or validated here |
| **Monitoring** | Health endpoint | Queue depth alerts → auto-scale workers |

---

## Future Improvements

- [ ] **Authentication** — Laravel Sanctum token-based auth
- [ ] **Observability Stack** — Prometheus metrics + Grafana dashboards
- [ ] **Transactional Outbox** — Close the commit-to-queue dispatch failure window
- [ ] **Redis Role Separation** — Isolate cache eviction from queue, lock, session, and scheduler-mutex state
- [ ] **Kafka Migration** — Replace Redis queues for durability and replay
- [ ] **Circuit Breaker** — Resilience pattern for payment gateway failures
- [ ] **API Versioning** — `/api/v1/...` namespace for backward compatibility
- [ ] **Metrics & Alerting** — Queue depth, error rates, p95 latency monitoring
- [ ] **Rate Limiting per User** — Move from IP-based to authenticated user-based limits

---

## How this was built

I defined the problem, the design and the acceptance criteria, and reviewed and verified the result. AI coding assistants (Claude Code, Codex, Cursor) did most of the implementation.

## License

[MIT](LICENSE)
