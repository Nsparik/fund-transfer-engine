<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsMonologProcessor]
final class CorrelationIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestStack $requestStack) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $request       = $this->requestStack->getCurrentRequest();
        $correlationId = $request?->attributes->get('correlation_id');

        if ($correlationId === null) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            'correlation_id' => $correlationId,
        ]);
    }
}
