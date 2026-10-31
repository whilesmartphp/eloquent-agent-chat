<?php

namespace Whilesmart\AgentChat\Runs;

use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Whilesmart\AgentChat\Attachments\Attachment;
use Whilesmart\AgentChat\Contracts\MediaResolver;
use Whilesmart\AgentChat\Models\AgentRun;

/**
 * Shows the model the images and documents attached to the prompt. Sources
 * are checked against the allowed hosts and disks again here, because a
 * message can be written by code other than the run endpoint. Attachments
 * that only carry a `ref` need a host resolver.
 */
class DefaultMediaResolver implements MediaResolver
{
    public function resolve(AgentRun $run): array
    {
        $media = [];

        foreach ((array) ($run->promptMessage?->attachments ?? []) as $attachment) {
            if (is_array($attachment) && ($item = $this->media($attachment)) !== null) {
                $media[] = $item;
            }
        }

        return $media;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    protected function media(array $attachment): mixed
    {
        $type = $attachment['type'] ?? null;
        $url = is_string($attachment['url'] ?? null) ? $attachment['url'] : null;
        $disk = is_string($attachment['disk'] ?? null) ? $attachment['disk'] : null;
        $path = is_string($attachment['path'] ?? null) ? $attachment['path'] : null;
        $name = is_string($attachment['name'] ?? null) ? $attachment['name'] : null;
        $mime = is_string($attachment['mime'] ?? null) ? $attachment['mime'] : null;

        if ($disk !== null && $path !== null && Attachment::allowedDisk($disk)) {
            return match ($type) {
                'image' => Image::fromStoragePath($path, $disk),
                'document' => Document::fromStoragePath($path, $disk, $name),
                default => null,
            };
        }

        if ($url !== null && Attachment::allowedUrl($url)) {
            return match ($type) {
                'image' => Image::fromUrl($url, $mime),
                'document' => Document::fromUrl($url, $name),
                default => null,
            };
        }

        return null;
    }
}
