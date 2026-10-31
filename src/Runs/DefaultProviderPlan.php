<?php

namespace Whilesmart\AgentChat\Runs;

use Whilesmart\AgentChat\Contracts\ProviderPlan;
use Whilesmart\AgentChat\Models\AgentRun;

class DefaultProviderPlan implements ProviderPlan
{
    public function candidates(AgentRun $run): array
    {
        return [[]];
    }
}
