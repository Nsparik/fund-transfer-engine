<?php

declare(strict_types=1);

namespace App\Shared\UI\Cli;

use App\Shared\Application\DTO\ReconciliationResult;
use App\Shared\Application\Reconciliation\ReconcileBalancesService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command that reconciles ledger balance snapshots against live account balances.
 *
 * ## Purpose
 *   Verifies the invariant: accounts.balance_minor_units == balance_after_minor_units
 *   of the most recent ledger_entries row for the same account.
 *
 *   A MISMATCH indicates a data integrity violation — the atomic double-entry write
 *   inside InitiateTransferHandler / ReverseTransferHandler should guarantee this
 *   invariant always holds.  A mismatch means either a code bug or direct DB surgery.
 *
 * ## Usage
 *   bin/console app:reconcile:balances
 *   bin/console app:reconcile:balances --show-matches          # include healthy rows in output
 *   bin/console app:reconcile:balances --account-id=<uuid>     # single-account spot check
 *
 * ## Exit codes
 *   0 — all accounts reconciled (all match, or zero-balance with no entries)
 *   1 — one or more MISMATCH accounts found → page on-call
 *   2 — no MISMATCH, but one or more no_ledger_entry accounts present;
 *       expected when accounts were seeded with initialBalanceMinorUnits > 0
 *       and have never transferred.  Exit 1 supersedes exit 2.
 *
 * ## Logging
 *   CRITICAL  — one log line per MISMATCH account (ops alerting, ELK, Datadog)
 *   WARNING   — one summary line with the count of no_ledger_entry accounts
 *               (individual IDs are NOT dumped into a single log record to prevent
 *               oversized records blowing aggregator limits at scale)
 *   DEBUG     — one line per no_ledger_entry account for ad-hoc investigation
 */
#[AsCommand(
    name:        'app:reconcile:balances',
    description: 'Reconcile ledger balance snapshots against live account balances',
)]
final class ReconcileBalancesCommand extends Command
{
    public function __construct(
        private readonly ReconcileBalancesService $service,
        private readonly LoggerInterface          $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'show-matches',
                null,
                InputOption::VALUE_NONE,
                'Include healthy (matching) accounts in the output table',
            )
            ->addOption(
                'account-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Reconcile a single account by UUID (spot-check for support tickets)',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Process accounts in pages of this size (memory-safe for large tables; 0 = load all at once)',
                500,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $showMatches = (bool) $input->getOption('show-matches');
        $accountId   = $input->getOption('account-id');

        $io->title('Ledger Balance Reconciliation');

        $batchSize = (int) $input->getOption('batch-size');

        if ($batchSize > 0) {
            $results = iterator_to_array($this->service->reconcileBatched($batchSize), false);
        } else {
            $results = $this->service->reconcile();
        }

        // ── Optional single-account filter ────────────────────────────────────
        if ($accountId !== null) {
            $results = array_values(array_filter(
                $results,
                static fn (ReconciliationResult $r) => $r->accountId === $accountId,
            ));

            if ($results === []) {
                $io->error(sprintf('Account "%s" was not found in reconciliation results.', $accountId));
                return Command::FAILURE;
            }
        }

        if ($results === []) {
            $io->note('No accounts found — nothing to reconcile.');
            return Command::SUCCESS;
        }

        // ── Partition results by status ───────────────────────────────────────
        $mismatches    = array_values(array_filter($results, static fn ($r) => $r->status === ReconciliationResult::STATUS_MISMATCH));
        $noLedgerEntry = array_values(array_filter($results, static fn ($r) => $r->status === ReconciliationResult::STATUS_NO_LEDGER_ENTRY));
        $matches       = array_values(array_filter($results, static fn ($r) => $r->status === ReconciliationResult::STATUS_MATCH));

        // ── Summary ───────────────────────────────────────────────────────────
        $io->definitionList(
            ['Total accounts checked' => count($results)],
            ['✓ Match'                => count($matches)],
            ['⚠ No ledger entry'      => count($noLedgerEntry)],
            ['✗ Mismatch'             => count($mismatches)],
        );

        // ── MISMATCH rows — always printed; these are critical ────────────────
        if ($mismatches !== []) {
            $io->section('✗  MISMATCH — Balance discrepancies detected');
            $io->table(
                ['Account ID', 'Currency', 'Account balance', 'Ledger balance', 'Diff (minor units)'],
                array_map(static fn (ReconciliationResult $r) => [
                    $r->accountId,
                    $r->currency,
                    number_format($r->accountBalance),
                    number_format((int) $r->ledgerBalance),
                    ($r->diffMinorUnits >= 0 ? '+' : '') . number_format($r->diffMinorUnits),
                ], $mismatches),
            );

            // One CRITICAL log entry per account — safe at scale, no large array per record.
            foreach ($mismatches as $r) {
                $this->logger->critical('reconciliation.balance_mismatch', [
                    'account_id'       => $r->accountId,
                    'currency'         => $r->currency,
                    'account_balance'  => $r->accountBalance,
                    'ledger_balance'   => $r->ledgerBalance,
                    'diff_minor_units' => $r->diffMinorUnits,
                ]);
            }
        }

        // ── No-ledger-entry rows — may be expected for initial-balance accounts ──
        if ($noLedgerEntry !== []) {
            $io->section('⚠  No ledger entry — non-zero balance accounts without any transactions');
            $io->table(
                ['Account ID', 'Currency', 'Account balance (minor units)'],
                array_map(static fn (ReconciliationResult $r) => [
                    $r->accountId,
                    $r->currency,
                    number_format($r->accountBalance),
                ], $noLedgerEntry),
            );

            // One WARNING summary line (count only) — aggregator-safe at any scale.
            $this->logger->warning('reconciliation.no_ledger_entry', [
                'count' => count($noLedgerEntry),
            ]);

            // Individual DEBUG lines for ad-hoc investigation without blowing log record sizes.
            foreach ($noLedgerEntry as $r) {
                $this->logger->debug('reconciliation.no_ledger_entry.account', [
                    'account_id'      => $r->accountId,
                    'currency'        => $r->currency,
                    'account_balance' => $r->accountBalance,
                ]);
            }
        }

        // ── Optionally display matching accounts ──────────────────────────────
        if ($showMatches && $matches !== []) {
            $io->section('✓  Matching accounts');
            $io->table(
                ['Account ID', 'Currency', 'Balance (minor units)'],
                array_map(static fn (ReconciliationResult $r) => [
                    $r->accountId,
                    $r->currency,
                    number_format($r->accountBalance),
                ], $matches),
            );
        }

        // ── Determine exit code ───────────────────────────────────────────────
        if ($mismatches !== []) {
            $io->error(sprintf(
                'Reconciliation FAILED: %d mismatch(es) detected. See application logs for details.',
                count($mismatches),
            ));

            return Command::FAILURE; // exit 1
        }

        if ($noLedgerEntry !== []) {
            $io->warning(sprintf(
                'Reconciliation complete with %d account(s) having no ledger entry but a non-zero balance. '
                . 'This is expected when accounts were created with an initial balance. '
                . 'Investigate if those accounts have already had transfers.',
                count($noLedgerEntry),
            ));

            return 2; // exit 2 — informational
        }

        $io->success(sprintf('All %d account(s) reconciled successfully.', count($results)));

        return Command::SUCCESS; // exit 0
    }
}
