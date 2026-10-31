<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Tests\Fixtures\MakeImageTool;
use Tests\TestCase;
use Whilesmart\AgentChat\Blocks\BlockCollector;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Conversations\Models\Message;

class AttachmentsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    #[Test]
    public function prompt_attachments_are_stored_on_the_prompt_and_shown_to_the_model(): void
    {
        config(['agent-chat.attachments.url_hosts' => ['files.example.com']]);

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", [
                'body' => 'Match this style',
                'attachments' => [
                    ['type' => 'image', 'url' => 'https://files.example.com/ref.png', 'mime' => 'image/png', 'name' => 'ref.png', 'size' => 512, 'extra' => 'dropped'],
                    ['type' => 'document', 'url' => 'https://files.example.com/brief.pdf', 'name' => 'brief.pdf'],
                ],
            ])
            ->assertStatus(202)
            ->json('data.id');

        $prompt = Message::findOrFail(AgentRun::findOrFail($id)->prompt_message_id);
        $this->assertSame([
            ['type' => 'image', 'url' => 'https://files.example.com/ref.png', 'mime' => 'image/png', 'name' => 'ref.png', 'size' => 512],
            ['type' => 'document', 'url' => 'https://files.example.com/brief.pdf', 'name' => 'brief.pdf'],
        ], $prompt->attachments);

        [$image, $document] = $this->harness->media[0];
        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame('https://files.example.com/ref.png', $image->url());
        $this->assertInstanceOf(Document::class, $document);
        $this->assertSame('https://files.example.com/brief.pdf', $document->url());
        $this->assertStringEndsWith('User: Match this style [image: ref.png] [document: brief.pdf]', $this->harness->inputs[0]);
    }

    #[Test]
    public function an_attachment_can_be_the_whole_prompt(): void
    {
        config(['agent-chat.attachments.url_hosts' => ['*.example.com']]);

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", [
                'attachments' => [['type' => 'image', 'url' => 'https://files.example.com/ref.png', 'name' => 'ref.png']],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');

        $this->assertStringEndsWith('User: [image: ref.png]', $this->harness->inputs[0]);
    }

    #[Test]
    public function attachments_must_come_from_an_allowed_host_or_disk(): void
    {
        config(['agent-chat.attachments.url_hosts' => ['files.example.com'], 'agent-chat.attachments.disks' => ['uploads']]);
        $conversation = $this->conversation();
        $this->actingAs($this->user());

        $cases = [
            ['attachments.0.url', ['type' => 'image', 'url' => 'https://169.254.169.254/latest/meta-data']],
            ['attachments.0.url', ['type' => 'image', 'url' => 'https://evil.example.org/x.png']],
            ['attachments.0.url', ['type' => 'image', 'url' => 'http://files.example.com/ref.png']],
            ['attachments.0.disk', ['type' => 'image', 'disk' => 'local', 'path' => '.env']],
            ['attachments.0.path', ['type' => 'image', 'disk' => 'uploads', 'path' => '../.env']],
            ['attachments.0.type', ['type' => 'script', 'url' => 'https://files.example.com/x.js']],
            ['attachments.0', ['type' => 'image', 'name' => 'nowhere.png']],
        ];

        foreach ($cases as [$field, $attachment]) {
            $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Look', 'attachments' => [$attachment]])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, Message::count());
        $this->assertSame([], $this->harness->inputs);
    }

    #[Test]
    public function an_attachment_on_an_allowed_disk_is_read_from_storage(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('refs/ref.png', base64_decode(self::PNG));
        config(['agent-chat.attachments.disks' => ['uploads']]);

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", [
                'body' => 'Use this',
                'attachments' => [['type' => 'image', 'disk' => 'uploads', 'path' => 'refs/ref.png', 'name' => 'ref.png']],
            ])
            ->assertStatus(202);

        $image = $this->harness->media[0][0];
        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame(base64_decode(self::PNG), $image->rawContent());
        $this->assertSame('image/png', $image->mimeType());
    }

    #[Test]
    public function earlier_images_are_named_in_the_history_but_not_sent_again(): void
    {
        config(['agent-chat.attachments.url_hosts' => ['files.example.com']]);
        $conversation = $this->conversation();
        $this->actingAs($this->user());

        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", [
            'body' => 'Look at this',
            'attachments' => [['type' => 'image', 'url' => 'https://files.example.com/ref.png', 'name' => 'ref.png']],
        ]);
        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Now make it blue']);

        $this->assertCount(1, $this->harness->media[0]);
        $this->assertSame([], $this->harness->media[1]);
        $this->assertStringContainsString('User: Look at this [image: ref.png]', $this->harness->inputs[1]);
    }

    #[Test]
    public function files_the_agent_makes_are_attached_to_the_reply_and_shown_as_image_blocks(): void
    {
        Agents::registerTool(new MakeImageTool);

        $this->harness->script(function (callable $call) {
            $call('make_image');

            return AgentResult::success('Here is the poster.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Make a poster'])
            ->json('data.id');

        $attachment = [
            'type' => 'image',
            'url' => 'https://cdn.example.com/design.png',
            'mime' => 'image/png',
            'name' => 'design.png',
            'size' => 2048,
            'ref' => ['type' => 'library_asset', 'id' => 7],
        ];

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.reply.attachments', [$attachment])
            ->assertJsonPath('data.reply.blocks.1', [
                'type' => 'image',
                'src' => 'https://cdn.example.com/design.png',
                'alt' => 'The poster',
                'attachment' => 0,
                'ref' => ['type' => 'library_asset', 'id' => 7],
            ]);

        $this->assertSame([$attachment], Message::findOrFail(AgentRun::findOrFail($id)->reply_message_id)->attachments);
    }

    #[Test]
    public function a_file_with_the_wrong_shape_cannot_be_attached_to_a_reply(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid attachment');

        (new BlockCollector)->attach(['type' => 'hologram', 'url' => 'https://cdn.example.com/x']);
    }
}
