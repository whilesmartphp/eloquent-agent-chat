<?php

namespace Whilesmart\AgentChat\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Models\AgentRunAttempt;

class AgentRunAttemptFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public AgentRun $run, public AgentRunAttempt $attempt) {}
}
