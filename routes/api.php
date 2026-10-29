<?php

use Illuminate\Support\Facades\Route;

$controller = config('agent-chat.controller');
$writeMiddleware = config('agent-chat.route_write_middleware', []);
$actions = config('agent-chat.route_actions', ['index', 'show', 'store', 'retry', 'export']);

if (in_array('index', $actions, true)) {
    Route::get('agent-turns', [$controller, 'index']);
}
if (in_array('show', $actions, true)) {
    Route::get('agent-turns/{agentTurn}', [$controller, 'show']);
}
if (in_array('store', $actions, true)) {
    Route::post('conversations/{conversation}/agent-turns', [$controller, 'store'])->middleware($writeMiddleware);
}
if (in_array('retry', $actions, true)) {
    Route::post('agent-turns/{agentTurn}/retry', [$controller, 'retry'])->middleware($writeMiddleware);
}
if (in_array('export', $actions, true)) {
    Route::get('agent-turns/{agentTurn}/export', [$controller, 'export']);
}
