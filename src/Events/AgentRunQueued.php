<?php

namespace Whilesmart\AgentChat\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Whilesmart\AgentChat\Models\AgentRun;

class AgentRunQueued
{
    use Dispatchable, SerializesModels;

    public function __construct(public AgentRun $run) {}
}
