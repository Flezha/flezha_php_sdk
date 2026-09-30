<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Exception\DecodeException;
use Flezha\Exception\FlezhaException;

/**
 * One outcome of a `call(...)`, as its Response channel delivers it: the
 * request the call end sent, decoded back with the call's codec; the answer
 * as `value`, or the `error` the call ended in (a missed deadline is
 * `call.timeout`); `target`, the callee's instance id; and `created`, true
 * when this call started the callee.
 */
final readonly class Response
{
    public function __construct(
        public mixed $request,
        public mixed $value,
        public ?FlezhaException $error,
        public string $target,
        public bool $created,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }

    /**
     * The Response a controller's envelope carries: a JSON object whose
     * `request` and `value` are padded base64 and whose `error` is an error
     * object. `$decode` reads the request's and the answer's bytes.
     *
     * @param callable(string):mixed $decode
     */
    public static function decode(string $payload, callable $decode): self
    {
        /** @var array{target: string, created: bool, request: string, value?: string, error?: array<string, mixed>} $envelope */
        $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $error = isset($envelope['error']) ? FlezhaException::fromObject($envelope['error']) : null;

        return new self(
            request: $decode(self::bytes($envelope['request'])),
            value: $error === null ? $decode(self::bytes($envelope['value'] ?? '')) : null,
            error: $error,
            target: $envelope['target'],
            created: $envelope['created'],
        );
    }

    private static function bytes(string $base64): string
    {
        $bytes = base64_decode($base64, true);

        return $bytes !== false
            ? $bytes
            : throw new DecodeException('a Response envelope carries bytes that are not base64');
    }
}
