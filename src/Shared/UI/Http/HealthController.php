<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    /** Alert threshold: events stuck unpublished for more than this many minutes. */
    private const OUTBOX_LAG_MINUTES = 5;

    public function __construct(
        private readonly Connection               $connection,
        private readonly LoggerInterface          $logger,
        private readonly OutboxRepositoryInterface $outboxRepository,
    ) {}

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks    = [];
        $degraded  = false;
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        // ── 1. Database connectivity ──────────────────────────────────────────
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            // Log the full error internally (may contain DSN/credentials) but
            // never expose it to the caller — health endpoints are often publicly
            // reachable by load balancers and monitoring systems.
            $this->logger->error('health.database_check_failed', [
                'exception_class'   => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            $checks['database'] = 'unavailable';
            $degraded           = true;
        }

        // ── 2. Outbox lag ─────────────────────────────────────────────────────
        // Count events that have been stuck unpublished for longer than the lag
        // threshold. A non-zero count means the OutboxProcessor is not running
        // or is repeatedly failing.  Skipped when DB is already unavailable.
        if ($checks['database'] === 'ok') {
            try {
                $stuckCount = $this->outboxRepository->countStuckEvents(self::OUTBOX_LAG_MINUTES);

                if ($stuckCount > 0) {
                    $this->logger->error('health.outbox_lag_detected', [
                        'stuck_event_count'  => $stuckCount,
                        'threshold_minutes'  => self::OUTBOX_LAG_MINUTES,
                    ]);

                    $checks['outbox'] = sprintf(
                        '%d event(s) unpublished for >%d min',
                        $stuckCount,
                        self::OUTBOX_LAG_MINUTES,
                    );
                    $degraded = true;
                } else {
                    $checks['outbox'] = 'ok';
                }
            } catch (\Throwable $e) {
                $this->logger->warning('health.outbox_check_failed', [
                    'exception_class'   => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
                // Non-fatal — outbox table might not exist yet (before migration).
                $checks['outbox'] = 'unavailable';
            }
        }

        $status = $degraded ? 'degraded' : 'ok';
        $code   = $degraded ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK;

        return new JsonResponse(
            data: ['status' => $status, 'checks' => $checks, 'timestamp' => $timestamp],
            status: $code,
        );
    }
}
