<?php

use Whilesmart\AgentChat\Export\MarkdownCanvasExporter;
use Whilesmart\AgentChat\Http\Controllers\AgentTurnController;
use Whilesmart\AgentChat\Http\Requests\StoreAgentTurnRequest;
use Whilesmart\AgentChat\Http\Resources\AgentTurnResource;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\AgentChat\ResponseFormatters\DefaultResponseFormatter;
use Whilesmart\AgentChat\Tools\AskQuestionTool;
use Whilesmart\AgentChat\Tools\OpenCanvasTool;
use Whilesmart\AgentChat\Tools\RenderCalloutTool;
use Whilesmart\AgentChat\Tools\RenderChartTool;
use Whilesmart\AgentChat\Tools\RenderKpiTool;
use Whilesmart\AgentChat\Tools\RenderMarkdownTool;
use Whilesmart\AgentChat\Tools\RenderProgressTool;
use Whilesmart\AgentChat\Tools\RenderTableTool;
use Whilesmart\AgentChat\Tools\RenderTimelineTool;
use Whilesmart\AgentChat\Tools\UpdateCanvasTool;
use Whilesmart\AgentChat\Turns\DefaultTurnInputBuilder;

return [
    'model' => AgentTurn::class,
    'turns_table' => env('AGENT_CHAT_TURNS_TABLE', 'agent_turns'),

    // The harness a turn runs when the request names none. Requests may only
    // pick from `harnesses`; leave it empty to allow just the default.
    'default_harness' => env('AGENT_CHAT_HARNESS', 'assistant'),
    'harnesses' => [],

    'register_routes' => env('AGENT_CHAT_REGISTER_ROUTES', true),
    'route_prefix' => env('AGENT_CHAT_ROUTE_PREFIX', 'api'),
    'route_middleware' => ['api', 'auth:sanctum'],
    'route_write_middleware' => [],
    'route_actions' => ['index', 'show', 'store', 'retry', 'export'],
    'controller' => AgentTurnController::class,

    'requests' => [
        'store_turn' => StoreAgentTurnRequest::class,
    ],

    'resources' => [
        'turn' => AgentTurnResource::class,
    ],

    'response_formatter' => DefaultResponseFormatter::class,

    'input_builder' => DefaultTurnInputBuilder::class,

    // How many earlier messages are folded into the agent's input.
    'history_limit' => (int) env('AGENT_CHAT_HISTORY_LIMIT', 10),

    'queue' => [
        'connection' => env('AGENT_CHAT_QUEUE_CONNECTION'),
        'queue' => env('AGENT_CHAT_QUEUE'),
        'tries' => (int) env('AGENT_CHAT_TRIES', 2),
        'timeout' => (int) env('AGENT_CHAT_TIMEOUT', 120),
        // Seconds before a failed attempt is tried again, while tries remain.
        'backoff' => (int) env('AGENT_CHAT_BACKOFF', 10),
    ],

    // Register the render tools with eloquent-agents so harnesses can list
    // them by name. Add or remove classes to change the set.
    'register_tools' => true,
    'tools' => [
        RenderMarkdownTool::class,
        RenderTableTool::class,
        RenderKpiTool::class,
        RenderChartTool::class,
        RenderCalloutTool::class,
        RenderTimelineTool::class,
        RenderProgressTool::class,
        AskQuestionTool::class,
        OpenCanvasTool::class,
        UpdateCanvasTool::class,
    ],

    // Step labels shown while a turn runs, keyed by tool name pattern (fnmatch).
    // The first match wins; a tool with no match adds no step.
    'progress_labels' => [
        'render_*' => 'Putting it together',
        'open_canvas' => 'Putting it together',
        'update_canvas' => 'Putting it together',
        'ask_question' => 'Preparing a question',
        'list_*' => 'Looking through your records',
        'search_*' => 'Searching',
        'calculator' => 'Working out the figures',
    ],

    // Push turn progress to a private channel as it happens. The stored turn
    // stays the source of truth for clients that poll or reconnect.
    'broadcast' => [
        'enabled' => (bool) env('AGENT_CHAT_BROADCAST', false),
        'channel_prefix' => 'agent-chat.conversation',
    ],

    'exporters' => [
        'md' => MarkdownCanvasExporter::class,
    ],
];
