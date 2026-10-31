<?php

namespace Whilesmart\AgentChat\Attachments;

use Closure;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The shape of one item in a message's attachments list. A file is located by
 * an https url or by a storage disk and path; `ref` points at a host record.
 */
final class Attachment
{
    public const TYPES = ['image', 'document', 'audio', 'video', 'file'];

    private const KEYS = ['type', 'url', 'disk', 'path', 'mime', 'name', 'size', 'ref'];

    /**
     * Rules for one attachment at `$key` (for example `attachments.*`).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $key): array
    {
        return [
            $key => ['array', self::locatable()],
            "{$key}.type" => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            "{$key}.url" => ['nullable', 'string', 'max:2048', 'url:https'],
            "{$key}.disk" => ['nullable', 'string', 'max:64', "required_with:{$key}.path"],
            "{$key}.path" => ['nullable', 'string', 'max:1024', 'not_regex:/(^|[\\\\\/])\.\.([\\\\\/]|$)/'],
            "{$key}.mime" => ['nullable', 'string', 'max:255'],
            "{$key}.name" => ['nullable', 'string', 'max:255'],
            "{$key}.size" => ['nullable', 'integer', 'min:0'],
            "{$key}.ref" => ['nullable', 'array'],
            "{$key}.ref.type" => ["required_with:{$key}.ref", 'string', 'max:255'],
            "{$key}.ref.id" => ["required_with:{$key}.ref"],
        ];
    }

    /**
     * Rules for attachments a person sends: a url must be on an allowed host
     * and a path on an allowed disk, because the media resolver fetches them
     * on the server.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function requestRules(string $key): array
    {
        $rules = self::rules($key);
        $rules["{$key}.url"][] = function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && ! self::allowedUrl($value)) {
                $fail('Attachments can only link to an allowed file host.');
            }
        };
        $rules["{$key}.disk"][] = function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && ! self::allowedDisk($value)) {
                $fail('Attachments can only point at an allowed storage disk.');
            }
        };

        return $rules;
    }

    /**
     * Validate an attachment and keep only the known keys.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    public static function normalize(array $attachment): array
    {
        $validator = Validator::make(['attachment' => $attachment], self::rules('attachment'));

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid attachment: '.$validator->errors()->first());
        }

        return array_filter(
            array_intersect_key($attachment, array_flip(self::KEYS)),
            fn ($value) => $value !== null,
        );
    }

    public static function allowedUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        foreach ((array) config('agent-chat.attachments.url_hosts', []) as $pattern) {
            if (fnmatch(strtolower((string) $pattern), strtolower($host))) {
                return true;
            }
        }

        return false;
    }

    public static function allowedDisk(string $disk): bool
    {
        return in_array($disk, array_map('strval', (array) config('agent-chat.attachments.disks', [])), true);
    }

    private static function locatable(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_array($value) && empty($value['url']) && empty($value['path']) && empty($value['ref'])) {
                $fail('An attachment needs a url, a disk and path, or a ref.');
            }
        };
    }
}
