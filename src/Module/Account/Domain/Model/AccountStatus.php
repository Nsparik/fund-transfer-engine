<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Model;

/**
 * Lifecycle states of an Account aggregate.
 *
 * Allowed transitions:
 *
 *   ACTIVE ──► FROZEN ──► ACTIVE   (freeze / unfreeze cycle)
 *   ACTIVE ──► CLOSED               (permanent, terminal)
 *   FROZEN ──► CLOSED               (close a frozen account)
 *
 * A CLOSED account is terminal — it cannot be reopened, debited, or credited.
 * A FROZEN account can be debited/credited only by the unfreeze path.
 */
enum AccountStatus: string
{
    case ACTIVE = 'active';
    case FROZEN = 'frozen';
    case CLOSED = 'closed';

    public function isTerminal(): bool
    {
        return $this === self::CLOSED;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::ACTIVE => $next === self::FROZEN || $next === self::CLOSED,
            self::FROZEN => $next === self::ACTIVE || $next === self::CLOSED,
            self::CLOSED => false,
        };
    }
}
