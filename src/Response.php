<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;

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
     * The Response a controller's envelope carries, read by the core's
     * decoder; a payload that is no envelope throws `call.malformed_response`.
     * `$decode` reads the request's and the answer's bytes.
     *
     * @param callable(string):mixed $decode
     */
    public static function decode(string $payload, callable $decode): self
    {
        [$target, $created, $request, $answered, $result] = Core::responseDecode($payload);
        $request = $decode($request);

        return new self(
            request: $request,
            value: $answered ? $decode($result) : null,
            error: $answered ? null : FlezhaException::fromDocument($result),
            target: $target,
            created: $created,
        );
    }
}
