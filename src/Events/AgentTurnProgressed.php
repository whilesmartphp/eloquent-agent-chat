<?php

namespace Whilesmart\AgentChat\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Whilesmart\AgentChat\Models\AgentTurn;

class AgentTurnProgressed
{
    use Dispatchable, SerializesModels;

    public function __construct(public AgentTurn $turn, public string $label) {}
}
