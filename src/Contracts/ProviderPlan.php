<?php

namespace Whilesmart\AgentChat\Contracts;

use Whilesmart\AgentChat\Models\AgentRun;

/**
 * The providers a run tries, in order. Each candidate is passed to the harness
 * as run overrides, so it may also set temperature, maxSteps or maxTokens.
 * An empty candidate uses the harness's own provider and model.
 */
interface ProviderPlan
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidates(AgentRun $run): array;
}
