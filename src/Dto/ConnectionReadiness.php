<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/** API configuration only: not a test of numbering, invoice validity or MF credentials. */
final readonly class ConnectionReadiness
{
    public const array REQUIRED_SCOPES = ['invoices.read', 'invoices.write', 'invoices.issue', 'ksef.send', 'ksef.download', 'invoices.download'];

    /** @param list<string> $scopes */
    public function __construct(
        public ?string $companyNip,
        public ?string $companyName,
        public array $scopes,
        public bool $apiPlanActive,
        public bool $ordersEnabled,
        public bool $orderIssueEnabled,
    ) {
    }

    /** @param list<string> $required @return list<string> */
    public function missingScopes(array $required = self::REQUIRED_SCOPES): array
    {
        return array_values(array_diff($required, $this->scopes));
    }

    public function isReady(): bool
    {
        return $this->apiPlanActive && $this->ordersEnabled && $this->orderIssueEnabled
            && null !== $this->companyNip && '' !== $this->companyNip
            && null !== $this->companyName && '' !== $this->companyName
            && [] === $this->missingScopes();
    }
}
