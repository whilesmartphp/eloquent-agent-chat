<?php

namespace Whilesmart\AgentChat\Turns;

class ProgressLabels
{
    public function for(string $toolName): ?string
    {
        foreach ((array) config('agent-chat.progress_labels', []) as $pattern => $label) {
            if (fnmatch((string) $pattern, $toolName)) {
                return (string) $label;
            }
        }

        return null;
    }
}
