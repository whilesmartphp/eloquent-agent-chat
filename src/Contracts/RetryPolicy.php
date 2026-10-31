<?php

namespace Whilesmart\AgentChat\Contracts;

use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Models\AgentRunAttempt;
use Whilesmart\Agents\ValueObjects\AgentResult;

/**
 * Decides whether a failed attempt is worth another try, on the next provider
 * or on a later job attempt. A failure that is not retryable fails the run.
 */
interface RetryPolicy
{
    public function retryable(AgentRun $run, AgentRunAttempt $attempt, AgentResult $result): bool;
}
