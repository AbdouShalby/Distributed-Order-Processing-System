# DOPS Refunds API Implementation Plan

> **For agentic workers:** Implemented by Codex under task contracts written by Claude (codex-handoff skill); Claude reviews every task before the next one starts, with extra scrutiny on the money path; Fable reviews the whole branch before the PR. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add refunds to DOPS so an agent service can refund paid orders safely: never more than was paid, idempotent per key, owner-checked, with human approval held in DOPS.

**Architecture:** A `refunds` table is the single source of truth for refunded money (nothing stored on the order; refunded and reserved amounts are derived with bcmath over locked rows). Every refund-mutating transaction starts by locking the order row; the simulated gateway is called by plain code after the transaction returns, and the outcome is written back under a new lock. New endpoints live under `/api/agent/*` behind a service-token middleware and a customer-ownership check; public endpoints are unchanged. Layering follows the repo: use cases in `app/Application/UseCases/<Name>/`, repository interfaces in `app/Domain`, Eloquent implementations in `app/Infrastructure/Persistence`, bindings in `DomainServiceProvider`.

**Tech Stack:** PHP 8.4, Laravel 12, MySQL 8 (integration), SQLite in-memory (unit/feature), bcmath, PHPUnit 11 with `#[Test]`, k6 `grafana/k6:1.0.0`.

**Spec:** `E:\Abdou\Memory\handoff\designs\2026-11-18-support-agent-design.md` (sections 7.1 and 7.7; 7.7 wins; route prefix `/api/agent` is a recorded deviation, see Delivery).

## Global Constraints

- Branch `feature/refunds-api` from `main`; one PR; no push or merge without Claude.
- Money: `decimal(10,2)` in MySQL; Eloquent cast `decimal:2`; decimal strings in PHP and JSON (`"12.50"`); arithmetic and comparison only with `bcadd`/`bcsub`/`bccomp` at scale 2. No floats, no `(float)`, no `round`, no SQL arithmetic or `SUM` on money.
- Amount input: a JSON string matching `/\A\d{1,8}(\.\d{1,2})?\z/`, greater than `0.00`; normalised to scale 2 (`"30"` -> `"30.00"`) before any use, including the fingerprint. JSON numbers are rejected.
- Refund statuses: `PENDING_APPROVAL`, `PENDING`, `SUCCEEDED`, `FAILED`, `REJECTED`. Reserved = `PENDING_APPROVAL` + `PENDING` + `SUCCEEDED`. Invariant: reserved <= order `total_amount`.
- Only `PAID` orders accept new refunds. When the `SUCCEEDED` sum equals `total_amount`, the order moves `PAID -> REFUNDED` by a conditional update (`where status = 'PAID'`), following `claimForCancellation` in `EloquentOrderRepository`.
- Auto ceiling (defense in depth): config `services.refunds.auto_approve_limit` (env `REFUND_AUTO_APPROVE_LIMIT`, default `"50.00"`). `mode: "auto"` executes only when reserved-after-this-request <= limit; otherwise the refund is stored `PENDING_APPROVAL`. An empty or malformed limit means every refund is held for approval.
- Transaction rule: the first statement of every refund transaction is the order `lockForUpdate`. For approve, reject and execute, read the refund's `order_id` before the transaction, then lock the order, then the refund row. Never call the gateway inside a transaction or through `afterCommit`.
- Service token: header `X-Service-Token`, config `services.agent.token` (env `AGENT_SERVICE_TOKEN`). Reject with 401 when the config is empty, before `hash_equals`. `X-Customer-Id` must match `/\A[1-9]\d*\z/`. An order not owned by that customer is 404, identical to a missing order, and is checked before any idempotency-key lookup.
- Error responses use the existing shape `{ "message": string, "error_code": string }`; reuse `IDEMPOTENCY_CONFLICT` and `IdempotencyConflictException` for key reuse; new codes: `UNAUTHORIZED`, `ORDER_NOT_REFUNDABLE`, `REFUND_EXCEEDS_PAID`, `REFUND_NOT_PENDING_APPROVAL`; validation on agent routes returns `VALIDATION_ERROR` with the field errors (public routes keep Laravel's default shape). New exceptions get render entries in `bootstrap/app.php` before the catch-all.
- Never commit a real token. Tests set `config(['services.agent.token' => 'test-token-not-secret'])`; `.env.example` gets `AGENT_SERVICE_TOKEN=` and `REFUND_AUTO_APPROVE_LIMIT=50.00`; both compose files pass `AGENT_SERVICE_TOKEN` from `.env`.
- Public routes, their responses and all existing tests keep their behaviour. Pint and all 5 CI checks (including GitGuardian) stay green.
- Every refund row stores the request's `trace_id` (from `TraceIdMiddleware`).
- Rate limit: agent routes get their own named limiter `agent` (config `services.agent.rate_limit_per_minute`, default 600) instead of the shared 60/min API limit, because the agent calls from one IP.

## Review Focus

- Malformed amounts (`"0"`, `"-5"`, `"10.005"`, `"1e3"`, `"10\n"`, `10.5` as a JSON number) return 422 `VALIDATION_ERROR`; `"30"` is accepted and stored as `30.00`.
- A refund on another customer's order returns 404 `NOT_FOUND` whether or not the idempotency key already exists, and leaves no row.
- The same key with a different customer, normalised amount or mode returns 409 `IDEMPOTENCY_CONFLICT` without the original refund's data; the same key with a different `reason` only is an exact replay (reason is not part of the fingerprint).
- A refund left `PENDING` (gateway timeout, unavailable, any exception, or a crash between commit and the gateway call) stays reserved, and an exact replay re-runs execution through the stored provider key, completing it exactly once.
- Two concurrent creates with the same key, and two concurrent approvals of one refund, each produce one effect and no 500.

---

### Task 1: `REFUNDED` order status

**Files:**
- Modify: `app/Domain/Order/Enums/OrderStatus.php`, `app/Http/Controllers/OrderController.php` (status `in:` list in `index`), `README.md` (state-machine section near line 288)
- Modify test: `tests/Unit/Domain/Order/OrderStatusTest.php` (existing; its providers currently assert `PAID` is terminal)

**Interfaces:**
- Produces: `OrderStatus::REFUNDED`; `PAID->canTransitionTo(REFUNDED) === true`; `PAID->canTransitionTo(CANCELLED) === false`; `REFUNDED->isTerminal() === true`; `PAID->isTerminal() === false`; `REFUNDED` cannot transition anywhere.

- [ ] **Step 1:** Update the existing providers and add cases for the interfaces above.
- [ ] **Step 2:** Run `php vendor/bin/phpunit --filter OrderStatusTest`. Expected: FAIL (`REFUNDED` undefined).
- [ ] **Step 3:** Add the case and transitions; add `REFUNDED` to the `index` filter. Do not touch worker recovery (it only handles `PROCESSING`; `isTerminal()` has no other callers).
- [ ] **Step 4:** Run the full suite. Expected: all pass; record the new total.
- [ ] **Step 5:** Commit `feat: add REFUNDED order status`.

### Task 2: Refund storage and simulated refund operation

**Files:**
- Create: `database/migrations/2026_11_18_000001_create_refunds_table.php`, `database/migrations/2026_11_18_000002_add_operation_to_simulated_payment_operations.php`, `app/Models/Refund.php`, `app/Domain/Refund/Contracts/RefundRepositoryInterface.php`, `app/Domain/Refund/RefundAmounts.php`, `app/Infrastructure/Persistence/Repositories/EloquentRefundRepository.php`
- Modify: `app/Domain/Payment/Contracts/PaymentGatewayInterface.php`, `app/Infrastructure/PaymentGateway/SimulatedPaymentGateway.php`, `config/services.php`, `app/Providers/DomainServiceProvider.php`
- Test: `tests/Unit/Domain/Refund/RefundAmountsTest.php`, `tests/Feature/SimulatedRefundGatewayTest.php`

**Interfaces:**
- Table `refunds`: `id`, `order_id` (FK), `customer_id` unsigned big int, `amount` decimal(10,2), `reason` string(500), `mode` string(20), `status` string(20), `idempotency_key` string(255) unique, `request_fingerprint` char(64), `provider_idempotency_key` string(255) unique, `trace_id` string(64) nullable, `decided_by` string(100) nullable, `decision_reason` string(500) nullable, `decided_at` timestamp nullable, `failure_message` string(255) nullable, timestamps; index (`order_id`, `status`), index (`customer_id`, `created_at`).
- Column `simulated_payment_operations.operation` string(20) default `'charge'`.
- `PaymentGatewayInterface::refund(int $orderId, string $amount, string $providerIdempotencyKey): PaymentResult`; the ledger mismatch check also compares `operation`; config `services.simulated_payment.refund_force_outcome` (env `SIMULATED_REFUND_FORCE_OUTCOME`, default `success`, allowed `success`|`decline`); the existing timeout-after-persist switch applies to refunds.
- `RefundAmounts` (pure, no DB): `normalise(string $amount): string`, `reserved(array $refunds): string` (bcadd over rows with reserved statuses), `succeeded(array $refunds): string`, `fitsCap(string $reserved, string $amount, string $total): bool`, `withinAutoLimit(string $reservedAfter, ?string $limit): bool` (false when limit empty or malformed).
- `RefundRepositoryInterface`: `findByKey(string $key): ?Refund`, `forOrderLocked(int $orderId): array` (call only after the order lock), `create(array $attributes): Refund`, `lockById(int $id): ?Refund`, `save(Refund $refund): void`, `pendingApproval(int $limit): array`, `forCustomerSince(int $customerId, \DateTimeInterface $since): array`.

- [ ] **Step 1: Write the failing tests**

```php
// RefundAmountsTest
#[Test] public function normalises_to_scale_two(): void { $this->assertSame('30.00', RefundAmounts::normalise('30')); }
#[Test] public function reserved_counts_pending_approval_pending_and_succeeded_only(): void
#[Test] public function cap_allows_exact_total_and_rejects_one_cent_more(): void
#[Test] public function malformed_or_empty_auto_limit_never_allows_auto(): void
// SimulatedRefundGatewayTest
#[Test] public function refund_is_recorded_as_refund_and_replays_without_second_side_effect(): void // side_effect_count 1, request_count 2
#[Test] public function a_charge_key_cannot_be_reused_for_a_refund(): void
#[Test] public function forced_refund_decline_is_not_successful(): void
```

- [ ] **Step 2:** Run both filters. Expected: FAIL.
- [ ] **Step 3:** Implement migrations, model (`amount` cast `decimal:2`), `RefundAmounts`, repository and binding, gateway `refund()`.
- [ ] **Step 4:** Full suite. Expected: all pass.
- [ ] **Step 5:** Commit `feat: add refund storage and simulated refund operation`.

### Task 3: Service token, agent rate limit and ownership

**Files:**
- Create: `app/Http/Middleware/RequireServiceToken.php`, `app/Http/Controllers/Agent/AgentOrderController.php`
- Modify: `bootstrap/app.php` (middleware alias `service.token`, named limiter `agent`, render entries), `config/services.php` (`agent.token`, `agent.rate_limit_per_minute`, `refunds.auto_approve_limit`), `.env.example`, `docker-compose.yml`, `docker-compose.integration.yml`, `routes/api.php` (group prefix `agent`, middleware `service.token`, `throttle:agent`), `app/Domain/Order/Contracts/OrderRepositoryInterface.php` and its Eloquent implementation (`lockForCustomer`)
- Test: `tests/Feature/Agent/AgentAuthAndOwnershipTest.php`

**Interfaces:**
- `OrderRepositoryInterface::lockForCustomer(int $orderId, int $customerId): ?Order` (domain entity; `lockForUpdate`; null when missing or not owned; call inside a transaction).
- `GET /api/agent/orders` (customer's orders, newest first, max 20) and `GET /api/agent/orders/{id}`; response `data` = existing order DTO plus `refunded_amount` and `reserved_amount` (decimal strings).

- [ ] **Step 1: Write the failing tests**

```php
#[Test] public function missing_or_wrong_token_is_401(): void
#[Test] public function empty_token_config_rejects_even_an_empty_header(): void
#[Test] public function other_customers_order_is_404_identical_to_missing(): void   // compare full JSON bodies
#[Test] public function invalid_customer_header_is_422(): void                       // missing, "0", "abc", "1 "
#[Test] public function public_routes_are_unchanged(): void                          // GET /api/orders/{id} without token still 200
```

- [ ] **Step 2:** Run `--filter AgentAuthAndOwnershipTest`. Expected: FAIL.
- [ ] **Step 3:** Implement as specified.
- [ ] **Step 4:** Full suite. Expected: all pass.
- [ ] **Step 5:** Commit `feat: add agent routes with service token and ownership checks`.

### Task 4: Create, read and execute refunds

**Files:**
- Create: `app/Application/UseCases/CreateRefund/CreateRefundUseCase.php`, `app/Application/UseCases/ExecuteRefund/ExecuteRefundUseCase.php`, `app/Application/Refunds/RefundFingerprint.php`, `app/Domain/Refund/Exceptions/{RefundExceedsPaidException,OrderNotRefundableException,RefundNotPendingApprovalException}.php`, `app/Http/Requests/CreateRefundRequest.php`, `app/Http/Controllers/Agent/AgentRefundController.php` (`store`, `indexForOrder`, `show`)
- Modify: `routes/api.php`, `bootstrap/app.php` (renders)
- Test: `tests/Unit/Application/CreateRefundDecisionTest.php` (pure decision logic with fakes), `tests/Feature/Agent/CreateRefundTest.php`

**Interfaces:**
- `POST /api/agent/orders/{id}/refunds` body `{ "amount": "30.00", "reason": string(1..500), "idempotency_key": string(8..255), "mode": "auto"|"needs_approval" }` -> 201 new, 200 exact replay, 404, 409 `IDEMPOTENCY_CONFLICT`, 422 `VALIDATION_ERROR`|`ORDER_NOT_REFUNDABLE`|`REFUND_EXCEEDS_PAID`. `data`: `{id, order_id, amount, reason, mode, status, decided_by, decision_reason, trace_id, created_at, updated_at}`.
- `GET /api/agent/orders/{id}/refunds` (newest first) and `GET /api/agent/refunds/{id}` (customer-scoped via `X-Customer-Id`).
- `RefundFingerprint::of(int $orderId, int $customerId, string $normalisedAmount, string $mode): string` (sha256 hex of canonical JSON; `reason` excluded).
- `ExecuteRefundUseCase::execute(int $refundId): Refund`.
- Create algorithm: transaction { lock order for customer (404 if null) ; `findByKey` -> if found: fingerprint equal ? replay (if `PENDING`, run execute after the transaction) : 409 ; order `PAID` else 422 ; rows = `forOrderLocked` ; cap check else 422 ; status = mode `auto` and `withinAutoLimit` ? `PENDING` : `PENDING_APPROVAL` ; insert with `provider_idempotency_key = "refund-" . Str::uuid()` and `trace_id` }. On a duplicate-key error from the insert (concurrent same key on another order), re-read by key and return replay or 409 (portable check: catch `UniqueConstraintViolationException`). After the transaction, if `PENDING`: execute.
- Execute algorithm: read `order_id` ; call `refund()` with the stored provider key (plain code, no transaction) ; transaction { lock order, then refund ; if refund still `PENDING`: success -> `SUCCEEDED`, then if succeeded sum == total, conditional `PAID -> REFUNDED`; decline -> `FAILED` with message } ; any exception from the gateway: leave `PENDING`, log with trace id, return it.

- [ ] **Step 1: Write the failing tests**

```php
#[Test] public function small_auto_refund_succeeds_and_is_readable(): void         // 201 SUCCEEDED; GET show and list; refunded_amount "20.00"
#[Test] public function exact_replay_returns_200_same_id_even_with_new_reason(): void
#[Test] public function amount_30_and_30_00_are_the_same_request(): void
#[Test] public function key_reuse_with_different_amount_or_mode_is_409_without_data(): void
#[Test] public function other_customer_gets_404_even_for_an_existing_key(): void
#[Test] public function auto_above_ceiling_is_held_without_gateway_call(): void      // 80.00 auto -> PENDING_APPROVAL; no simulated_payment_operations row
#[Test] public function split_auto_refunds_are_held_after_ceiling(): void            // 30.00 + 30.00 auto -> second PENDING_APPROVAL
#[Test] public function refund_exceeding_total_is_422(): void
#[Test] public function unpaid_cancelled_or_refunded_orders_are_not_refundable(): void
#[Test] public function full_refund_moves_order_to_refunded(): void
#[Test] public function declined_refund_releases_the_reservation(): void
#[Test] public function timed_out_refund_stays_pending_reserved_and_replay_completes_it_once(): void
#[Test] public function malformed_amounts_are_422(): void                            // Review Focus list
```

- [ ] **Step 2:** Run `--filter CreateRefund`. Expected: FAIL.
- [ ] **Step 3:** Implement as specified.
- [ ] **Step 4:** Full suite and `pint --test`. Expected: PASS.
- [ ] **Step 5:** Commit `feat: create, read and execute refunds with reservation and replay`.

### Task 5: Approve, reject, Ops queue, velocity data, reconcile

**Files:**
- Create: `app/Application/UseCases/DecideRefund/DecideRefundUseCase.php`, `app/Http/Requests/DecideRefundRequest.php`
- Modify: `app/Http/Controllers/Agent/AgentRefundController.php` (`approve`, `reject`, `queue`, `forCustomer`), `routes/api.php`, `routes/console.php` (`refunds:reconcile {refundId}`)
- Test: `tests/Feature/Agent/DecideRefundTest.php`

**Interfaces:**
- `POST /api/agent/refunds/{id}/approve` `{ "decided_by": string(1..100) }`; `POST /api/agent/refunds/{id}/reject` `{ "decided_by", "reason": string(1..500) }`; service token, no customer header; 200, 404, 409 `REFUND_NOT_PENDING_APPROVAL`.
- `GET /api/agent/refunds?status=PENDING_APPROVAL` (Ops queue, oldest first, max 50) and `GET /api/agent/customers/{customerId}/refunds?since=<ISO-8601>` (velocity data).
- Approve: read `order_id` ; transaction { lock order, then refund ; must be `PENDING_APPROVAL` ; set `PENDING`, `decided_by`, `decided_at` } ; execute. Reject: same locks ; set `REJECTED` with reason.
- `refunds:reconcile {refundId}`: for a `PENDING` refund, calls `ExecuteRefundUseCase::execute`.

- [ ] **Step 1: Write the failing tests**

```php
#[Test] public function approve_executes_once_and_records_decider(): void
#[Test] public function second_approve_is_409(): void
#[Test] public function reject_releases_the_reservation_and_is_final(): void
#[Test] public function queue_lists_only_pending_approval_oldest_first(): void
#[Test] public function customer_refunds_since_filters_by_time_and_customer(): void
#[Test] public function reconcile_completes_a_timed_out_refund_exactly_once(): void
```

- [ ] **Step 2:** Run `--filter DecideRefundTest`. Expected: FAIL.
- [ ] **Step 3:** Implement as specified.
- [ ] **Step 4:** Full suite and Pint. Expected: PASS.
- [ ] **Step 5:** Commit `feat: approve, reject, queue and reconcile refunds`.

### Task 6: Real-MySQL concurrency proof, k6, CI and docs

**Files:**
- Modify: `tests/Integration/Support/IntegrationHttpClient.php` (optional headers argument), `tests/Integration/IntegrationTestCase.php` (truncate `refunds`), `tests/Integration/Fixtures/concurrent_actor.php` (refund create and approve actors)
- Create: `tests/Integration/RefundConcurrencyTest.php`, `database/seeders/RefundDemoSeeder.php`, `load-tests/refund-race-test.js`
- Modify: `.github/workflows/ci.yml` (job running the MySQL integration suite with the compose file; build cache allowed), `load-tests/results.md`, `README.md` (Refunds section, endpoints, threat notes), `docs/architecture.md`

- [ ] **Step 1: Write the failing integration tests (real MySQL, separate PHP processes)**

```php
#[Test] public function parallel_refunds_never_exceed_the_paid_total(): void    // 100.00 order, 10 actors x 30.00 (mixed modes) -> 3 accepted, reserved <= 100.00
#[Test] public function parallel_same_key_creates_produce_one_refund(): void    // 5 actors, same key -> one row, others 200 replay, no 500
#[Test] public function parallel_double_approval_executes_once(): void          // one 200, others 409, one gateway side effect
```

Each test ends with the invariant check: for every order, reserved <= total (bcmath over rows).

- [ ] **Step 2:** Run `make test-integration`. Expected: PASS; then remove the order `lockForUpdate` in a scratch change and confirm at least the first test FAILS; restore it.
- [ ] **Step 3:** `RefundDemoSeeder` creates one `PAID` order of `100.00` for user 1. Order of operations: `migrate:fresh --seed`, then `db:seed --class=RefundDemoSeeder`, then `redis-cli FLUSHALL`, then k6. `refund-race-test.js`: 50 VUs, one `needs_approval` refund of `10.00` each with a unique key; token and customer id from `__ENV`; thresholds `refunds_accepted == 10`, `refunds_exceed == 40`, `unexpected == 0`; finish with a DB sum check.
- [ ] **Step 4:** Run k6 locally; append the verified result (date, commit, counts, sum check) to `load-tests/results.md`; add the CI integration job; update README and architecture docs.
- [ ] **Step 5:** Full suite, integration suite, Pint; commit `test: prove refund invariants under real concurrency`, `ci: run MySQL integration suite`, `docs: document refunds API`.

---

## Delivery

- Claude reviews each task's diff before the next contract; Fable reviews the whole branch before the PR.
- Contract text for every task includes: no query before the order lock in a refund transaction; gateway called only after the transaction returns, never via `afterCommit`; no change to public routes, responses or existing tests; no floats or SQL money arithmetic; empty token config rejected before `hash_equals`; `X-Customer-Id` pattern; the mutation check on concurrency tests.
- Record in the design that agent endpoints use the `/api/agent` prefix.
- PR to `main` listing endpoints, invariants, tests and the k6 result; merge only after CI is green and Abdou approves.
