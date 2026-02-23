# Distributed Order Processing System

> A production-grade distributed order processing system demonstrating **concurrency control**, **distributed locking**, **async payment processing**, and **real-time event broadcasting** — engineered as a backend architecture case study.

[![CI Pipeline](https://github.com/AbdouShalby/distributed-order-processing-system/actions/workflows/ci.yml/badge.svg)](https://github.com/AbdouShalby/distributed-order-processing-system/actions)

---

## Table of Contents

- [Tech Stack](#tech-stack)
- [System Architecture](#system-architecture)
- [Clean Architecture](#clean-architecture)
- [Database Schema](#database-schema)
- [Order Lifecycle & State Machine](#order-lifecycle--state-machine)
- [Distributed Locking Strategy](#distributed-locking-strategy)
- [Queue & Worker Strategy](#queue--worker-strategy)
- [Real-Time WebSocket Broadcasting](#real-time-websocket-broadcasting)
- [API Reference](#api-reference)
- [Concurrency & Safety Guarantees](#concurrency--safety-guarantees)
- [Security](#security)
- [Observability](#observability)
- [Load Testing](#load-testing)
- [CI Pipeline](#ci-pipeline)
- [Getting Started](#getting-started)
- [Project Structure](#project-structure)
- [Design Decisions](#design-decisions)
- [Scaling Strategy](#scaling-strategy)
- [Future Improvements](#future-improvements)

---

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **API** | Laravel 12 / PHP 8.4 | REST API with Clean Architecture |
| **Database** | MySQL 8.0 | ACID transactions, row-level locking |
| **Cache / Lock / Queue** | Redis 7 | Distributed locks, job queue, caching |
| **WebSocket** | Laravel Reverb | Real-time order status broadcasting |
| **Reverse Proxy** | Nginx | Load balancing, security headers |
| **Worker** | Supervisor (2 procs) | Async job processing |
| **Containers** | Docker Compose (6 services) | Full infrastructure |
| **Load Testing** | k6 | Concurrency & stress testing |
| **CI** | GitHub Actions (4 jobs) | Lint, Unit, Feature, Docker Build |

---

## System Architecture

```
┌──────────┐     ┌─────────┐     ┌──────────┐     ┌─────────┐
│  Client   │────▶│  Nginx   │────▶│  PHP-FPM  │────▶│  MySQL   │
│           │◀────│  :8000   │◀────│  (API)    │     │  8.0     │
└──────────┘     └─────────┘     └─────┬────┘     └─────────┘
                                       │
                                  ┌────▼─────┐
                                  │  Redis 7  │
                                  │ Lock+Queue│
                                  └────┬─────┘
                                       │
                                  ┌────▼─────┐     ┌──────────┐
                                  │  Worker   │────▶│  Reverb   │
                                  │(Supervisor│     │ WebSocket │
                                  │ 2 procs)  │     │  :8080    │
                                  └──────────┘     └──────────┘
```

### Request Flow

```
1. Client ──▶ POST /api/orders
2. Nginx  ──▶ PHP-FPM (OrderController)
3. Controller validates input (FormRequest)
4. CreateOrderUseCase acquires Redis distributed locks (ascending product_id)
5. DB Transaction:
   ├── SELECT ... FOR UPDATE (products)
   ├── Validate stock availability
   ├── Decrement stock atomically
   ├── INSERT order (PENDING) + order_items
   └── COMMIT
6. dispatch(ProcessOrderJob)->afterCommit()
7. Release all Redis locks
8. Return 201 Created
   ─── async ───
9. Worker picks job from Redis queue
10. Load order → guard: status must be PENDING
11. Mark PROCESSING → simulate payment (80% success)
12. Mark PAID/FAILED → broadcast via Reverb WebSocket
```

---

## Clean Architecture

The codebase follows **Clean Architecture** principles — the domain layer has zero framework dependencies:

```
┌──────────────────────────────────────────────────────┐
│  HTTP Layer (Controllers, Middleware, Requests)       │──── Framework (Laravel)
├──────────────────────────────────────────────────────┤
│  Application Layer (Use Cases, DTOs)                 │──── Orchestration
├──────────────────────────────────────────────────────┤
│  Domain Layer (Entities, VOs, Enums, Interfaces)     │──── Pure PHP (no Laravel)
├──────────────────────────────────────────────────────┤
│  Infrastructure (Eloquent, Redis, Queue, Broadcast)  │──── Implements Domain contracts
└──────────────────────────────────────────────────────┘
```

| Layer | Depends On | Contains |
|-------|-----------|----------|
| **Domain** | Nothing | `Order` entity, `OrderItem` VO, `OrderStatus` enum, repository interfaces, exceptions |
| **Application** | Domain | `CreateOrderUseCase`, `ProcessOrderUseCase`, `CancelOrderUseCase`, DTOs |
| **Infrastructure** | Domain + Laravel | `EloquentOrderRepository`, `RedisDistributedLock`, `SimulatedPaymentGateway`, `ProcessOrderJob`, broadcast events |
| **HTTP** | Application | `OrderController`, `CreateOrderRequest`, `TraceIdMiddleware` |

**Dependency Rule**: Dependencies point inward — infrastructure implements domain interfaces, never the reverse.

---

## Database Schema

### Entity-Relationship

```
┌──────────┐       ┌───────────┐       ┌──────────────┐
│  users   │──1:N──│  orders    │──1:N──│ order_items   │
│          │       │            │       │               │
│ id       │       │ id         │       │ id            │
│ name     │       │ user_id FK │       │ order_id FK   │
│ email UQ │       │ status     │       │ product_id FK │
│ password │       │ total_amt  │       │ quantity      │
└──────────┘       │ idemp_key  │       │ unit_price    │
                   │ cancel_at  │       └──────────────┘
                   └───────────┘              │
                                         ┌───┘
┌──────────┐                             │
│ products │◀────────────────────────────┘
│          │
│ id       │
│ name     │
│ price    │  DECIMAL(10,2)
│ stock    │  UNSIGNED INT
└──────────┘
```

### Index Strategy

| Table | Index | Type | Purpose |
|-------|-------|------|---------|
| `orders` | `idx_orders_idempotency` | **UNIQUE** | Idempotency key dedup |
| `orders` | `idx_orders_user` | B-Tree | Filter by user |
| `orders` | `idx_orders_status` | B-Tree | Filter by status |
| `order_items` | `idx_order_items_order` | B-Tree | Join with orders |
| `order_items` | `idx_order_items_product` | B-Tree | Join with products |
| `products` | `idx_products_stock` | B-Tree | Stock availability queries |

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

**Terminal States**: `PAID`, `FAILED`, `CANCELLED` — no further transitions allowed.

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
Layer 1: Redis Distributed Lock (prevents concurrent access)
    │
    ▼
Layer 2: DB SELECT ... FOR UPDATE (guarantees atomicity)
```

Why both? Redis lock prevents contention (fast fail). DB row lock guarantees correctness even if Redis fails.

### Lock Implementation

| Parameter | Value | Rationale |
|-----------|-------|-----------|
| **Lock key** | `inventory:product:{id}` | Per-product granularity |
| **TTL** | 10 seconds | Safety margin > max transaction time |
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

### Deadlock Prevention

When an order has multiple products, locks are acquired in **ascending `product_id`** order:

```
Order: [product_id: 5, product_id: 2, product_id: 8]
Lock order: 2 → 5 → 8 (sorted ascending)
```

This prevents circular wait (the classic deadlock condition). If any lock fails, **all** previously acquired locks are released immediately.

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
1. Load order from DB (fresh)
2. Guard: if status ≠ PENDING → exit (idempotency)
3. Transition → PROCESSING
4. Simulate payment (50-200ms delay, 80/20 success/fail)
5. On success → PAID + broadcast OrderPaid
6. On failure → FAILED + broadcast OrderFailed
7. All wrapped in DB transaction
```

### Retry Configuration

| Parameter | Value | Purpose |
|-----------|-------|---------|
| `tries` | 3 | Max attempts before `failed_jobs` table |
| `backoff` | `[1, 3, 5]` seconds | Exponential backoff between retries |
| `max_time` | 3600 seconds | Kill zombie workers after 1 hour |
| Supervisor procs | 2 | Parallel job processing |

### Delivery Guarantee

The system provides **at-least-once** delivery. The worker's idempotency guard (`if status ≠ PENDING → exit`) ensures that redelivered jobs are safely skipped — no double payments, no duplicate state changes, no repeated broadcasts.

### Known Gap & Mitigation

If the app crashes between DB commit and `afterCommit()` execution, the job is never dispatched. The order stays `PENDING` forever. **Future mitigation**: Transactional Outbox pattern — write the job to a DB table in the same transaction, then a poller pushes it to Redis.

---

## Real-Time WebSocket Broadcasting

### Technology

**Laravel Reverb** — self-hosted, zero external dependencies, official Laravel package.

### Channel & Events

| Event | Channel | Payload | Trigger |
|-------|---------|---------|---------|
| `OrderPaid` | `private-orders.{userId}` | `{ order_id, status, total_amount, timestamp }` | Payment succeeds |
| `OrderFailed` | `private-orders.{userId}` | `{ order_id, status, total_amount, reason, timestamp }` | Payment fails |
| `OrderCancelled` | `private-orders.{userId}` | `{ order_id, status, cancelled_at, timestamp }` | User cancels |

- **Private channels**: Users can only receive events for their own orders
- All broadcasts dispatched via `DB::afterCommit()` — guaranteed to fire only after data is persisted
- Event classes implement `ShouldBroadcast` (queued, non-blocking)

### Docker Container

```yaml
reverb:
  build: ...
  command: php artisan reverb:start --host=0.0.0.0 --port=8080
  ports:
    - "8080:8080"
  depends_on:
    - redis
    - mysql
```

---

## API Reference

**Base URL**: `http://localhost:8000/api`

All responses include `X-Trace-Id` header (UUID for distributed tracing).

### Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| `POST` | `/api/orders` | Create order (idempotent) | — |
| `GET` | `/api/orders/{id}` | Get order details | — |
| `GET` | `/api/orders?user_id=&status=&page=` | List orders (paginated) | — |
| `POST` | `/api/orders/{id}/cancel` | Cancel pending order | — |
| `GET` | `/api/products` | List all products | — |
| `GET` | `/api/health` | Health check (DB + Redis) | — |

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

### Validation Rules

| Field | Rules |
|-------|-------|
| `user_id` | Required, must exist in `users` table |
| `idempotency_key` | Required, string, max 255 chars |
| `items` | Required, array, min 1 element |
| `items.*.product_id` | Required, must exist in `products` table |
| `items.*.quantity` | Required, integer, min 1 |

### Error Responses

| Status | Error Code | When |
|--------|-----------|------|
| `409` | `insufficient_stock` | Not enough stock for requested quantity |
| `409` | `lock_conflict` | Could not acquire distributed lock (high contention) |
| `422` | `validation_error` | Invalid request payload |
| `422` | `order_not_cancellable` | Order is not in PENDING status |
| `404` | `not_found` | Order or resource not found |
| `429` | `too_many_requests` | Rate limit exceeded (60 req/min) |

### Health Check

```bash
curl http://localhost:8000/api/health
```

```json
{
  "status": "ok",
  "services": {
    "database": "connected",
    "redis": "connected"
  }
}
```

Returns `503` with `"status": "degraded"` if any service is down.

### Cancel Order

```bash
curl -X POST http://localhost:8000/api/orders/1/cancel
```

- Only `PENDING` orders can be cancelled
- Stock is restored atomically in a DB transaction
- Re-cancelling an already cancelled order returns `200 OK` (idempotent)
- `PROCESSING`, `PAID`, `FAILED` orders return `422`

### List Orders (Paginated)

```bash
curl "http://localhost:8000/api/orders?user_id=1&status=PAID&page=1&per_page=15"
```

Supports filtering by `user_id` and `status`. Pagination: `per_page` default 15, max 50.

---

## Concurrency & Safety Guarantees

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
Request 1 (key="abc") ──▶ CREATE order → 201 Created
Request 2 (key="abc") ──▶ FIND existing → 200 OK (same order returned)
```

The `idempotency_key` has a `UNIQUE` constraint on the `orders` table. No separate idempotency table, no TTL, no cleanup — simple and permanent.

### Cancel Safety

```
Can cancel:    PENDING → CANCELLED (stock restored in same transaction)
Cannot cancel: PROCESSING (worker may be mid-payment)
Cannot cancel: PAID / FAILED / CANCELLED (terminal states)
Idempotent:    CANCELLED → CANCELLED returns 200 OK
```

---

## Security

| Measure | Implementation |
|---------|---------------|
| **Rate Limiting** | 60 req/min per IP via Laravel `ThrottleRequests` middleware (configurable) |
| **Input Validation** | Strict Laravel FormRequest classes on all endpoints |
| **Server-Side Pricing** | Total always calculated from DB prices — client values ignored |
| **Decimal Precision** | `DECIMAL(10,2)` + `bcmath` — zero floating-point errors |
| **Idempotency** | UNIQUE constraint prevents duplicate order creation |
| **SQL Injection** | All queries via Eloquent parameterized queries — no raw SQL with user input |
| **Mass Assignment** | All models use explicit `$fillable` whitelist |
| **Trace Propagation** | `X-Trace-Id` UUID header for distributed tracing (auto-generated if missing) |
| **Error Masking** | No stack traces in production — generic messages with error codes; details logged server-side |
| **Security Headers** | `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection` via Nginx |

---

## Observability

### Structured Logging

All log entries are JSON-formatted with consistent fields:

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

- Every request gets a `X-Trace-Id` UUID (auto-generated or client-provided)
- Trace ID propagated through: Controller → Use Case → Worker Job → Broadcast Event
- Enables end-to-end request tracking across async boundaries

### Health Monitoring

`GET /api/health` checks:
- **Database**: MySQL connection via `DB::connection()->getPdo()`
- **Redis**: `Redis::ping()` response verification
- Returns `200 OK` or `503 Service Unavailable` (degraded)

---

## Load Testing

Three k6 scenarios in `load-tests/`:

| Test | Scenario | What It Proves |
|------|----------|---------------|
| `oversell-test.js` | 50 VUs race for `stock=1` | Exactly 1 order succeeds, 49 get `409` |
| `idempotency-test.js` | 50 VUs with same key | Exactly 1 created (`201`), 49 return existing (`200`) |
| `high-load-test.js` | Ramp 0→50 VUs over 30s | p95 response time < 500ms under load |

```bash
# Install k6
brew install k6  # or: sudo apt install k6

# Run oversell test (product with stock=1)
k6 run load-tests/oversell-test.js

# Run with custom base URL
k6 run -e BASE_URL=http://localhost:8000 load-tests/high-load-test.js
```

---

## CI Pipeline

GitHub Actions workflow with **4 parallel jobs**:

```
┌─────────────────┐  ┌──────────────┐  ┌───────────────┐  ┌──────────────┐
│ Lint & Static   │  │  Unit Tests  │  │ Feature Tests │  │ Docker Build │
│ Analysis        │  │              │  │               │  │              │
│                 │  │ Pure domain  │  │ MySQL + Redis │  │ Build all 6  │
│ composer install│  │ logic tests  │  │ services      │  │ containers   │
│ route:list      │  │ (no DB)      │  │ Full HTTP     │  │ Verify start │
└─────────────────┘  └──────────────┘  └───────────────┘  └──────────────┘
```

| Job | What It Validates | Duration |
|-----|------------------|----------|
| **Lint** | Code style (Pint), dependencies, routes resolve | ~15s |
| **Unit Tests** | 58 tests — Domain entities, state machine, Application use cases (mocked) | ~12s |
| **Feature Tests** | 18 tests — Full HTTP lifecycle, concurrency, idempotency, health | ~40s |
| **Docker Build** | All 6 containers build and start successfully | ~60s |

**Total: 76 tests, 247 assertions**

---

## Getting Started

### Prerequisites

- Docker & Docker Compose
- Git

### Quick Start

```bash
# Clone
git clone https://github.com/AbdouShalby/distributed-order-processing-system.git
cd distributed-order-processing-system

# Copy environment file
cp .env.example .env

# Start all 6 services
docker compose up -d --build

# Run migrations & seed sample data
docker compose exec app php artisan migrate:fresh --seed

# Verify everything works
curl http://localhost:8000/api/health
# → {"status":"ok","services":{"database":"connected","redis":"connected"}}

curl http://localhost:8000/api/products
# → 5 seeded products (Laptop, Phone, Headphones, Mouse, USB Hub)

# Create your first order
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id":1,"idempotency_key":"my-first-order","items":[{"product_id":1,"quantity":1}]}'
# → 201 Created, status: PENDING

# Check order status (worker processes it async → should be PAID or FAILED)
curl http://localhost:8000/api/orders/1
# → status: PAID (80% chance) or FAILED (20% chance)

# Run all tests
docker compose exec app php artisan test
# → 76 passed (247 assertions)

# Stop everything
docker compose down
```

### Makefile Shortcuts

```bash
make setup    # build + up + migrate + seed
make test     # run all tests
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
| `reverb` | PHP 8.4-FPM | `8080:8080` | WebSocket server |

---

## Project Structure

```
.
├── app/
│   ├── Domain/                              # Pure business logic (zero Laravel deps)
│   │   ├── Order/
│   │   │   ├── Entities/Order.php           # Order aggregate root
│   │   │   ├── Enums/OrderStatus.php        # State machine with canTransitionTo()
│   │   │   ├── ValueObjects/OrderItem.php   # Immutable line item (product, qty, price)
│   │   │   ├── Contracts/                   # OrderRepositoryInterface
│   │   │   └── Exceptions/                  # OrderNotFound, NotCancellable, InvalidTransition
│   │   ├── Inventory/
│   │   │   ├── Contracts/                   # ProductRepositoryInterface, DistributedLockInterface
│   │   │   └── Exceptions/                  # InsufficientStock, LockAcquisition
│   │   └── Payment/
│   │       ├── Contracts/PaymentGatewayInterface.php
│   │       └── ValueObjects/PaymentResult.php
│   │
│   ├── Application/                         # Use cases orchestrating domain logic
│   │   ├── UseCases/
│   │   │   ├── CreateOrder/                 # Lock → validate → decrement → save → dispatch
│   │   │   ├── ProcessOrder/                # Guard → payment → update status → broadcast
│   │   │   └── CancelOrder/                 # Guard → restore stock → cancel → broadcast
│   │   └── DTOs/                            # CreateOrderDTO, OrderResponseDTO
│   │
│   ├── Infrastructure/                      # Framework implementations
│   │   ├── Locking/
│   │   │   ├── RedisDistributedLock.php     # SET NX EX + Lua release + jittered backoff
│   │   │   └── InMemoryDistributedLock.php  # Test double (no Redis needed in tests)
│   │   ├── Persistence/Repositories/
│   │   │   ├── EloquentOrderRepository.php  # Implements OrderRepositoryInterface
│   │   │   └── EloquentProductRepository.php
│   │   ├── PaymentGateway/
│   │   │   └── SimulatedPaymentGateway.php  # 80/20 success/fail, 50-200ms delay
│   │   ├── Queue/Jobs/
│   │   │   └── ProcessOrderJob.php          # Queued job with 3 retries, [1,3,5]s backoff
│   │   └── Broadcasting/Events/
│   │       ├── OrderPaidEvent.php           # ShouldBroadcast → private-orders.{userId}
│   │       ├── OrderFailedEvent.php
│   │       └── OrderCancelledEvent.php
│   │
│   ├── Http/                                # Thin controllers (no business logic)
│   │   ├── Controllers/
│   │   │   ├── OrderController.php          # CRUD + idempotency check
│   │   │   ├── ProductController.php
│   │   │   └── HealthController.php         # DB + Redis health probes
│   │   ├── Requests/CreateOrderRequest.php  # Validation rules
│   │   └── Middleware/TraceIdMiddleware.php  # X-Trace-Id propagation
│   │
│   └── Providers/
│       └── DomainServiceProvider.php        # Interface → Implementation bindings
│
├── database/
│   ├── migrations/                          # 7 migrations
│   └── seeders/DatabaseSeeder.php           # 5 products + 2 users
│
├── tests/
│   ├── Unit/
│   │   ├── Application/                    # Use case tests with mocked dependencies
│   │   │   ├── CreateOrderUseCaseTest.php   # 6 tests — success, idempotency, lock fail, stock, precision
│   │   │   ├── ProcessOrderUseCaseTest.php  # 6 tests — paid, failed, guard, missing, transitions
│   │   │   └── CancelOrderUseCaseTest.php   # 7 tests — cancel, idempotent, non-cancellable, stock restore
│   │   └── Domain/Order/
│   │       ├── OrderEntityTest.php           # 14 tests — transitions, totals, precision
│   │       ├── OrderItemTest.php             # 4 tests — immutability, line totals
│   │       └── OrderStatusTest.php           # 20 tests — valid/invalid transitions, terminal
│   └── Feature/
│       ├── OrderLifecycleTest.php           # 12 tests — create, show, list, cancel, idempotency
│       ├── ConcurrencyTest.php              # 3 tests — oversell, idempotency, concurrent cancel
│       └── HealthCheckTest.php              # 2 tests — healthy, degraded
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
├── docker-compose.yml                       # 6 services
├── Makefile                                 # setup, test, down shortcuts
└── README.md                                # You are here
```

---

## Design Decisions

| Decision | Alternative Considered | Why This Approach |
|----------|----------------------|-------------------|
| **Redis lock + DB `FOR UPDATE`** | DB locks only | Layered defense — Redis prevents contention at the gate, DB guarantees correctness as the last line |
| **Idempotency key in `orders` table** | Separate idempotency table | One fewer table, one fewer query, no TTL/cleanup needed |
| **Ascending product_id lock ordering** | Random lock order | Prevents circular wait (classic deadlock condition) |
| **Jittered exponential backoff** | Fixed retry interval | ±25% jitter prevents thundering herd when many requests retry simultaneously |
| **Direct stock decrement** | 2-phase reservation (`reserved_stock` column) | Simpler, fewer failure modes — reservation adds complexity without proportional benefit here |
| **`dispatch()->afterCommit()`** | `DB::afterCommit(fn => dispatch())` | Works correctly with `Queue::fake()` in tests; closure-based approach doesn't fire in test environment |
| **Server-side total (bcmath)** | Trust client total | Never trust the client — `bcmul`/`bcadd` for exact decimal arithmetic |
| **Simulated payment** | Real gateway integration | 80/20 success/fail with 50-200ms delay is realistic enough for architecture validation |
| **InMemoryDistributedLock for tests** | Mock Redis in tests | Simpler, faster, no Redis dependency in CI unit tests; real Redis used only in feature tests with services |
| **Clean Architecture layers** | Standard Laravel MVC | Domain logic is framework-independent, testable in isolation, swappable infrastructure |

---

## Scaling Strategy

| Component | Current | Scale Path |
|-----------|---------|------------|
| **API (PHP-FPM)** | 1 container | Horizontal — stateless, add containers behind Nginx |
| **Workers** | 2 processes (Supervisor) | Increase `numprocs` or add worker containers |
| **Redis** | Single instance | Redis Cluster for lock/queue partition tolerance |
| **MySQL** | Single instance | Read replicas for GET endpoints, primary for writes |
| **Reverb** | Single instance | Horizontal scaling with Redis pub/sub backend |
| **Monitoring** | Health endpoint | Queue depth alerts → auto-scale workers |

---

## Future Improvements

- [ ] **Authentication** — Laravel Sanctum token-based auth
- [ ] **Observability Stack** — Prometheus metrics + Grafana dashboards
- [ ] **Transactional Outbox** — Guaranteed event delivery (no lost jobs on crash)
- [ ] **Kafka Migration** — Replace Redis queues for durability and replay
- [ ] **Circuit Breaker** — Resilience pattern for payment gateway failures
- [ ] **API Versioning** — `/api/v1/...` namespace for backward compatibility
- [ ] **Metrics & Alerting** — Queue depth, error rates, p95 latency monitoring
- [ ] **Rate Limiting per User** — Move from IP-based to authenticated user-based limits

---

## License

MIT
