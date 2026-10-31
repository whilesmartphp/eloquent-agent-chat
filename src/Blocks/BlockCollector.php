<?php

namespace Whilesmart\AgentChat\Blocks;

use Closure;
use InvalidArgumentException;
use Whilesmart\AgentChat\Attachments\Attachment;

/**
 * Collects the blocks and files tools produce during one attempt. A fresh
 * collector is bound for each attempt so nothing leaks between attempts.
 */
class BlockCollector
{
    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    /** @var array<int, array<string, mixed>> */
    private array $attachments = [];

    private ?string $canvasTitle = null;

    /**
     * @param  (Closure(array<int, array<string, mixed>>): void)|null  $onChange  Called with every block so far after one is added.
     */
    public function __construct(private ?Closure $onChange = null) {}

    /**
     * Add a block. Its type must be a core type or one listed under
     * `agent-chat.block_types`. A question block is normalized to the same
     * shape `ask_question` draws, so it gets ids and can be answered.
     *
     * @param  array<string, mixed>  $block
     */
    public function add(array $block): void
    {
        $this->blocks[] = self::normalize($block);

        if ($this->onChange !== null) {
            ($this->onChange)($this->blocks);
        }
    }

    /**
     * Check a block's type and return it in its stored shape.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function normalize(array $block): array
    {
        $type = $block['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::types(), true)) {
            throw new InvalidArgumentException('Block type '.json_encode($type).' is not registered. Add it to agent-chat.block_types.');
        }

        if ($type !== 'question') {
            return $block;
        }

        return app(BlockBuilder::class)->questionFrom($block)
            ?? throw new InvalidArgumentException('A question block needs a prompt and at least one option.');
    }

    /**
     * Attach a file to the reply message, so channels that send media deliver
     * it. An image is also shown as an image block unless `$show` is false.
     * Returns the file's position in the reply's attachments.
     *
     * @param  array<string, mixed>  $attachment
     */
    public function attach(array $attachment, ?string $alt = null, bool $show = true): int
    {
        $attachment = Attachment::normalize($attachment);
        $index = count($this->attachments);
        $this->attachments[] = $attachment;

        if ($show && $attachment['type'] === 'image') {
            $this->add(app(BlockBuilder::class)->image(
                $attachment['url'] ?? null,
                $alt ?? ($attachment['name'] ?? null),
                $index,
                $attachment['ref'] ?? null,
            ));
        }

        return $index;
    }

    public function openCanvas(string $title): void
    {
        $this->canvasTitle = $title !== '' ? $title : 'Report';
    }

    public function canvasTitle(): ?string
    {
        return $this->canvasTitle;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return array_values(array_unique([
            ...BlockBuilder::CORE_TYPES,
            ...array_map('strval', (array) config('agent-chat.block_types', [])),
        ]));
    }
}
