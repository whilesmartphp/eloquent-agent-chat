<?php

namespace Whilesmart\AgentChat\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Whilesmart\AgentChat\Models\AgentTurn;

trait HasAgentTurns
{
    public function agentTurns(): MorphMany
    {
        return $this->morphMany(config('agent-chat.model', AgentTurn::class), 'owner');
    }
}
