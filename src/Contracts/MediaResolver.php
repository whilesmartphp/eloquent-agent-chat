<?php

namespace Whilesmart\AgentChat\Contracts;

use Whilesmart\AgentChat\Models\AgentRun;

/**
 * Turns the attachments a run should show the model into engine-ready media.
 */
interface MediaResolver
{
    /**
     * @return array<int, mixed>
     */
    public function resolve(AgentRun $run): array;
}
