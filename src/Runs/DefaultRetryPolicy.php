<?php

namespace Whilesmart\AgentChat\Runs;

use Whilesmart\AgentChat\Contracts\RetryPolicy;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Models\AgentRunAttempt;
use Whilesmart\Agents\ValueObjects\AgentResult;

class DefaultRetryPolicy implements RetryPolicy
{
    public function retryable(AgentRun $run, AgentRunAttempt $attempt, AgentResult $result): bool
    {
        return true;
    }
}
