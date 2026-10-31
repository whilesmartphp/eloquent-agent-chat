<?php

use Whilesmart\AgentChat\Export\MarkdownCanvasExporter;
use Whilesmart\AgentChat\Http\Controllers\AgentRunController;
use Whilesmart\AgentChat\Http\Requests\StoreAgentRunRequest;
use Whilesmart\AgentChat\Http\Resources\AgentRunAttemptResource;
use Whilesmart\AgentChat\Http\Resources\AgentRunResource;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Models\AgentRunAttempt;
use Whilesmart\AgentChat\ResponseFormatters\DefaultResponseFormatter;
use Whilesmart\AgentChat\Runs\DefaultMediaResolver;
use Whilesmart\AgentChat\Runs\DefaultProviderPlan;
use Whilesmart\AgentChat\Runs\DefaultRetryPolicy;
use Whilesmart\AgentChat\Runs\DefaultRunInputBuilder;
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

return [
    'model' => AgentRun::class,
    'attempt_model' => AgentRunAttempt::class,
    'runs_table' => env('AGENT_CHAT_RUNS_TABLE', 'agent_runs'),
    'attempts_table' => env('AGENT_CHAT_ATTEMPTS_TABLE', 'agent_run_attempts'),

    // The harness a run uses when the request names none. Requests may only
    // pick from `harnesses`; leave it empty to allow just the default.
    'default_harness' => env('AGENT_CHAT_HARNESS', 'assistant'),
    'harnesses' => [],

    'register_routes' => env('AGENT_CHAT_REGISTER_ROUTES', true),
    'route_prefix' => env('AGENT_CHAT_ROUTE_PREFIX', 'api'),
    'route_middleware' => ['api', 'auth:sanctum'],
    'route_write_middleware' => [],
    'route_actions' => ['index', 'show', 'attempts', 'store', 'release', 'retry', 'cancel', 'export'],
    'controller' => AgentRunController::class,

    'requests' => [
        'store_run' => StoreAgentRunRequest::class,
    ],

    'resources' => [
        'run' => AgentRunResource::class,
        'attempt' => AgentRunAttemptResource::class,
    ],

    'response_formatter' => DefaultResponseFormatter::class,

    'input_builder' => DefaultRunInputBuilder::class,
    'media_resolver' => DefaultMediaResolver::class,
    'provider_plan' => DefaultProviderPlan::class,
    'retry_policy' => DefaultRetryPolicy::class,

    // queue: a prompt waits while an earlier run in the conversation is
    // unfinished. reject: the prompt is refused with 409. parallel: no limit.
    'concurrency' => env('AGENT_CHAT_CONCURRENCY', 'queue'),

    // How many earlier messages are folded into the agent's input.
    'history_limit' => (int) env('AGENT_CHAT_HISTORY_LIMIT', 10),

    // Attachments people send are fetched on the server to show the model,
    // so they may only link to these https hosts (fnmatch patterns) or point
    // at these storage disks. Both are empty, and so closed, by default.
    'attachments' => [
        'max' => (int) env('AGENT_CHAT_MAX_ATTACHMENTS', 10),
        'url_hosts' => [],
        'disks' => [],
    ],

    'queue' => [
        'connection' => env('AGENT_CHAT_QUEUE_CONNECTION'),
        'queue' => env('AGENT_CHAT_QUEUE'),
        'tries' => (int) env('AGENT_CHAT_TRIES', 2),
        'timeout' => (int) env('AGENT_CHAT_TIMEOUT', 120),
        // Seconds before a failed run is tried again, while tries remain.
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

    // Block types your tools add beside the core ones, such as 'design_version'.
    'block_types' => [],

    // Step labels shown while a run works, keyed by tool name pattern (fnmatch).
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

    // Push run updates to a private channel as they happen. The stored run
    // stays the source of truth for clients that poll or reconnect.
    'broadcast' => [
        'enabled' => (bool) env('AGENT_CHAT_BROADCAST', false),
        'channel_prefix' => 'agent-chat.conversation',
    ],

    'exporters' => [
        'md' => MarkdownCanvasExporter::class,
    ],
];
