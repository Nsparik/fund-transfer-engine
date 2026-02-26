<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Model;

/**
 * Lifecycle states of a Transfer aggregate.
 *
 * Allowed state-machine transitions:
 *
 *   PENDING ──► PROCESSING ──► COMPLETED
 *                          └──► FAILED
 *   COMPLETED ──────────────► REVERSED
 *
 * Any other transition is illegal and must throw a domain exception.
 */
enum TransferStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';
    case FAILED     = 'failed';
    case REVERSED   = 'reversed';

    /**
     * Terminal states cannot be transitioned out of.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::REVERSED => true,
            default => false,
        };
    }

    /**
     * Returns the set of states this status can legally transition to.
     *
     * @return list<self>
     */
    public function allowedTransitionsTo(): array
    {
        return match ($this) {
            self::PENDING    => [self::PROCESSING],
            self::PROCESSING => [self::COMPLETED, self::FAILED],
            self::COMPLETED  => [self::REVERSED],
            self::FAILED     => [],
            self::REVERSED   => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitionsTo(), true);
    }
}
