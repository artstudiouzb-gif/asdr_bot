<?php

declare(strict_types=1);

namespace App\Services\Processing;

final class RuleResult
{
    /** @param array<int, string> $applied названия сработавших правил */
    public function __construct(
        public readonly string $html,
        public readonly array $applied = [],
        public readonly bool $dropped = false,
        public readonly ?string $dropReason = null,
    ) {
    }
}
