<?php

use Illuminate\Support\Facades\Route;

$controller = config('agent-chat.controller');
$writeMiddleware = config('agent-chat.route_write_middleware', []);
$actions = config('agent-chat.route_actions', ['index', 'show', 'attempts', 'store', 'release', 'retry', 'cancel', 'export']);

if (in_array('index', $actions, true)) {
    Route::get('agent-runs', [$controller, 'index']);
}
if (in_array('show', $actions, true)) {
    Route::get('agent-runs/{agentRun}', [$controller, 'show']);
}
if (in_array('attempts', $actions, true)) {
    Route::get('agent-runs/{agentRun}/attempts', [$controller, 'attempts']);
}
if (in_array('store', $actions, true)) {
    Route::post('conversations/{conversation}/agent-runs', [$controller, 'store'])->middleware($writeMiddleware);
}
if (in_array('release', $actions, true)) {
    Route::post('agent-runs/{agentRun}/release', [$controller, 'release'])->middleware($writeMiddleware);
}
if (in_array('retry', $actions, true)) {
    Route::post('agent-runs/{agentRun}/retry', [$controller, 'retry'])->middleware($writeMiddleware);
}
if (in_array('cancel', $actions, true)) {
    Route::post('agent-runs/{agentRun}/cancel', [$controller, 'cancel'])->middleware($writeMiddleware);
}
if (in_array('export', $actions, true)) {
    Route::get('agent-runs/{agentRun}/export', [$controller, 'export']);
}
