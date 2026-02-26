# Fund Transfer Engine

A production-grade, ledger-based fund transfer API built with **PHP 8.2 / Symfony 7.4 / MySQL 8.0 / Redis 7**.

Implements double-entry bookkeeping, idempotent money movement, pessimistic concurrency control, and a transactional outbox — all within a single deployable modular monolith designed for microservice extraction.

---

## Table of Contents

- [Tech Stack](#tech-stack)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Running the Application](#running-the-application)
- [Running Tests](#running-tests)
- [Architecture](#architecture)
- [API Reference](#api-reference)
- [Design Decisions](#design-decisions)
- [Roadmap](#roadmap)
- [Time Spent](#time-spent)
- [AI Usage Disclosure](#ai-usage-disclosure)

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Language | PHP 8.2 |
| Framework | Symfony 7.4 |
| Database | MySQL 8.0 (InnoDB, READ-COMMITTED isolation) |
| Cache / Rate-limiter | Redis 7 |
| ORM / Query | Doctrine DBAL 3.9 (raw DBAL — no ORM, explicit control) |
| HTTP Server | Apache 2.4 + PHP-FPM |
| Containerisation | Docker + Docker Compose v2 |
| Tests | PHPUnit 11 — Unit / Integration / Functional |

---

## Prerequisites

PHP, Composer, and MySQL run inside containers. The only software you need installed on your machine is:

| Requirement | Minimum version | Install guide |
|---|---|---|
| **Docker Engine** | 24.0 | [docs.docker.com/engine/install](https://docs.docker.com/engine/install/) |
| **Docker Compose** | v2.20 (plugin, not standalone) | Bundled with Docker Desktop; Linux: [docs.docker.com/compose/install](https://docs.docker.com/compose/install/linux/) |
| **Git** | any recent | [git-scm.com](https://git-scm.com/downloads) |
| **curl** | any | Pre-installed on macOS and most Linux distros |

> **Docker Desktop (macOS / Windows)** installs both Docker Engine and the Compose plugin in one step.
> Go to [docs.docker.com/desktop](https://docs.docker.com/desktop/) and follow the installer for your OS.

### Verify your install before proceeding

```bash
docker --version
# Docker version 24.x.x or higher

docker compose version
# Docker Compose version v2.x.x  (must say "v2", not "docker-compose 1.x")

git --version
# git version 2.x.x
```

If `docker compose version` returns an error, you have the old standalone `docker-compose` v1. Follow the [migration guide](https://docs.docker.com/compose/migrate/) — all Makefile targets use `docker compose` (v2 plugin syntax).

> **Linux users:** after installing Docker Engine, add your user to the `docker` group so you can run Docker without `sudo`:
> ```bash
> sudo usermod -aG docker $USER
> newgrp docker          # apply without logging out
> docker run hello-world # confirm it works
> ```
> Then run `id -u` and `id -g` and set those values as `APP_UID` / `APP_GID` in your `.env` to avoid volume permission issues.

---

## Installation

```bash
# 1. Clone the repository
git clone <repo-url>
cd fund-transfer-engine

# 2. Create your local environment file
cp .env.example .env
# Edit .env — replace all change_me_* placeholders.
# Values must be internally consistent: MYSQL_PASSWORD must match DATABASE_URL,
# REDIS_PASSWORD must match REDIS_URL.
# Linux only: also set  APP_UID=$(id -u)  and  APP_GID=$(id -g)

# 3. Build Docker images and start all containers
make build
make up

# 4. Wait ~30 s on first run, then confirm all services are healthy
make ps
# Expected: fund_apache, fund_php, fund_mysql, fund_redis — all "healthy"

# 5. Install Composer dependencies
make composer-install

# 6. Run database migrations
make migrate

# 7. Smoke-test the API
curl -s http://localhost:8080/transfers
# → {"data":{"items":[],"total":0,"page":1,"perPage":25,"totalPages":0}}
```

---

## Configuration

All runtime configuration lives in `.env` (gitignored). Use `.env.example` as the authoritative template — every variable is documented inline.

| Variable | Purpose |
|----------|---------|
| `APP_ENV` | Symfony environment — `dev` / `prod` / `test` |
| `APP_SECRET` | 32-char hex secret — generate with `openssl rand -hex 32` |
| `APACHE_PORT` | Host port Apache binds to (default `8080`) |
| `MYSQL_*` | Root password, database name, app user credentials |
| `DATABASE_URL` | Doctrine DBAL DSN — must match `MYSQL_*` values |
| `REDIS_PASSWORD` | Redis auth password |
| `REDIS_URL` | Redis DSN — must match `REDIS_PASSWORD` |
| `APP_UID` / `APP_GID` | Linux host UID/GID for volume file ownership |
| `TRUSTED_PROXIES` | Symfony proxy trust list — `127.0.0.1,REMOTE_ADDR` for local dev |

> **Network security:** MySQL and Redis expose **no host ports**. They are reachable only by the PHP container on the internal Docker bridge network.

---

## Running the Application

### Makefile targets

```bash
make up              # Start all containers in background
make down            # Stop containers (volumes preserved)
make down-v          # Stop containers AND destroy volumes — DESTRUCTIVE
make ps              # Show container status and health
make logs            # Stream logs from all containers
make shell           # Open bash inside the PHP container
make migrate         # Apply all pending migrations
make migrate-status  # Show applied / pending migration status
make migrate-rollback # Roll back the last applied migration
```

### CLI commands (run inside `make shell`)

```bash
# Process one batch of outbox events and exit
bin/console app:outbox:process --once

# Run as a long-running daemon (Supervisor-compatible)
bin/console app:outbox:process --sleep=1

# Requeue a specific dead-lettered outbox event for redelivery
bin/console app:outbox:requeue-dead-letters --id=<outbox-event-uuid>

# Reset ALL dead-lettered outbox events (attempt_count cleared, republished)
bin/console app:outbox:reset-dead-letters

# Reconcile all ledger balances against account snapshots
bin/console app:reconcile:balances

# Reconcile a single account
bin/console app:reconcile:balances --account-id=<uuid>

# Prune expired idempotency key records (schedule as nightly cron)
bin/console app:idempotency:prune
```

---

## Running Tests

Integration and functional tests require the full Docker stack to be running. Unit tests run standalone.

```bash
# Unit tests only — no database required
docker compose exec php php vendor/bin/phpunit --testsuite Unit

# Integration tests — requires MySQL container healthy
docker compose exec php php vendor/bin/phpunit --testsuite Integration

# Functional (HTTP) tests — requires full stack
docker compose exec php php vendor/bin/phpunit --testsuite Functional

# Full suite
docker compose exec php php vendor/bin/phpunit
```

**Baseline: 482 tests — all green.**

### Coverage by area

| Area | Type | What is verified |
|------|------|-----------------|
| Transfer atomicity | Integration | Debit, credit, ledger entries, outbox events all commit or all roll back |
| Failure paths | Integration | `FAILED` state persisted; balances unchanged on `InsufficientFunds`, `Frozen`, `CurrencyMismatch` |
| Ledger entries | Integration | Exactly 2 rows per transfer; `INSERT IGNORE` prevents duplicates |
| Transfer reversal | Integration | Original balances restored; `reversed_at` stamped; 4 total ledger rows |
| Outbox atomicity | Integration | All 4 domain events present after a successful transfer |
| Idempotency | Functional | Same key → cached 201; different body on same key → 422; missing key → 400 `IDEMPOTENCY_KEY_REQUIRED` |
| Rate limiting | Functional | 11th request within sliding window → 429 with `Retry-After` |
| API contracts | Functional | Status codes, error envelopes, `Location` headers, pagination |

---

## Architecture

The project is a **modular monolith** following Clean Architecture within each bounded context. Modules communicate through explicit PHP interface ports in `Shared/` — never through direct cross-module class imports.

```
src/
├── Module/
│   ├── Transfer/               Bounded context — Transfer aggregate
│   │   ├── Domain/             Aggregate, value objects, events, exceptions, repository interface
│   │   ├── Application/        Command/query handlers, request DTOs
│   │   ├── Infrastructure/     DbalTransferRepository, AccountTransferService
│   │   └── UI/                 TransferController
│   │
│   ├── Account/                Bounded context — Account aggregate
│   │   ├── Domain/             Account aggregate, Balance VO, AccountStatus enum
│   │   ├── Application/        CreateAccount, FreezeAccount, UnfreezeAccount, CloseAccount
│   │   ├── Infrastructure/     DbalAccountRepository
│   │   └── UI/                 AccountController
│   │
│   └── Ledger/                 Bounded context — immutable double-entry ledger
│       ├── Domain/             LedgerEntry aggregate, EntryType enum
│       ├── Application/        FindAccountStatement query handler
│       └── Infrastructure/     DbalLedgerRepository
│
└── Shared/
    ├── Application/Port/       AccountTransferPort, LedgerEntryRecorderPort,
    │                           TransactionManagerInterface, ReconciliationRepositoryInterface
    ├── Domain/                 Idempotency, Outbox domain objects
    ├── Infrastructure/         DbalTransactionManager, OutboxProcessor, MigrationRunner,
    │                           DbalIdempotencyRepository, DbalOutboxRepository
    └── UI/                     DomainExceptionListener, IdempotencySubscriber,
                                CorrelationIdSubscriber, ProcessOutboxCommand, ReconcileBalancesCommand
```

**Module boundary enforcement:** Transfer imports zero types from the Account domain. All account operations cross the boundary through `AccountTransferPort`. Extracting Transfer to a standalone microservice requires only replacing that interface's implementation — no domain or application code changes.

---

## API Reference

### Transfers

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/transfers` | Initiate a new transfer |
| `GET` | `/transfers/{id}` | Fetch a transfer by ID |
| `GET` | `/transfers?status=&page=&per_page=` | List transfers (paginated) |
| `POST` | `/transfers/{id}/reverse` | Reverse a completed transfer |

### Accounts

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/accounts` | Open a new account |
| `GET` | `/accounts/{id}` | Fetch an account |
| `POST` | `/accounts/{id}/freeze` | Freeze an active account |
| `POST` | `/accounts/{id}/unfreeze` | Unfreeze a frozen account |
| `POST` | `/accounts/{id}/close` | Close an account (balance must be zero) |
| `GET` | `/accounts/{id}/transfers` | List transfers for an account |
| `GET` | `/accounts/{id}/statement?from=&to=&page=&per_page=` | Paginated ledger statement |

### Request headers

| Header | Required | Description |
|--------|----------|-------------|
| `Content-Type` | Yes | `application/json` |
| `X-Idempotency-Key` | **Yes** (POST /transfers, POST /accounts) | Client-generated unique key (UUIDv4 recommended). Missing key returns 400 `IDEMPOTENCY_KEY_REQUIRED`. Optional on sub-resource mutations (`/freeze`, `/reverse`, etc). |
| `X-Correlation-ID` | No | Propagated to response header unchanged; generated server-side if absent. |

### Response envelope

```json
{ "data": { ... } }

{ "error": { "code": "TRANSFER_NOT_FOUND", "message": "Transfer not found." } }

{ "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "violations": [ { "field": "currency", "message": "This value is not valid." } ]
  }
}
```

### Domain error codes

| Code | HTTP | Trigger |
|------|------|---------|
| `IDEMPOTENCY_KEY_REQUIRED` | 400 | `POST /transfers` or `POST /accounts` missing `X-Idempotency-Key` header |
| `TRANSFER_NOT_FOUND` | 404 | Unknown transfer ID |
| `ACCOUNT_NOT_FOUND` | 404 | Unknown account ID |
| `INVALID_TRANSFER_STATE` | 409 | State machine violation (e.g. reversing a `FAILED` or already-`REVERSED` transfer) |
| `ACCOUNT_FROZEN` | 409 | Debit or credit attempted on a frozen account |
| `ACCOUNT_CLOSED` | 409 | Debit or credit attempted on a closed account |
| `INVALID_ACCOUNT_STATE` | 409 | Account status transition not permitted (e.g. unfreezing an active account) |
| `NON_ZERO_BALANCE_ON_CLOSE` | 409 | Account close attempted while balance > 0 — drain funds first |
| `INSUFFICIENT_FUNDS` | 422 | Source balance below transfer amount |
| `CURRENCY_MISMATCH` | 422 | Transfer currency does not match account currency |
| `SAME_ACCOUNT_TRANSFER` | 422 | Source and destination accounts are identical |
| `INVALID_DATE_RANGE` | 422 | `from >= to`, range > 366 days, or unparseable date on `/statement` |
| `IDEMPOTENCY_KEY_REUSE` | 422 | Same key submitted with a different request body |
| `RATE_LIMIT_EXCEEDED` | 429 | Rate limit exceeded — 10 req/min (transfers), 20 req/min (accounts), per IP |

---

## Design Decisions

### Double-entry ledger — not a balance column

Money does not move by updating a number. Every transfer writes two immutable `ledger_entries` rows — a debit on the source account and a credit on the destination. The account balance is derived from the most recent `balance_after_minor_units` snapshot; it is never the source of truth. This makes the ledger independently auditable and replayable without the `accounts` table.

### Integer minor-units throughout

All monetary values are stored and transmitted as 64-bit integers in the currency's smallest unit (cents for USD, pence for GBP). Floating-point precision errors are structurally impossible. `Balance::add()` guards against `PHP_INT_MAX` overflow with an explicit `OverflowException`.

### Pessimistic locking (`SELECT FOR UPDATE`)

Account rows are locked with `SELECT … FOR UPDATE` before any mutation. The transfer row is also locked in the reversal path. This serialises concurrent operations at the database level without retry logic in application code — a retry loop is unacceptable for a financial operation where re-validating funds has observable side effects.

### Deadlock prevention — alphabetical UUID lock order

`AccountTransferService` always acquires `FOR UPDATE` locks in ascending UUID lexicographic order, regardless of which account is source or destination. This eliminates AB/BA deadlocks when two concurrent transfers involve the same account pair in opposite directions. A bounded retry (3 attempts, randomised exponential backoff) handles rare MySQL-internal deadlocks.

### READ-COMMITTED isolation level

`docker/mysql/mysql.cnf` sets `transaction_isolation = READ-COMMITTED`. This avoids InnoDB gap locks that REPEATABLE-READ introduces on non-unique index scans, reducing deadlock surface on concurrent transfers to different account pairs. Since every safety-critical read uses explicit `FOR UPDATE`, the weaker isolation does not reduce financial consistency.

### Transactional outbox pattern

Domain events (`TransferInitiated`, `TransferCompleted`, `AccountDebited`, `AccountCredited`) are written to `outbox_events` inside the same database transaction as the business operation — never dispatched in-process after the commit. A separate `OutboxProcessor` daemon delivers them at-least-once. This guarantees no event is lost even if the PHP process dies immediately after commit. Workers use `SELECT … FOR UPDATE SKIP LOCKED` to process disjoint event batches without coordination.

### Two-layer idempotency

**HTTP layer:** `IdempotencySubscriber` stores a SHA-256 fingerprint of `(method|path|body)` under `X-Idempotency-Key`. Retries with the same key and body receive the cached response; a different body returns 422.

**Ledger layer:** `UNIQUE (account_id, transfer_id, entry_type)` with `INSERT IGNORE` prevents a duplicate debit or credit even if the handler is somehow reached twice. Neither layer alone is sufficient — both are required for defence in depth.

### Advisory lock migrations

`MigrationRunner` acquires `GET_LOCK('fund_transfer_migrations', 10)` before touching the schema. In rolling deployments where multiple PHP containers start simultaneously, only one runs migrations; the rest wait and fail with a clear error rather than racing on `CREATE TABLE`.

### UUIDv4 for accounts, UUIDv7 for transfers

Account IDs are externally visible and must not leak creation order — UUIDv4 is fully opaque. Transfer IDs are internal ledger records inserted at high volume — UUIDv7's time-ordered prefix minimises B-tree page splits.

---

## Roadmap

The scope of this build was deliberately focused on the transfer engine core — correct money movement, strong consistency guarantees, and a reliable outbox. Several production concerns were intentionally deferred. Below is an honest account of what I would tackle next, and why.

### Authentication & Authorisation

Every endpoint is currently unauthenticated. That was a conscious trade-off to keep the scope on the domain logic, not the auth layer. In a real deployment I would introduce JWT validation as a Symfony kernel middleware — before any controller is reached — and scope all reads to the authenticated caller. A customer should never be able to see another customer's account or transfers. I'd layer RBAC on top with three roles: `customer` (own resources only), `ops` (read anything), and `admin` (mutations and reversals). Keeping auth as middleware means the domain stays completely auth-provider agnostic.

One related gap: the idempotency key is currently global across all callers. It should be namespaced as `{caller_id}:{key}` so two different callers using the same UUID cannot collide or replay each other's responses.

### Reliability Gaps

Two things I'd close immediately in a production cut:

**Advisory lock on migrations** — `GET_LOCK()` is not compatible with connection pooling (ProxySQL / PgBouncer drops the connection context). The right approach is an `INSERT … ON DUPLICATE KEY UPDATE` into a dedicated `migrations_lock` table — atomic, pooling-safe, and no TCP session dependency.

**Crash-after-commit on account creation** — `POST /accounts` has no idempotency protection today. If the process dies after the DB commit but before the HTTP response is sent, a retry creates a duplicate account. The fix is a DB-level `UNIQUE` constraint on the idempotency key column with a `SELECT` on conflict — the same pattern already used for transfers.

I'd also replace `LIMIT/OFFSET` pagination with keyset pagination (`WHERE (created_at, id) < (:ts, :id)`) and expose a `next_cursor`. At scale, offset-based pagination does a full index scan on every deep page — unacceptable for a transfer history endpoint.

### Observability

Right now, debugging a production issue means grepping logs. That's not sustainable. I would add three things:

- A `/metrics` Prometheus endpoint exposing request latency histograms, outbox queue depth, dead-letter count, and idempotency hit/miss ratio. The outbox depth alone is an early warning for downstream degradation.
- OpenTelemetry trace propagation using the `X-Correlation-ID` already captured on every request — instrument Doctrine queries and event dispatch so a single trace shows the full lifecycle of a transfer.
- Split `/health` into a lightweight liveness probe (always 200, no DB call) and a readiness probe (checks MySQL + Redis). Kubernetes needs both to route traffic safely during rolling deploys.

### Features Worth Building Next

**Webhook relay** — the outbox already emits `TransferCompleted`, `AccountDebited`, and `AccountCredited`. Adding a webhook consumer that signs payloads with HMAC-SHA256 and delivers them to a registered endpoint is mostly plumbing around what already exists.

**Batch transfers** — a `POST /transfers/batch` endpoint would be straightforward to add and immediately useful for payroll and marketplace payout use cases. Each transfer in the batch would be processed atomically and independently, returning a mixed-result envelope.

**Fraud detection hook** — I'd expose a `FraudCheckPort` interface in the Transfer module with a null (pass-through) implementation by default. This means operators can wire in a risk-scoring service without touching any domain code. The interface boundary is already natural given the module structure.

Multi-currency and scheduled transfers are also on the list but represent more significant domain work — worth designing carefully rather than bolting on.

### Scaling & Compliance

The module boundaries are already clean enough that extracting `Account`, `Ledger`, and `Transfer` into independent services is an incremental operation, not a rewrite. The outbox event model is specifically designed to make that extraction safe — no synchronous cross-service calls needed on day one.

On compliance: a GDPR-ready version would need PII field encryption at rest, a right-to-erasure aggregate, and an append-only audit log. These aren't hard to add to the existing schema but they do require careful key management design upfront.

Finally, the current migrations use single-step `ALTER TABLE`. At production table sizes that causes full locks. I'd move to an expand/contract pattern — add nullable columns first, backfill in batches, then tighten constraints in a follow-up deploy — eliminating downtime risk on schema changes.

---

## Time Spent

Approximately **24 hours** total across design, implementation, hardening, and validation.

| Phase | Work | Estimate |
|-------|------|---------|
| 1 | Domain modelling — aggregates, value objects, events, exceptions | ~4 h |
| 2 | Infrastructure — DBAL repositories, transaction manager, pessimistic locking | ~4 h |
| 3 | Ledger, Outbox, Idempotency, Rate limiter, Reconciliation CLI | ~6 h |
| 4 | Test suite — 455 unit / integration / functional tests | ~5 h |
| 5 | Production hardening — deadlock retry, advisory lock migrations | ~2 h |
| 6 | Docker stack, submission readiness, documentation | ~3 h |

---

## AI Usage Disclosure

AI coding tools were used to accelerate scaffolding of repetitive boilerplate (test stubs, DTO wiring, migration skeletons) and to validate edge-case reasoning (deadlock proofs, idempotency invariants).

All architectural decisions — module boundaries, locking strategy, isolation level selection, outbox design, and idempotency layering — were made, reasoned through, and verified manually. Every AI-generated suggestion was reviewed, corrected where necessary, and integrated only after validation against the domain requirements.
