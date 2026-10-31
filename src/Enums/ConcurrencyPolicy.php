<?php

namespace Whilesmart\AgentChat\Enums;

/**
 * What happens when a prompt arrives while another run in the same
 * conversation has not settled.
 */
enum ConcurrencyPolicy: string
{
    case Queue = 'queue';
    case Reject = 'reject';
    case Parallel = 'parallel';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
