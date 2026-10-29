<?php

namespace Whilesmart\AgentChat\Contracts;

use Whilesmart\AgentChat\Models\AgentTurn;

/**
 * Turns the conversation behind an agent turn into the text input the harness
 * receives. Bind your own to add context such as attachments or the record the
 * conversation is about.
 */
interface TurnInputBuilder
{
    public function build(AgentTurn $turn): string;
}
