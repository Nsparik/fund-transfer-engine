# Design Considerations & Future Enhancements

## 1. Engineering Intent

### Why a ledger model instead of mutable balances

The simplest implementation of a transfer is two UPDATE statements — subtract from source, add to destination. That works until you need to answer questions like "what was this account's balance at 14:32 last Tuesday?" or "which transfer caused this discrepancy?". You can't reconstruct history from a single `balance` column.

The ledger here is append-only. Every completed transfer writes exactly two rows — a DEBIT on the source account and a CREDIT on the destination — each carrying `balance_after_minor_units` as a point-in-time snapshot. The `accounts` table still holds a running balance for fast reads, but the ledger is the authoritative record. If those two numbers ever diverge, the reconciliation CLI (`app:reconcile-balances`) will tell you precisely which account is out of sync and by how much. That property is non-negotiable in financial systems.

The `uidx_ledger_account_transfer_type` unique constraint on `(account_id, transfer_id, entry_type)` is also load-bearing: it means the handler can be retried (for any reason — crash, timeout, at-least-once delivery from a future queue) without producing duplicate ledger entries. The `INSERT IGNORE` against that constraint is the idempotency mechanism at the storage layer.

### Why correctness was prioritised over premature scalability

The target for this exercise is a well-reasoned implementation of money movement, not a distributed systems design. Every decision here was made to make correct behaviour provable and easy to verify: strong types, integer minor units to eliminate floating-point precision issues, a `Balance` value object that throws on currency mismatch, domain invariants enforced inside the aggregate before any persistence, and a test suite that directly verifies financial invariants (zero-sum balances across all accounts, no overdraft, reversal exactness).

Optimising for throughput before correctness is the wrong order of operations. Get the invariants right first, then introduce the complexity needed to scale them.

### Why MySQL is the source of truth, not Redis

Redis handles rate limiting (sliding window, 10 req/min per IP via `Symfony\RateLimiter`) and is intentionally configured fail-open: if Redis is unavailable, the request proceeds. This is a deliberate trade-off documented in the controller — rate limiting is an abuse-prevention measure, and a Redis outage must not block legitimate financial transactions. MySQL enforces idempotency, balance correctness, and the double-entry constraint. If Redis goes down, you get slightly more requests through. If MySQL goes down, you stop. That ordering of authority is correct for a payment system.

### Why a modular monolith

The three modules — Account, Transfer, Ledger — have clearly defined boundaries enforced by ports and adapters. The Transfer handler imports no Account or Ledger domain types; it communicates through `AccountTransferPort` and `LedgerEntryRecorderPort`, both defined in `Shared/Application/Port`. Cross-module data crosses the boundary as primitive DTOs (`DoubleEntryResult`), not domain objects.

This structure was chosen because the codebase needed to be extractable into services without a rewrite, but it did not need to be microservices yet. The complexity budget for this exercise did not include distributed transactions, service discovery, or network fault tolerance — all of which become mandatory once you split across process boundaries. A modular monolith gives you the bounded context discipline without that operational overhead.

---

## 2. Trade-offs Made

**No authentication layer.** Auth was excluded deliberately. Adding JWT verification or an API gateway would introduce configuration surface area and token management concerns that obscure what this exercise is actually demonstrating — the money-movement mechanics. Any reviewer evaluating the transfer logic should not have to reason through auth middleware to follow the critical path.

**No async messaging infrastructure.** The Outbox pattern is fully implemented: domain events are written atomically to `outbox_events` inside the transfer transaction, and `ProcessOutboxCommand` polls and dispatches them via Symfony's event dispatcher. What is not yet wired is a real broker. The processor currently dispatches to in-process listeners, which is correct behaviour for this scope but would need to be replaced with a Kafka or RabbitMQ publisher in production.

**No multi-currency engine.** Currency is stored and enforced (a GBP account cannot receive a USD credit), but there is no FX rate lookup, no rate-locking at transaction time, and no cross-currency settlement logic. Adding this is a well-understood problem — lock a rate at initiation, store it on the transfer row, apply it during the double-entry — but it would have doubled the complexity of the domain model without demonstrating anything new about correctness under concurrency.

**No horizontal sharding strategy.** All accounts live in one MySQL instance. Shard-level consistency across accounts in different shards requires either two-phase commit (expensive and operationally fragile) or a saga with compensating transactions (complex). Neither is appropriate to introduce here.

**Limited observability stack.** Structured JSON logging with `correlation_id` and `transfer_id` on every log entry is in place. What is missing is a metrics exporter (Prometheus) and distributed tracing (OpenTelemetry). The structured logs give you enough signal to correlate failures in development; in production you would need span-level visibility.

---

## 3. What I Would Add Next in a Real Production System

**Outbox → broker integration.** Replace the in-process dispatcher in `OutboxProcessor` with a Kafka or RabbitMQ producer. The outbox contract is already defined — `OutboxEventSerializerInterface` handles serialization, and `markPublished` / `markFailed` with retry counting and dead-lettering are already implemented. The integration point is one class.

**Read-model projections for balance queries.** Currently `GET /accounts/{id}` reads the running balance from the `accounts` table directly. At scale, balance reads would be served from a projection table (or Redis) populated by the Ledger module's domain events, which decouples read throughput from the write-locked account rows.

**Multi-currency and FX rate locking.** Lock a rate at initiation, store `fx_rate`, `source_amount`, `destination_amount`, and `settlement_currency` on the transfer row. The double-entry then records both sides in their native currencies. This needs careful schema design — `ledger_entries.amount_minor_units` is currency-specific, so multi-currency entries require storing both amounts.

**Idempotency enforcement at the API gateway.** The current implementation enforces idempotency at the application layer (Symfony event subscriber). In a horizontally scaled deployment this is correct but relies on the database as the coordination point. Moving idempotency enforcement upstream to an API gateway (Kong, AWS API Gateway) reduces DB load for retries.

**SLO-driven observability.** P99 latency on `POST /transfers`, error rate by domain code, deadlock retry rate, outbox backlog depth, and idempotency key cardinality growth are the metrics that matter. Without them you cannot set or enforce SLOs.

**Scheduled reconciliation job.** The `ReconcileBalancesCommand` exists and is correct, but it is a manual one-shot CLI command. In production it should run on a schedule (every 15 minutes, or continuously with a short sleep loop), alert on any mismatch exceeding a threshold, and write results to a `reconciliation_runs` table for audit.

---

## 4. Scalability Path

The current design scales incrementally without re-architecture because the module boundaries are already drawn correctly.

The first scale step is read replicas. MySQL read replicas can serve `GET /transfers`, `GET /accounts/:id`, and ledger statement queries without touching the primary. The only required code change is injecting a read-only connection for query handlers — the port interfaces already separate reads from writes.

The second step is extracting the Ledger module into a separate service. Because the Transfer handler interacts with the Ledger exclusively through `LedgerEntryRecorderPort`, this extraction is a matter of replacing the in-process adapter with an HTTP or gRPC client. No domain logic changes. The outbox already publishes ledger-relevant domain events (`AccountDebited`, `AccountCredited`) that could drive the extracted service's write path.

The third step — if account-level throughput becomes the bottleneck — is sharding the `accounts` table by `account_id`. The alphabetical lock ordering in `AccountTransferService` (`executeDoubleEntry`) already prevents AB/BA deadlocks within a shard. Cross-shard transfers would require a saga, which is the point at which the modular monolith boundary becomes a service boundary.

Distributed transactions are deliberately absent. Every operation that needs to be atomic is either a single MySQL transaction or an outbox-backed eventual consistency pattern. That constraint is what allows the system to scale without rewriting correctness guarantees.

---

## 5. Risk Areas to Watch in Production

**Deadlocks under extreme concurrency.** Lock ordering (alphabetical UUID) prevents AB/BA deadlocks for the same account pair, but MySQL can still deadlock on gap locks and insert-intention locks at high insert rates. The `DbalTransactionManager` retries up to three times with randomised exponential backoff, which handles transient spikes. If the deadlock retry rate appears in metrics consistently above 1%, it indicates a systemic lock-ordering problem that warrants investigation rather than more retries.

**Idempotency key cardinality growth.** The `idempotency_keys` table grows with every unique transfer request. `PruneIdempotencyKeysCommand` deletes expired rows, but it must be run regularly. Without it, the table grows unbounded and index performance degrades. Monitor row count and ensure the prune job is scheduled.

**Schema migration discipline.** The `ledger_entries` table is append-only. You can add nullable columns or new indexes safely. You cannot change column types on `amount_minor_units` or `balance_after_minor_units` without a full table rewrite, which is a blocking operation on large tables in MySQL without online DDL tooling (pt-online-schema-change or gh-ost). All future migrations must be reviewed with this constraint in mind.

**Outbox backlog.** If `ProcessOutboxCommand` falls behind — due to a downstream consumer being slow or unavailable — the `outbox_events` table accumulates unprocessed rows. Events with `attempt_count >= 5` are dead-lettered (logged at CRITICAL, not deleted). Operators must monitor `SELECT COUNT(*) FROM outbox_events WHERE published_at IS NULL` and have a runbook for replaying dead-lettered events.

---

## 6. What This Exercise Demonstrates

Modelling money correctly is harder than it looks. The failure modes — concurrent overdraft, duplicate debits on retry, phantom balance from a crashed mid-transaction, stale reads during a reversal — are not theoretical. They happen in production when the implementation does not take them seriously from the start.

This implementation shows that the constraints are understood: integer minor units prevent floating-point drift; pessimistic locking with deadlock-safe ordering prevents concurrent overdraft; the outbox with idempotency constraints at the ledger layer prevents duplicate entries on retry; the reversal handler acquires a `FOR UPDATE` lock on the transfer row to prevent two concurrent reversals both seeing COMPLETED.

The module structure demonstrates that bounded contexts can be maintained in a single codebase without the operational complexity of microservices. The port interfaces are not ceremony — they are the extraction seam that allows the Account or Ledger module to become a separate service without touching the Transfer module's logic.

The test suite (472 tests) covers financial invariants directly: zero-sum balance checks across account pairs, reversal exactness, idempotency under concurrent first-requests, and rate limiter behaviour under Redis failure. These are not unit tests that mock everything — they are integration tests that run against a real MySQL instance and verify the actual database state after each operation.

The goal was not to build a production payment system in a take-home exercise. The goal was to demonstrate knowing where the complexity belongs, which parts to implement rigorously, and which parts to scope out cleanly with a clear rationale for each decision.
