# Production-Inspired Architecture

> Detailed architecture diagrams for the Distributed Order Processing System.
> These diagrams supplement the [README](../README.md) with visual references.
> They describe a production-inspired showcase with a simulated payment provider,
> not a production deployment or a live-payment safety claim.

---

## System Architecture Overview

```mermaid
graph TB
    Client([Client]) -->|HTTP| Nginx[Nginx :8000]
    Nginx -->|FastCGI| PHP[PHP-FPM API]
    PHP -->|Read/Write| MySQL[(MySQL 8.0)]
    PHP -->|Cache + Lock + Queue + Session| Redis[(Shared Redis 7)]
    Redis -->|Job| Worker[Worker - Supervisor 2 procs]
    Worker -->|Read/Write| MySQL
    Worker -->|Broadcast| Reverb[Reverb WebSocket :8080]
    Reverb -.->|Startup dependency; scaling disabled| Redis
    Reverb -->|Push Events| Client
    Scheduler[Scheduler - schedule:work] -->|Recovery scan| MySQL
    Scheduler -->|Mutex + enqueue| Redis

    style Client fill:#e1f5fe
    style Nginx fill:#fff3e0
    style PHP fill:#e8f5e9
    style MySQL fill:#fce4ec
    style Redis fill:#fff8e1
    style Worker fill:#f3e5f5
    style Scheduler fill:#ede7f6
    style Reverb fill:#e0f2f1
```

---

## Clean Architecture Layers

```mermaid
graph TB
    subgraph HTTP["HTTP Layer (Laravel)"]
        Controllers[Controllers]
        Middleware[Middleware]
        Requests[Form Requests]
    end

    subgraph App["Application Layer"]
        CreateOrder[CreateOrderUseCase]
        ProcessOrder[ProcessOrderUseCase]
        CancelOrder[CancelOrderUseCase]
        DTOs[DTOs]
    end

    subgraph Domain["Domain Layer (Pure PHP)"]
        Entities[Order Entity]
        VOs[Value Objects]
        Enums[OrderStatus Enum]
        Interfaces[Repository Interfaces]
        Exceptions[Domain Exceptions]
    end

    subgraph Infra["Infrastructure Layer"]
        Eloquent[Eloquent Repositories]
        RedisLock[Redis Distributed Lock]
        Payment[Simulated Payment]
        Jobs[Queue Jobs]
        Events[Broadcast Events]
    end

    HTTP --> App
    App --> Domain
    Infra -.->|implements| Domain

    style HTTP fill:#e3f2fd
    style App fill:#e8f5e9
    style Domain fill:#fff3e0
    style Infra fill:#fce4ec
```

**Dependency Rule**: All arrows point inward. Infrastructure implements Domain interfaces, never the reverse.

---

## Order Lifecycle State Machine

```mermaid
stateDiagram-v2
    [*] --> PENDING: Order Created

    PENDING --> PROCESSING: Worker picks job
    PENDING --> CANCELLED: User cancel API

    PROCESSING --> PAID: Payment succeeds (80%)
    PROCESSING --> FAILED: Payment fails (20%)

    PAID --> [*]
    FAILED --> [*]
    CANCELLED --> [*]

    note right of PENDING
        Stock decremented
        Job dispatched (afterCommit)
    end note

    note right of CANCELLED
        Stock restored atomically
        Broadcast OrderCancelled
    end note

    note right of PAID
        Broadcast OrderPaid
    end note

    note right of FAILED
        Broadcast OrderFailed
    end note
```

---

## Atomic Cancellation Flow

```mermaid
sequenceDiagram
    participant C as Cancel caller(s)
    participant DB as MySQL
    participant WS as Reverb

    C->>DB: BEGIN; UPDATE order to CANCELLED WHERE status=PENDING
    alt Exactly one conditional-update winner
        C->>DB: Lock/load order and items
        C->>DB: Increment inventory for each item
        DB-->>C: COMMIT status + all inventory increments
        C->>WS: Broadcast OrderCancelled after commit
    else Order already CANCELLED
        DB-->>C: 200 idempotent replay; no inventory increment
    else PROCESSING, PAID, or FAILED won first
        DB-->>C: 422 INVALID_TRANSITION; no inventory increment
    end
```

Cancellation and worker processing race on conditional updates to the same order
row. If cancellation wins, the worker claim skips the terminal order. If the
worker changes `PENDING` to `PROCESSING` first, cancellation is rejected. Any
inventory-restoration exception rolls the cancellation transaction back.

---

## Request Flow (Create Order)

```mermaid
sequenceDiagram
    participant C as Client
    participant N as Nginx
    participant A as PHP-FPM (API)
    participant R as Redis
    participant DB as MySQL
    participant Q as Queue (Redis)
    participant W as Worker
    participant WS as Reverb (WebSocket)

    C->>N: POST /api/orders
    N->>A: Forward (FastCGI)
    A->>A: Validate (FormRequest)
    A->>A: Canonicalize payload + SHA-256 fingerprint
    A->>DB: Fast lookup idempotency_requests

    alt Existing durable request
        A->>A: Compare fingerprint + load completed order
        A-->>C: 200 replay or 409 conflict
    else No durable request
        A->>DB: Fast lookup legacy/external order unique key
        alt Legacy order exists
            A->>A: Compare stored/derived fingerprint
            A-->>C: 200 replay or 409 conflict
        else No existing result
            rect rgb(230, 245, 255)
                Note over A,DB: Database-authoritative transaction
                A->>DB: INSERT IGNORE idempotency reservation
                A->>DB: SELECT reservation FOR UPDATE
                A->>A: Compare fingerprint
                alt Caller owns reservation
                    A->>R: Acquire sorted product locks (SET NX EX)
                    A->>DB: SELECT products FOR UPDATE
                    A->>A: Validate stock + calculate server total
                    A->>DB: Decrement stock; insert order + items
                    A->>DB: Complete reservation with order_id
                    DB-->>A: COMMIT
                else Concurrent caller completed reservation
                    A->>DB: Load the completed order
                end
            end

            alt Transaction winner
                A->>Q: dispatch(ProcessOrderJob)->afterCommit()
                A->>R: Release held locks with token-checked Lua
                A-->>C: 201 Created
            else Exact concurrent replay
                A-->>C: 200 OK + Idempotency-Replayed: true
            else Fingerprint conflict
                A-->>C: 409 IDEMPOTENCY_CONFLICT
            end
        end
    end

    rect rgb(245, 230, 255)
        Note over W,WS: Async Processing
        Q->>W: Deliver job (at least once)
        W->>DB: Atomic conditional claim + token + lease
        W->>W: Charge with stable provider idempotency key
        alt Payment Success (80%)
            W->>DB: Token-checked UPDATE → PAID
            W->>WS: Broadcast OrderPaid
        else Payment Failure (20%)
            W->>DB: Token-checked UPDATE → FAILED
            W->>WS: Broadcast OrderFailed
        else Retryable/ambiguous provider error
            W->>DB: Expire matching lease
            W->>Q: Retry with the same provider key
        end
        WS-->>C: Push event (configured private channel)
    end
```

The `orders.idempotency_key` unique index remains a legacy/external-writer
backstop. Its duplicate-key recovery path compares the fingerprint and returns a
replay rather than reporting a second creation. Only the transaction winner
dispatches work or runs creation handlers.

The channel callback checks an authenticated user's ID, but API authentication
and an end-to-end broadcast-authentication flow are not implemented or proven in
this milestone. Broadcast delivery also remains outside the database transaction.

---

## Refund Reservation, Approval and Execution

Agent routes require a configured shared service token. Customer routes also
validate `X-Customer-Id` and check ownership; the Ops queue, history and decisions
require only the service token. This is a trusted-service boundary, not end-user
authentication or separate human-role authorization. Public routes retain their
existing behavior. See the [Refunds API contract](../README.md#refunds-api).
Refund response objects include `customer_id` on every refund endpoint. The Ops
queue accepts `PENDING_APPROVAL` and `PENDING` (stuck executions), each oldest
first with an ID tiebreaker and a maximum of 50 rows. Rate limiting returns
structured JSON with `message`, `error_code: RATE_LIMIT_EXCEEDED`, and `retry_after`.

```mermaid
sequenceDiagram
    participant Caller as Agent / Ops
    participant API as Agent API / Use Cases
    participant DB as MySQL
    participant Gateway as Simulated Refund Provider

    Caller->>API: Create refund (token, customer, amount string, key, mode)
    API->>DB: BEGIN; lock owned order FIRST
    API->>DB: Look up key; check fingerprint before new-refund rules
    alt Exact replay
        DB-->>API: Existing refund
        API->>DB: COMMIT
        Note over API: Return existing result; PENDING replay retries execution below
    else New request on PAID order
        API->>DB: Read refund rows after order lock; compute reserved using bcmath
        Note over API,DB: Reject if reserved + amount exceeds order total
        API->>DB: Reserve PENDING or PENDING_APPROVAL; COMMIT
    end

    alt Held for approval
        API-->>Caller: PENDING_APPROVAL (new create: 201)
        Caller->>API: Approve or reject (token, decided_by; rejection reason)
        API->>DB: Read order_id before transaction
        API->>DB: BEGIN; lock order FIRST, then refund
        alt Approve PENDING_APPROVAL
            API->>DB: Set PENDING and decision audit fields; COMMIT
        else Reject PENDING_APPROVAL
            API->>DB: Set REJECTED and reason; COMMIT (release reservation)
            API-->>Caller: 200 REJECTED; no gateway call
        else Already decided
            API->>DB: ROLLBACK
            API-->>Caller: 409 REFUND_NOT_PENDING_APPROVAL
        end
    end

    opt Refund is PENDING (auto, approval, replay, or reconcile)
        API->>Gateway: Refund with stored provider key, OUTSIDE transaction
        alt Success or definitive decline
            Gateway-->>API: Durable idempotent outcome
            API->>DB: BEGIN; lock order FIRST, then refund
            API->>DB: If still PENDING, set SUCCEEDED or FAILED
            opt Succeeded total equals paid total
                API->>DB: Conditional PAID to REFUNDED
            end
            API->>DB: COMMIT
        else Exception / timeout / unknown result
            Note over API,DB: Leave PENDING reserved; retry stored provider key later
        end
        API-->>Caller: Refund with current status
    end
```

`refunds` is the source of truth for both reserved and succeeded amounts.
`PENDING_APPROVAL`, `PENDING`, and `SUCCEEDED` reserve money; `REJECTED` and
`FAILED` do not. Every refund transaction locks the order row first; refund rows
are read after that lock, establishing one serialization point for reservation
and decisions without refund-collection gap locks. Decisions and execution retain
the single-refund primary-key lock. Each transaction allows up to three attempts
on a deadlock. Orders referenced by refund ledger entries are protected from
deletion by a restrictive foreign key.
Monetary values are decimal strings and arithmetic uses bcmath at scale 2;
`RefundAmounts::reserved(rows) <= order.total_amount` is the per-order invariant.
The fingerprint includes order, customer, normalized amount and mode; `reason`
is excluded. Foreign ownership is rejected before idempotency lookup.

`auto` reserves `PENDING` only when cumulative reserved-after-create is within
`services.refunds.auto_approve_limit` (default `50.00`); otherwise it reserves
`PENDING_APPROVAL`. Empty or malformed limits hold everything. Human approval
transitions the already reserved amount to `PENDING`. Partial success leaves
the order `PAID`; full success conditionally marks it `REFUNDED`.

Gateway calls are plain application code after commit, not transaction callbacks.
An exception or a crash after reservation retains `PENDING`; create replay or
`refunds:reconcile {refundId}` retries with the persisted provider identity.
`refunds:reconcile --all-pending` instead retries the oldest 100 `PENDING` refunds
and prints one `<refundId>: <status>` line per refund. The forms are mutually
exclusive. Gateway exceptions log their class and message alongside the refund
and trace IDs; an invalid incoming trace ID is replaced with a UUID before use.
There is no scheduled refund reconciler. The simulator's unique operation ledger
models one logical side effect per key; it is not proof of a real provider's
settlement or recovery behavior.

`RefundConcurrencyTest` uses independent PHP processes released by the existing
Redis barrier, HTTP through integration nginx/PHP-FPM, and committed MySQL rows.
It covers the paid cap, identical-key creates, competing approvals, first refunds
on eight distinct orders, and same-key conflicts across two orders, ending each
case with a database-wide per-order reservation check. The HTTP service and
tests use the integration environment's deterministic refund `success` outcome;
the tests fail setup if the shared configuration selects another outcome or a
timeout. The k6 refund scenario separately races 50 held requests against a
fresh `100.00` order. The verified refund race is recorded in
[`load-tests/results.md`](../load-tests/results.md); the two additional cross-order
regressions require a fresh real-MySQL run. This evidence does not establish
production deployment or real payments.

## Distributed Locking Strategy

```mermaid
graph TB
    subgraph "Layer 1: Redis Lock"
        Acquire[SET key token NX EX 10] --> |Success| Proceed[Proceed to DB]
        Acquire --> |Fail| Retry[Jittered Backoff]
        Retry --> |Max retries| Reject[409 Conflict]
        Retry --> |Retry| Acquire
    end

    subgraph "Layer 2: DB Lock"
        Proceed --> Select[SELECT ... FOR UPDATE]
        Select --> Validate[Validate Stock]
        Validate --> |OK| Decrement[Decrement Stock]
        Validate --> |Insufficient| Rollback[409 Insufficient Stock]
        Decrement --> Commit[COMMIT]
    end

    subgraph "Release"
        Commit --> LuaRelease["Lua: if GET==token then DEL"]
    end

    style Acquire fill:#fff3e0
    style Select fill:#e3f2fd
    style LuaRelease fill:#e8f5e9
```

---

## Docker Infrastructure

```mermaid
graph LR
    subgraph Docker Compose
        app[app<br/>PHP 8.4-FPM]
        nginx[nginx<br/>:8000 → :80]
        mysql[mysql<br/>MySQL 8.0<br/>:33061]
        redis[redis<br/>Shared Redis 7<br/>:63790<br/>cache + queue + lock + session + mutex]
        worker[worker<br/>Supervisor<br/>2 processes]
        scheduler[scheduler<br/>schedule:work<br/>1 process]
        reverb[reverb<br/>WebSocket<br/>:8080]
    end

    nginx --> app
    app --> mysql
    app --> redis
    worker --> mysql
    worker --> redis
    worker --> reverb
    scheduler -->|scan recoverable work| mysql
    scheduler -->|mutex + enqueue| redis
    reverb -.->|startup dependency; scaling off| redis

    style nginx fill:#fff3e0
    style app fill:#e8f5e9
    style mysql fill:#fce4ec
    style redis fill:#fff8e1
    style worker fill:#f3e5f5
    style scheduler fill:#ede7f6
    style reverb fill:#e0f2f1
```

---

## Real Integration Verification Topology

```mermaid
graph TB
    Test[PHPUnit integration service] --> MySQL[(MySQL 8.0)]
    Test --> Redis[(Redis 7)]
    Test --> Nginx[Nginx]
    Nginx --> App[PHP-FPM API]
    App --> MySQL
    App --> Redis

    Test --> Actors[Independent PHP actor processes]
    Actors -->|HTTP races| Nginx
    Actors -->|claims, cancellation, assertions| MySQL
    Actors -->|deterministic start/latch barriers| Redis

    Redis --> Queue[Real Redis queues]
    Queue --> Worker[One-shot queue-worker child]
    Worker --> MySQL
    Crash[Independent crash controller] -->|SIGKILL after provider-persisted latch| Worker
    Worker -->|provider-persisted latch| Redis

    style Test fill:#e3f2fd
    style MySQL fill:#fce4ec
    style Redis fill:#fff8e1
    style Actors fill:#f3e5f5
    style Worker fill:#e8f5e9
    style Crash fill:#ffcdd2
```

The isolated profile refuses SQLite, array cache, synchronous queues, and the
in-memory lock implementation. HTTP contention goes through nginx and PHP-FPM;
actor processes synchronize with expiring Redis barriers instead of sequential
loops. Queue tests consume real Redis jobs, and the hard-crash fixture uses a
separate controller process to stop the worker at a deterministic simulated-
provider boundary. Broadcasts use the log driver in this profile, so the suite
does not prove authenticated end-to-end WebSocket clients.

---

## Database Schema (ER Diagram)

```mermaid
erDiagram
    users {
        int id PK
        string name
        string email UK
        string password
        timestamp created_at
    }

    orders {
        int id PK
        int user_id FK
        string status
        decimal total_amount
        string idempotency_key UK
        char request_fingerprint
        uuid processing_token
        timestamp processing_claimed_at
        timestamp processing_lease_until
        int processing_attempt
        string payment_idempotency_key
        string processing_recovery_state
        timestamp cancelled_at
        timestamp created_at
        timestamp updated_at
    }

    order_items {
        int id PK
        int order_id FK
        int product_id FK
        int quantity
        decimal unit_price
    }

    products {
        int id PK
        string name
        decimal price
        int stock
        timestamp created_at
    }

    idempotency_requests {
        int id PK
        string idempotency_key UK
        char request_fingerprint
        string status
        int order_id FK "nullable, unique"
        timestamp created_at
        timestamp updated_at
    }

    simulated_payment_operations {
        int id PK
        string idempotency_key UK
        int order_id FK
        decimal amount
        string status
        string message
        int request_count
        int side_effect_count
        timestamp created_at
        timestamp updated_at
    }

    users ||--o{ orders : "places"
    orders ||--o{ order_items : "contains"
    products ||--o{ order_items : "referenced by"
    orders o|--o| idempotency_requests : "may resolve"
    orders ||--o{ simulated_payment_operations : "simulated provider ledger"
```

`idempotency_requests` is the create-request authority. The unique
`orders.idempotency_key` remains a defensive backstop for legacy/external
writers. `simulated_payment_operations` represents a local provider contract;
it is not evidence about a real payment gateway.

---

## Queue & Worker Pipeline

```mermaid
flowchart TD
    A[Job dispatched<br/>afterCommit] --> B[Redis Queue]
    B --> C[Worker picks job]
    C --> D{Atomic claim succeeds?}
    D --> |No| E[Duplicate/live lease/terminal:<br/>skip]
    D --> |PENDING, expired LEASED,<br/>or approved SAFE_TO_RECOVER| F[Persist PROCESSING token,<br/>lease, attempt, provider key]
    F --> G[Charge using stable<br/>provider idempotency key]
    G --> |Success| J[Token-checked PAID]
    G --> |Decline| K[Token-checked FAILED]
    G --> |Retryable or unknown| H[Expire matching lease<br/>and rethrow]
    H --> B
    S[One Docker scheduler<br/>schedule:work] --> O[Every minute:<br/>orders:recover-expired --limit=100]
    O --> B
    J --> L[Broadcast OrderPaid]
    K --> M[Broadcast OrderFailed]
    L --> N[Done]
    M --> N

    style B fill:#fff8e1
    style J fill:#c8e6c9
    style K fill:#ffcdd2
```

### Scheduled Recovery Runtime

The default Compose runtime contains exactly one dedicated `scheduler` service.
It runs `php /var/www/artisan schedule:work`, invokes
`orders:recover-expired --limit=100` every minute, and uses Laravel's existing
`withoutOverlapping(5)` mutex. Command output, including the number requeued,
is sent to stdout and is visible with `docker compose logs scheduler`.

The isolated Docker verification demonstrates the scheduler executing against
real MySQL and Redis, queueing an expired lease while no worker is running, and
one real worker later completing it through the simulated provider with the same
durable payment identity.

This is production-inspired runtime wiring, with explicit limits:

- `restart: unless-stopped` restarts an unexpectedly exited scheduler process;
- unavailable MySQL or Redis can fail a tick, which is reported in the logs;
- while workers remain down, later ticks can enqueue duplicate recovery attempts
  and grow the backlog;
- database claims and provider idempotency protect business side effects, not
  queue growth;
- the default showcase does not run multiple scheduler instances.

The current shared, eviction-prone Redis and the commit-to-queue gap remain
documented limitations for later milestones. This verification does not claim
exactly-once execution, guaranteed recovery, or arbitrary crash safety.

### Payment Crash Boundaries

```mermaid
flowchart TD
    A[Atomic claim<br/>PROCESSING + lease + provider key] --> B{Crash boundary}
    B --> |Before provider persistence| C[No simulated provider row]
    B --> |After provider persistence<br/>before PAID| D[Provider row durable<br/>order still PROCESSING]
    B --> |After PAID commit| E[Order terminal PAID]
    C --> F[Lease expires]
    D --> F
    F --> G[Recovery queues at-least-once work]
    G --> H[Atomic reclaim reuses<br/>the same provider key]
    H --> I[Idempotent provider replay]
    I --> J[Token-checked PAID or FAILED]
    E --> K[Duplicate delivery skips payment]

    style D fill:#fff3e0
    style E fill:#c8e6c9
    style J fill:#c8e6c9
```

The Milestone 2A.4 hard-crash test uses a real queue-worker child process and an
explicit Redis latch inside the simulated provider. The latch is published only
after the provider ledger is durable; a second application process then sends
`SIGKILL` before control returns to the order use case. The order remains
`PROCESSING`, recovery later reuses its stored provider identity, and the
simulated provider records two requests but one idempotent side effect.

A timeout expires the matching current `LEASED` claim, so the scheduler may
requeue it and the simulated provider's idempotent replay can return the durable
result under the same identity. This is not the legacy
`RECONCILIATION_REQUIRED` marker. A real provider integration must define an
authoritative lookup or equivalent idempotent-replay contract before enabling
that automatic path; the repository never invents an external outcome. A
durable simulated decline completes `FAILED` and is not retried, while the
demonstrated pre-acceptance failure expires the lease and may retry with the same
identity. Once `PAID` is durable, payment delivery is terminal, but
notification/handler delivery is not covered by a transactional outbox. These
are production-inspired patterns demonstrated at controlled boundaries, not
arbitrary crash safety or a live payment guarantee.

### Legacy `PROCESSING` Reconciliation

```mermaid
flowchart TD
    A[Historical PROCESSING row] --> B{Complete current<br/>lease metadata?}
    B --> |Yes| C[Classify LEASED]
    B --> |No| D[Classify<br/>RECONCILIATION_REQUIRED]
    C --> E{Lease expired?}
    E --> |No| F[Do not steal active lease]
    E --> |Yes| G[Eligible for atomic reclaim<br/>with existing provider key]
    D --> H[No automatic queue or payment]
    H --> I{Authoritative evidence<br/>payment never started?}
    I --> |No or unknown| J[Remain reconciliation-required]
    I --> |Explicit operator confirmation| K[Mark SAFE_TO_RECOVER]
    K --> L[Recovery command may enqueue]
    L --> M[Atomic claim writes first<br/>durable provider identity]

    style D fill:#fff3e0
    style H fill:#ffcdd2
    style J fill:#ffcdd2
    style K fill:#e3f2fd
    style G fill:#c8e6c9
    style M fill:#c8e6c9
```

This is a production-inspired reconciliation policy demonstrated with a
simulated payment provider. The idempotent backfill classifies only; it never
fabricates a token, lease, attempt, provider key, or payment outcome. The
`--confirmed-no-payment` operator flag represents evidence obtained outside this
showcase. Known paid/declined outcomes and unknown outcomes are intentionally not
replayed. The real-MySQL/Redis integration test is a reproducible concurrency and
recovery demonstration; it does not establish fitness for live payment operations
or recovery across every possible crash boundary.

---

*These diagrams are rendered natively on GitHub. For local viewing, use a Mermaid-compatible Markdown viewer or [mermaid.live](https://mermaid.live).*
