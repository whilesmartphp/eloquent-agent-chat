<?php

namespace Whilesmart\AgentChat\Enums;

enum AgentTurnStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isSettled(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
