<?php

namespace Whilesmart\AgentChat;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Whilesmart\AgentChat\Blocks\BlockBuilder;
use Whilesmart\AgentChat\Contracts\ResponseFormatter;
use Whilesmart\AgentChat\Contracts\TurnInputBuilder;
use Whilesmart\AgentChat\Http\Requests\StoreAgentTurnRequest;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\Agents\Registries\ToolRegistry;

class AgentChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-chat.php', 'agent-chat');

        $this->app->singleton(BlockBuilder::class);

        $this->app->bind(ResponseFormatter::class, fn ($app) => $this->configured(
            $app->make(config('agent-chat.response_formatter')),
            ResponseFormatter::class,
            'response formatter',
        ));

        $this->app->bind(TurnInputBuilder::class, fn ($app) => $this->configured(
            $app->make(config('agent-chat.input_builder')),
            TurnInputBuilder::class,
            'input builder',
        ));

        $this->app->bind(StoreAgentTurnRequest::class, function ($app) {
            $configured = config('agent-chat.requests.store_turn');

            if (! is_string($configured) || ! is_a($configured, StoreAgentTurnRequest::class, true)) {
                throw new InvalidArgumentException(
                    'The configured agent-chat store_turn request must extend '.StoreAgentTurnRequest::class.'.',
                );
            }

            return $app->build($configured);
        });

        if (! is_a(config('agent-chat.resources.turn'), JsonResource::class, true)) {
            throw new InvalidArgumentException('The configured agent-chat turn resource must extend '.JsonResource::class.'.');
        }

        if (! is_a(config('agent-chat.controller'), Controller::class, true)) {
            throw new InvalidArgumentException('The configured agent-chat controller must extend '.Controller::class.'.');
        }

        if (! is_a(config('agent-chat.model'), AgentTurn::class, true)) {
            throw new InvalidArgumentException('The configured agent-chat model must extend '.AgentTurn::class.'.');
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/agent-chat.php' => config_path('agent-chat.php'),
        ], 'agent-chat-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'agent-chat-migrations');

        Route::model('agentTurn', config('agent-chat.model', AgentTurn::class));

        if (config('agent-chat.register_tools', true)) {
            $registry = $this->app->make(ToolRegistry::class);

            foreach ((array) config('agent-chat.tools', []) as $tool) {
                $registry->register($tool);
            }
        }

        if (config('agent-chat.register_routes', true)) {
            Route::middleware(config('agent-chat.route_middleware', ['api', 'auth:sanctum']))
                ->prefix(config('agent-chat.route_prefix', 'api'))
                ->group(__DIR__.'/../routes/api.php');
        }
    }

    private function configured(mixed $instance, string $contract, string $label): mixed
    {
        if (! $instance instanceof $contract) {
            throw new InvalidArgumentException("The configured agent-chat {$label} must implement {$contract}.");
        }

        return $instance;
    }
}
