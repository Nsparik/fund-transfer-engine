.PHONY: up down build rebuild logs shell mysql-shell redis-cli ps help

# ─── Stack ────────────────────────────────────────────────────────────────────

## Start all containers in background
up:
	docker compose up -d

## Stop all containers (data volumes preserved)
down:
	docker compose down

## Stop all containers AND destroy data volumes — DESTRUCTIVE
down-v:
	docker compose down -v

## Build all images
build:
	docker compose build

## Force rebuild all images (no cache)
rebuild:
	docker compose build --no-cache

## Show container status and health
ps:
	docker compose ps

## Follow logs from all containers
logs:
	docker compose logs -f

## Follow PHP container logs only
logs-php:
	docker compose logs -f php

## Follow Apache container logs only
logs-apache:
	docker compose logs -f apache

# ─── Shells ───────────────────────────────────────────────────────────────────

## Open a bash shell in the PHP container
shell:
	docker compose exec php bash

## Open a MySQL prompt as the app user
mysql-shell:
	docker compose exec mysql mysql -u $$(grep ^MYSQL_USER .env | cut -d= -f2) \
		--password=$$(grep ^MYSQL_PASSWORD .env | cut -d= -f2) \
		$$(grep ^MYSQL_DATABASE .env | cut -d= -f2)

## Open a Redis CLI prompt
redis-cli:
	docker compose exec redis redis-cli -a $$(grep ^REDIS_PASSWORD .env | cut -d= -f2)

# ─── Composer ─────────────────────────────────────────────────────────────────

## Install Composer dependencies
composer-install:
	docker compose exec php composer install

# ─── Help ─────────────────────────────────────────────────────────────────────
help:
	@echo ""
	@echo "Fund Transfer Engine — Available targets:"
	@echo "──────────────────────────────────────────"
	@grep -E '^##' Makefile | sed 's/## /  /'
	@echo ""
