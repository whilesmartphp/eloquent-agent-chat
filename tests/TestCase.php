<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Tests\Fixtures\ScriptedHarness;
use Tests\Fixtures\User;
use Whilesmart\AgentChat\AgentChatServiceProvider;
use Whilesmart\Agents\AgentsServiceProvider;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Conversations\ConversationsServiceProvider;
use Whilesmart\Conversations\Models\Conversation;
use Whilesmart\OwnerAccess\OwnerAccessServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected const OWNER = 'App\\Models\\Workspace';

    protected ScriptedHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new ScriptedHarness('assistant');
        Agents::registerHarness('assistant', $this->harness);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            OwnerAccessServiceProvider::class,
            AgentsServiceProvider::class,
            ConversationsServiceProvider::class,
            AgentChatServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('agent-chat.route_middleware', ['api']);
        $app['config']->set('conversations.route_middleware', ['api']);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('agent-chat.queue.tries', 1);
    }

    protected function user(string $name = 'Dee'): User
    {
        return User::create(['name' => $name]);
    }

    protected function conversation(int $ownerId = 1): Conversation
    {
        return Conversation::create([
            'owner_type' => self::OWNER,
            'owner_id' => $ownerId,
            'channel' => 'agent',
        ]);
    }
}
