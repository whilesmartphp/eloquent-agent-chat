<?php

namespace Whilesmart\AgentChat\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Whilesmart\AgentChat\Models\AgentRun;

trait HasAgentRuns
{
    public function agentRuns(): MorphMany
    {
        return $this->morphMany(config('agent-chat.model', AgentRun::class), 'owner');
    }
}
