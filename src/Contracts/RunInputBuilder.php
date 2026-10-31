<?php

namespace Whilesmart\AgentChat\Contracts;

use Whilesmart\AgentChat\Models\AgentRun;

/**
 * Turns the conversation behind a run into the text input the harness
 * receives. Bind your own to add context such as the record the conversation
 * is about.
 */
interface RunInputBuilder
{
    public function build(AgentRun $run): string;
}
