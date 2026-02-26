<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Value object returned by AccountTransferPort::executeDoubleEntry().
 *
 * Carries the post-transfer balance snapshots and the domain events raised
 * by both Account aggregates, without exposing any Account domain types to
 * the Transfer module.
 *
 * - sourceBalanceAfterMinorUnits     Balance of the debited account after the operation.
 * - destinationBalanceAfterMinorUnits Balance of the credited account after the operation.
 * - events  list<TaggedEvent> — each domain event paired with the aggregate ID of
 *             the account that raised it.  AccountDebited carries the source account's
 *             ID; AccountCredited carries the destination account's ID.  The Transfer
 *             module iterates these and writes each to the outbox with the correct
 *             aggregate_id — no Account domain types cross the module boundary.
 */
final readonly class DoubleEntryResult
{
    /**
     * @param list<TaggedEvent> $events Domain events from both Account aggregates,
     *                                  each tagged with the owning account's UUID.
     */
    public function __construct(
        public readonly int   $sourceBalanceAfterMinorUnits,
        public readonly int   $destinationBalanceAfterMinorUnits,
        public readonly array $events,
    ) {}
}
