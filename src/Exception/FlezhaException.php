<?php

declare(strict_types=1);

namespace Flezha\Exception;

use Flezha\Ffi\Core;
use RuntimeException;
use Throwable;

/**
 * Anything Flezha refuses: the controller, the core, or this package itself.
 *
 * `getCode()` answers the code (`family.snake`, as `PDOException` answers its
 * SQLSTATE) and `errorClass` the class (`invalid`, `not_found`, `denied`, ...):
 * what a program branches on. `getMessage()` is one sentence for a reader,
 * `details` names what the message is about, and `hint` is the next command to
 * run, when there is one.
 *
 * A refusal this package makes is built from its code and details:
 *
 *     throw new FlezhaException('topology.duplicate_factory_id', ['factory_id' => 'echo']);
 */
final class FlezhaException extends RuntimeException
{
    /** @var string the catalogue code */
    protected $code = '';

    public readonly string $errorClass;

    /** @var array<string, mixed> */
    public readonly array $details;

    public readonly ?string $hint;

    /** @param array<string, mixed> $details */
    public function __construct(string $code, array $details = [], ?Throwable $previous = null)
    {
        parent::__construct('', 0, $previous);
        $this->fill(self::errorOf(Core::errorDocument($code, $details)));
    }

    /**
     * The error a JSON error document `{"error": {...}}` carries: what
     * `flz_core_last_error` answers for every failure the core records.
     */
    public static function fromDocument(string $document): self
    {
        return self::fromObject(self::errorOf($document));
    }

    /**
     * The error a decoded error object (`code`, `class`, `message`,
     * `details`, `hint`) carries, as a controller writes one into a call's
     * outcome.
     *
     * @param array<string, mixed> $error
     */
    public static function fromObject(array $error): self
    {
        $self = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $self->fill($error);

        return $self;
    }

    /** @return array<string, mixed> */
    private static function errorOf(string $document): array
    {
        /** @var array{error: array<string, mixed>} $decoded */
        $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['error'];
    }

    /** @param array<string, mixed> $error */
    private function fill(array $error): void
    {
        /** @var array{code: string, class: string, message: string, details?: array<string, mixed>|null, hint?: string|null} $error */
        $this->message = $error['message'];
        $this->code = $error['code'];
        $this->errorClass = $error['class'];
        $this->details = $error['details'] ?? [];
        $this->hint = $error['hint'] ?? null;
    }
}
