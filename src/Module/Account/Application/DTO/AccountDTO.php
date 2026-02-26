<?php

declare(strict_types=1);

namespace App\Module\Account\Application\DTO;

use App\Module\Account\Domain\Model\Account;

/**
 * Read model produced from an Account aggregate for external consumers
 * (API responses, CLI output, test assertions).
 *
 * Only primitive scalar values — no domain objects leak past this boundary.
 */
final readonly class AccountDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerName,
        public readonly string $currency,
        public readonly int    $balanceMinorUnits,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly int    $version,
        public readonly ?string $closedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'ownerName'         => $this->ownerName,
            'currency'          => $this->currency,
            'balanceMinorUnits' => $this->balanceMinorUnits,
            'status'            => $this->status,
            'createdAt'         => $this->createdAt,
            'updatedAt'         => $this->updatedAt,
            'closedAt'          => $this->closedAt,
            // version is intentionally omitted: it is an internal optimistic-lock
            // counter with no API semantics; leaking it would invite callers to
            // build concurrency control against an implementation detail.
        ];
    }

    public static function fromAccount(Account $account): self
    {
        return new self(
            id:                $account->getId()->toString(),
            ownerName:         $account->getOwnerName(),
            currency:          $account->getCurrency(),
            balanceMinorUnits: $account->getBalance()->getAmountMinorUnits(),
            status:            $account->getStatus()->value,
            createdAt:         $account->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt:         $account->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            version:           $account->getVersion(),
            closedAt:          $account->getClosedAt()?->format(\DateTimeInterface::ATOM),
        );
    }
}
