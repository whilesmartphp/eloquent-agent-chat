<?php

namespace Whilesmart\AgentChat;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Whilesmart\AgentChat\Blocks\BlockBuilder;
use Whilesmart\AgentChat\Contracts\MediaResolver;
use Whilesmart\AgentChat\Contracts\ProviderPlan;
use Whilesmart\AgentChat\Contracts\ResponseFormatter;
use Whilesmart\AgentChat\Contracts\RetryPolicy;
use Whilesmart\AgentChat\Contracts\RunInputBuilder;
use Whilesmart\AgentChat\Enums\ConcurrencyPolicy;
use Whilesmart\AgentChat\Http\Requests\StoreAgentRunRequest;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Models\AgentRunAttempt;
use Whilesmart\AgentChat\Runs\RunProgress;
use Whilesmart\Agents\Registries\ToolRegistry;

class AgentChatServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const CONTRACTS = [
        'response_formatter' => [ResponseFormatter::class, 'response formatter'],
        'input_builder' => [RunInputBuilder::class, 'input builder'],
        'media_resolver' => [MediaResolver::class, 'media resolver'],
        'provider_plan' => [ProviderPlan::class, 'provider plan'],
        'retry_policy' => [RetryPolicy::class, 'retry policy'],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-chat.php', 'agent-chat');

        $this->app->singleton(BlockBuilder::class);
        $this->app->bind(RunProgress::class, fn () => new RunProgress);

        foreach (self::CONTRACTS as $key => [$contract, $label]) {
            $this->app->bind($contract, fn ($app) => $this->configured(
                $app->make(config("agent-chat.{$key}")),
                $contract,
                $label,
            ));
        }

        $this->app->bind(StoreAgentRunRequest::class, function ($app) {
            $configured = config('agent-chat.requests.store_run');

            if (! is_string($configured) || ! is_a($configured, StoreAgentRunRequest::class, true)) {
                throw new InvalidArgumentException(
                    'The configured agent-chat store_run request must extend '.StoreAgentRunRequest::class.'.',
                );
            }

            return $app->build($configured);
        });

        foreach (['run', 'attempt'] as $resource) {
            if (! is_a(config("agent-chat.resources.{$resource}"), JsonResource::class, true)) {
                throw new InvalidArgumentException("The configured agent-chat {$resource} resource must extend ".JsonResource::class.'.');
            }
        }

        if (! is_a(config('agent-chat.controller'), Controller::class, true)) {
            throw new InvalidArgumentException('The configured agent-chat controller must extend '.Controller::class.'.');
        }

        if (! is_a(config('agent-chat.model'), AgentRun::class, true)) {
            throw new InvalidArgumentException('The configured agent-chat model must extend '.AgentRun::class.'.');
        }

        if (! is_a(config('agent-chat.attempt_model'), AgentRunAttempt::class, true)) {
            throw new InvalidArgumentException('The configured agent-chat attempt model must extend '.AgentRunAttempt::class.'.');
        }

        if (ConcurrencyPolicy::tryFrom((string) config('agent-chat.concurrency')) === null) {
            throw new InvalidArgumentException('The agent-chat concurrency must be one of: '.implode(', ', ConcurrencyPolicy::values()).'.');
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

        Route::model('agentRun', config('agent-chat.model', AgentRun::class));

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
