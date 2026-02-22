# Fund Transfer Engine

A **production-grade, ledger-based fund transfer module** built with:

- **Architecture**: Modular Monolith → Microservice extraction path
- **Backend**: PHP 8.2 / Symfony 7.4 LTS (transport layer only)
- **Database**: MySQL 8.0 (strict mode, InnoDB, READ COMMITTED)
- **Cache**: Redis 7
- **ORM**: None — Doctrine DBAL only
- **Money**: Integer minor-units (no floats, no decimals)
- **Pattern**: Clean Architecture + Domain-Driven Design
- **Messaging**: Outbox pattern (broker-ready)
- **Infra**: Docker CE (non-root workers, internal networking)

## Quick Start

```bash
cp .env.example .env      # fill in your secrets
make up                   # start all containers
make shell                # enter PHP container
```

## Project Layout

```
src/Module/Transfer/
├── Domain/          # Pure PHP — zero framework dependencies
├── Application/     # Use cases, commands, handlers
├── Infrastructure/  # DB adapters, outbox, Redis
└── UI/              # Symfony controllers, CLI (entry points only)

docker/              # Container configurations
migrations/          # Custom SQL migrations (no Doctrine)
tests/               # Unit / Integration / Functional
```
