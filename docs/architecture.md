# Architecture Documentation

> Detailed architecture diagrams for the Distributed Order Processing System.
> These diagrams supplement the [README](../README.md) with visual references.

---

## System Architecture Overview

```mermaid
graph TB
    Client([Client]) -->|HTTP| Nginx[Nginx :8000]
    Nginx -->|FastCGI| PHP[PHP-FPM API]
    PHP -->|Read/Write| MySQL[(MySQL 8.0)]
    PHP -->|Lock + Queue| Redis[(Redis 7)]
    Redis -->|Job| Worker[Worker - Supervisor 2 procs]
    Worker -->|Read/Write| MySQL
    Worker -->|Broadcast| Reverb[Reverb WebSocket :8080]
    Reverb -->|Push Events| Client

    style Client fill:#e1f5fe
    style Nginx fill:#fff3e0
    style PHP fill:#e8f5e9
    style MySQL fill:#fce4ec
    style Redis fill:#fff8e1
    style Worker fill:#f3e5f5
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

    rect rgb(255, 245, 230)
        Note over A,R: Distributed Lock Acquisition
        A->>R: SET lock:product:{id} NX EX 10
        R-->>A: OK (lock acquired)
    end

    rect rgb(230, 245, 255)
        Note over A,DB: Database Transaction
        A->>DB: SELECT ... FOR UPDATE (products)
        A->>A: Validate stock availability
        A->>DB: UPDATE products SET stock = stock - qty
        A->>DB: INSERT orders (status=PENDING)
        A->>DB: INSERT order_items
        DB-->>A: COMMIT
    end

    A->>Q: dispatch(ProcessOrderJob)->afterCommit()
    A->>R: DEL lock:product:{id} (Lua atomic)
    A-->>C: 201 Created

    rect rgb(245, 230, 255)
        Note over W,WS: Async Processing
        Q->>W: Dequeue job
        W->>DB: Load order (guard: PENDING)
        W->>DB: UPDATE status → PROCESSING
        W->>W: Simulate payment (50-200ms)
        alt Payment Success (80%)
            W->>DB: UPDATE status → PAID
            W->>WS: Broadcast OrderPaid
        else Payment Failure (20%)
            W->>DB: UPDATE status → FAILED
            W->>WS: Broadcast OrderFailed
        end
        WS-->>C: Push event (private channel)
    end
```

---

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
        redis[redis<br/>Redis 7<br/>:63790]
        worker[worker<br/>Supervisor<br/>2 processes]
        reverb[reverb<br/>WebSocket<br/>:8080]
    end

    nginx --> app
    app --> mysql
    app --> redis
    worker --> mysql
    worker --> redis
    worker --> reverb
    reverb --> redis

    style nginx fill:#fff3e0
    style app fill:#e8f5e9
    style mysql fill:#fce4ec
    style redis fill:#fff8e1
    style worker fill:#f3e5f5
    style reverb fill:#e0f2f1
```

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

    users ||--o{ orders : "places"
    orders ||--o{ order_items : "contains"
    products ||--o{ order_items : "referenced by"
```

---

## Queue & Worker Pipeline

```mermaid
flowchart TD
    A[Job dispatched<br/>afterCommit] --> B[Redis Queue]
    B --> C[Worker picks job]
    C --> D{Load Order}
    D --> |Not found| E[Log & exit]
    D --> |Found| F{Status == PENDING?}
    F --> |No| G[Idempotent skip]
    F --> |Yes| H[Transition → PROCESSING]
    H --> I[Simulate Payment<br/>50-200ms delay]
    I --> |80% success| J[Transition → PAID]
    I --> |20% failure| K[Transition → FAILED]
    J --> L[Broadcast OrderPaid]
    K --> M[Broadcast OrderFailed]
    L --> N[Done]
    M --> N

    style B fill:#fff8e1
    style J fill:#c8e6c9
    style K fill:#ffcdd2
```

---

*These diagrams are rendered natively on GitHub. For local viewing, use a Mermaid-compatible Markdown viewer or [mermaid.live](https://mermaid.live).*
