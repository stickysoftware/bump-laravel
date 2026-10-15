<?php

namespace StickySoftware\Bump;

final readonly class SendResult
{
    /**
     * @param  list<array<string, mixed>>  $results  one per event after expansion, as the API returned them
     */
    private function __construct(
        public SendOutcome $outcome,
        public array $results = [],
        public ?int $retryAfter = null,
        public string $reason = '',
    ) {}

    /**
     * @param  list<array<string, mixed>>  $results
     */
    public static function delivered(array $results): self
    {
        return new self(SendOutcome::Delivered, $results);
    }

    public static function retry(?int $retryAfter, string $reason): self
    {
        return new self(SendOutcome::Retry, retryAfter: $retryAfter, reason: $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(SendOutcome::Failed, reason: $reason);
    }

    /**
     * @return array{accepted: int, duplicate: int, rejected: int}
     */
    public function counts(): array
    {
        $counts = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];

        foreach ($this->results as $result) {
            $status = $result['status'] ?? null;

            if (is_string($status) && isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }
}
