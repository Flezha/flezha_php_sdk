<?php

declare(strict_types=1);

namespace Flezha;

use Closure;
use Flezha\Codec\Json;
use Flezha\Exception\DecodeException;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\ChannelSpec;

/**
 * The payload format a channel names on `input` and `sink`.
 *
 * A channel that names none reads its entry payload as JSON, and sends a sink
 * value by what it is: a string as it stands, anything else as JSON. A payload
 * between two tasklets names none either way and stays JSON.
 *
 * A refusal reads the channel's name when it is raised, so it names the
 * channel as the class finally declares it.
 */
enum ChannelCodec: string
{
    case Bytes = 'bytes';
    case Text = 'text';
    case Json = 'json';

    /** @return Closure(string):mixed */
    public function decoder(ChannelSpec $channel): Closure
    {
        return match ($this) {
            self::Bytes => static fn (string $bytes): string => $bytes,
            self::Text => static fn (string $bytes): string => Json::isUtf8($bytes)
                ? $bytes
                : throw new DecodeException(sprintf('channel `%s`: the payload is not valid UTF-8', $channel->name)),
            self::Json => Json::decode(...),
        };
    }

    /** @return Closure(mixed):string */
    public function encoder(ChannelSpec $channel): Closure
    {
        return match ($this) {
            self::Bytes => fn (mixed $value): string => is_string($value)
                ? $value
                : $this->refuse($channel, $value),
            self::Text => fn (mixed $value): string => is_string($value) && Json::isUtf8($value)
                ? $value
                : $this->refuse($channel, $value),
            self::Json => static fn (mixed $value): string => Json::sent("channel '{$channel->name}'", $value),
        };
    }

    /**
     * The encoder of a sink that names no codec: a string is sent as it
     * stands, and any other value as JSON.
     *
     * @return Closure(mixed):string
     */
    public static function defaultEncoder(ChannelSpec $channel): Closure
    {
        return static fn (mixed $value): string => self::plain($value, "channel '{$channel->name}'");
    }

    /**
     * What a sink naming no codec sends, and what a steps completion
     * carries: a string as it stands, any other value as JSON. `$owner` is
     * what a refusal names.
     */
    public static function plain(mixed $value, string $owner): string
    {
        return is_string($value)
            ? $value
            : Json::sent($owner, $value);
    }

    private function refuse(ChannelSpec $channel, mixed $value): never
    {
        throw new FlezhaException('runtime.value_wrong_for_codec', [
            'channel' => $channel->name,
            'codec' => $this->value,
            'type' => is_string($value) ? 'string that is not valid UTF-8' : get_debug_type($value),
        ]);
    }
}
