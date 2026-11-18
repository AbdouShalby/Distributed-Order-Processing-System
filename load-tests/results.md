# Load-Test Results

These are correctness results from a real local run. They show that the
system does not oversell and does not create duplicate orders under
concurrent load. They are not a performance benchmark: the run used Docker
Desktop on a Windows workstation with the source bind-mounted into the
containers, so response times are not representative and are not reported.

## Run

| Item | Value |
|------|-------|
| Date | 2026-11-18 |
| Code | commit `fd116be` (application code; the run used the same tree before an unrelated integration-compose change), exported with `git archive` |
| Stack | `docker compose` (Nginx, PHP 8.4 FPM, MySQL 8.0, Redis 7, queue worker, scheduler, Reverb) |
| Load generator | `grafana/k6:1.0.0`, run in a container on the compose network |
| Reset before each test | `php artisan migrate:fresh --seed` and `redis-cli FLUSHALL` |

The API is rate limited to 60 requests per minute per client, and the
counters live in Redis. Run the two tests at least a minute apart, or clear
Redis between them, or the second test will see `429` responses.

Commands, from the repository root, with the stack running:

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec redis redis-cli FLUSHALL
docker run --rm --network <compose-project>_dops-network \
  -e BASE_URL=http://nginx -v "$(pwd)/load-tests:/scripts:ro" \
  grafana/k6:1.0.0 run /scripts/oversell-test.js
```

Run `idempotency-test.js` the same way after another reset. Find the network
name with `docker network ls`.

## Oversell test (`oversell-test.js`)

50 virtual users try to buy product 5 ("Limited Edition Headphones"), which
is seeded with stock 1. Each request has its own idempotency key.

| Result | Value |
|--------|-------|
| Orders created (`201`) | 1 |
| Orders rejected (`409`) | 49 |
| Unexpected responses | 0 |
| Stock after the run (MySQL) | 0 |
| `order_items` rows for product 5 | 1 |
| k6 verdict | PASS |

## Idempotency test (`idempotency-test.js`)

50 virtual users send the same order with the same idempotency key at the
same time.

| Result | Value |
|--------|-------|
| Orders created (`201`) | 1 |
| Replays of the original order (`200`) | 49 |
| Unexpected responses | 0 |
| Orders with this key (MySQL) | 1 |
| `idempotency_requests` rows | 1 |
| Stock of product 1 after the run | 49 (seeded 50, one unit sold) |
| k6 verdict | PASS |

## Refund race test (`refund-race-test.js`)

Run on 2026-11-18 against commit `0c8607d` (branch `feature/refunds-api`), same
local Docker setup and `grafana/k6:1.0.0`. Reset: `migrate:fresh --seed`, then
`db:seed --class=RefundDemoSeeder` (one `PAID` order of `100.00`), then
`redis-cli FLUSHALL`. 50 virtual users each request a `needs_approval` refund
of `10.00` with a unique idempotency key at the same moment.

| Result | Value |
|--------|-------|
| Refunds accepted (`201`, held for approval) | 10 |
| Rejected `422 REFUND_EXCEEDS_PAID` | 40 |
| Unexpected responses | 0 |
| Refund rows for the order (MySQL) | 10 |
| Sum of refund amounts (MySQL) | 100.00 (equals the paid total) |
| k6 verdict | PASS |

The same invariant is covered by `tests/Integration/RefundConcurrencyTest.php`
(real MySQL, separate PHP processes), which fails when the order row lock is
removed.

## Not covered by this run

`high-load-test.js` and the worker retry and payment scenarios were not
re-run here. Earlier figures for them were not reproducible (no raw output,
commit or environment was kept) and were removed. The concurrency and
crash-window behaviour is covered by the PHPUnit suite against real MySQL
and Redis.
