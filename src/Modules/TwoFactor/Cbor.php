<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use function chr;
use function count;
use function is_int;
use function is_string;
use function mb_check_encoding;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;
use const INF;
use const NAN;

/**
 * Minimal CBOR decoder (RFC 8949).
 *
 * WebAuthn attestation objects and COSE keys are CBOR, so the
 * WebAuthn server needs a decoder — but the theme ships zero
 * runtime Composer dependencies, and pulling in a CBOR library
 * for one decode path would be disproportionate. This decoder
 * covers what WebAuthn produces: unsigned and negative integers,
 * byte and text strings (definite and indefinite length), arrays
 * and maps (definite and indefinite length), simple values
 * (false, true, null, undefined), half/single/double floats, and
 * tags (the tag number is dropped and the content is decoded).
 *
 * Hardened for untrusted input: a maximum input size (64 KiB —
 * an attestation object is a few hundred bytes), a maximum
 * nesting depth (32), duplicate map keys are rejected, text
 * strings must be valid UTF-8, and every length is checked
 * against the remaining input before any read. Anything malformed
 * raises CborException; callers translate that into a generic
 * "verification failed" without leaking the detail.
 *
 * Decoded values: integers as PHP int (values above PHP_INT_MAX
 * raise, since WebAuthn never produces them), byte strings as PHP
 * string, text strings as PHP string, arrays as list arrays, maps
 * as PHP arrays keyed by int or string. Map keys of any other
 * type raise.
 */
final class Cbor
{
    /**
     * Maximum CBOR input in bytes.
     */
    public const int MAX_INPUT_BYTES = 65536;

    /**
     * Maximum nesting depth (arrays/maps/tags).
     */
    public const int MAX_DEPTH = 32;

    /**
     * Decode a complete CBOR value.
     *
     * The whole input must be exactly one value — trailing bytes
     * raise, so a concatenated second value cannot hide behind a
     * valid first one.
     *
     * @param string $data CBOR bytes.
     * @return mixed Decoded value.
     * @throws CborException When the input is malformed.
     */
    public static function decode(string $data): mixed
    {
        if (strlen($data) > self::MAX_INPUT_BYTES) {
            throw new CborException('CBOR input is too large.');
        }
        if ($data === '') {
            throw new CborException('CBOR input is empty.');
        }
        $result = self::decodeAt($data, 0, 0);
        if ($result['length'] !== strlen($data)) {
            throw new CborException('Trailing bytes after the CBOR value.');
        }
        return $result['value'];
    }

    /**
     * Decode one CBOR value starting at an offset.
     *
     * Returns the value and the number of bytes it consumed, so the
     * WebAuthn server can read a COSE key out of the middle of an
     * authenticator-data blob and then continue past it.
     *
     * @param string $data CBOR bytes.
     * @param int $offset Byte offset to start at.
     * @param int $depth Current nesting depth.
     * @return array{value: mixed, length: int}
     * @throws CborException When the input is malformed.
     */
    public static function decodeAt(string $data, int $offset, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CborException('CBOR nesting is too deep.');
        }
        $total = strlen($data);
        if ($offset < 0 || $offset >= $total) {
            throw new CborException('CBOR offset is out of range.');
        }
        $initial = ord($data[$offset]);
        $major = $initial >> 5;
        $info = $initial & 31;
        $pos = $offset + 1;

        // Additional-information 28/29/30 are reserved.
        if ($info >= 28 && $info <= 30) {
            throw new CborException('Reserved CBOR additional information.');
        }

        switch ($major) {
            case 0:
                // Unsigned integer.
                $value = self::readArgument($data, $pos, $info, $total);
                return ['value' => $value['value'], 'length' => ($pos + $value['length']) - $offset];
            case 1:
                // Negative integer: -1 - n.
                $value = self::readArgument($data, $pos, $info, $total);
                return ['value' => -1 - $value['value'], 'length' => ($pos + $value['length']) - $offset];
            case 2:
                // Byte string.
                if ($info === 31) {
                    return self::readIndefiniteString($data, $pos, $offset, 2, $depth);
                }
                $value = self::readArgument($data, $pos, $info, $total);
                $length = $value['value'];
                $start = $pos + $value['length'];
                self::requireBytes($total, $start, $length);
                return ['value' => substr($data, $start, $length), 'length' => ($start + $length) - $offset];
            case 3:
                // Text string.
                if ($info === 31) {
                    return self::readIndefiniteString($data, $pos, $offset, 3, $depth);
                }
                $value = self::readArgument($data, $pos, $info, $total);
                $length = $value['value'];
                $start = $pos + $value['length'];
                self::requireBytes($total, $start, $length);
                $text = substr($data, $start, $length);
                if (!mb_check_encoding($text, 'UTF-8')) {
                    throw new CborException('CBOR text string is not valid UTF-8.');
                }
                return ['value' => $text, 'length' => ($start + $length) - $offset];
            case 4:
                // Array.
                if ($info === 31) {
                    return self::readIndefiniteCollection($data, $pos, $offset, $depth, false);
                }
                $value = self::readArgument($data, $pos, $info, $total);
                $count = $value['value'];
                $items = [];
                $cursor = $pos + $value['length'];
                for ($i = 0; $i < $count; $i++) {
                    $item = self::decodeAt($data, $cursor, $depth + 1);
                    $items[] = $item['value'];
                    $cursor += $item['length'];
                }
                return ['value' => $items, 'length' => $cursor - $offset];
            case 5:
                // Map.
                if ($info === 31) {
                    return self::readIndefiniteCollection($data, $pos, $offset, $depth, true);
                }
                $value = self::readArgument($data, $pos, $info, $total);
                $count = $value['value'];
                $map = [];
                $cursor = $pos + $value['length'];
                for ($i = 0; $i < $count; $i++) {
                    $key = self::decodeAt($data, $cursor, $depth + 1);
                    $cursor += $key['length'];
                    $entry = self::decodeAt($data, $cursor, $depth + 1);
                    $cursor += $entry['length'];
                    $mapKey = self::mapKey($key['value']);
                    if (isset($map[$mapKey])) {
                        throw new CborException('Duplicate key in CBOR map.');
                    }
                    $map[$mapKey] = $entry['value'];
                }
                return ['value' => $map, 'length' => $cursor - $offset];
            case 6:
                // Tag: decode the content, drop the tag number.
                $value = self::readArgument($data, $pos, $info, $total);
                $content = self::decodeAt($data, $pos + $value['length'], $depth + 1);
                return ['value' => $content['value'], 'length' => ($pos + $value['length'] + $content['length']) - $offset];
            case 7:
                // Simple value / float.
                return self::readSimpleOrFloat($data, $pos, $info, $offset, $total);
            default:
                throw new CborException('Unknown CBOR major type.');
        }
    }

    /**
     * Read the argument of a CBOR head.
     *
     * Additional information 0–23 is the value itself; 24 reads one
     * byte, 25 two, 26 four, 27 eight. Eight-byte values above
     * PHP_INT_MAX raise — WebAuthn never produces them, and silently
     * truncating an integer would be worse than refusing it.
     *
     * @param string $data CBOR bytes.
     * @param int $pos Offset just after the head byte.
     * @param int $info Additional information (0–27).
     * @param int $total Total input length.
     * @return array{value: int, length: int} Value and bytes consumed after the head.
     * @throws CborException When the argument is truncated or too large.
     */
    private static function readArgument(string $data, int $pos, int $info, int $total): array
    {
        if ($info < 24) {
            return ['value' => $info, 'length' => 0];
        }
        if ($info === 24) {
            self::requireBytes($total, $pos, 1);
            return ['value' => ord($data[$pos]), 'length' => 1];
        }
        if ($info === 25) {
            self::requireBytes($total, $pos, 2);
            $bytes = unpack('n', substr($data, $pos, 2));
            if ($bytes === false) {
                throw new CborException('Cannot read CBOR argument.');
            }
            return ['value' => (int) $bytes[1], 'length' => 2];
        }
        if ($info === 26) {
            self::requireBytes($total, $pos, 4);
            $bytes = unpack('N', substr($data, $pos, 4));
            if ($bytes === false) {
                throw new CborException('Cannot read CBOR argument.');
            }
            return ['value' => (int) $bytes[1], 'length' => 4];
        }
        // info === 27: eight-byte unsigned integer.
        self::requireBytes($total, $pos, 8);
        $bytes = unpack('J', substr($data, $pos, 8));
        if ($bytes === false) {
            throw new CborException('Cannot read CBOR argument.');
        }
        $value = $bytes[1];
        if (!is_int($value) || $value < 0) {
            throw new CborException('CBOR integer is too large.');
        }
        return ['value' => $value, 'length' => 8];
    }

    /**
     * Read an indefinite-length byte or text string.
     *
     * Chunks are concatenated until the break byte (0xFF). A chunk
     * of the other string type raises, and a nested indefinite
     * string raises (chunks must be definite-length).
     *
     * @param string $data CBOR bytes.
     * @param int $pos Offset of the first chunk head.
     * @param int $offset Offset of the enclosing value head.
     * @param int $major 2 for byte strings, 3 for text strings.
     * @param int $depth Current nesting depth.
     * @return array{value: string, length: int}
     * @throws CborException When the string is malformed.
     */
    private static function readIndefiniteString(string $data, int $pos, int $offset, int $major, int $depth): array
    {
        $total = strlen($data);
        $parts = '';
        $cursor = $pos;
        while (true) {
            if ($cursor >= $total) {
                throw new CborException('Unterminated indefinite-length CBOR string.');
            }
            $head = ord($data[$cursor]);
            if ($head === 0xFF) {
                $cursor++;
                break;
            }
            $chunkMajor = $head >> 5;
            $chunkInfo = $head & 31;
            if ($chunkMajor !== $major || $chunkInfo === 31) {
                throw new CborException('Invalid chunk in indefinite-length CBOR string.');
            }
            $chunk = self::decodeAt($data, $cursor, $depth + 1);
            if (!is_string($chunk['value'])) {
                throw new CborException('Invalid chunk in indefinite-length CBOR string.');
            }
            $parts .= $chunk['value'];
            $cursor += $chunk['length'];
        }
        if ($major === 3 && !mb_check_encoding($parts, 'UTF-8')) {
            throw new CborException('CBOR text string is not valid UTF-8.');
        }
        return ['value' => $parts, 'length' => $cursor - $offset];
    }

    /**
     * Read an indefinite-length array or map.
     *
     * Items are decoded until the break byte (0xFF). For maps, keys
     * and values alternate; a trailing lone key raises.
     *
     * @param string $data CBOR bytes.
     * @param int $pos Offset of the first item head.
     * @param int $offset Offset of the enclosing value head.
     * @param int $depth Current nesting depth.
     * @param bool $isMap True for a map, false for an array.
     * @return array{value: mixed, length: int}
     * @throws CborException When the collection is malformed.
     */
    private static function readIndefiniteCollection(string $data, int $pos, int $offset, int $depth, bool $isMap): array
    {
        $total = strlen($data);
        $items = [];
        $map = [];
        $cursor = $pos;
        while (true) {
            if ($cursor >= $total) {
                throw new CborException('Unterminated indefinite-length CBOR collection.');
            }
            if (ord($data[$cursor]) === 0xFF) {
                $cursor++;
                break;
            }
            $item = self::decodeAt($data, $cursor, $depth + 1);
            $cursor += $item['length'];
            if (!$isMap) {
                $items[] = $item['value'];
                continue;
            }
            if ($cursor >= $total || ord($data[$cursor]) === 0xFF) {
                throw new CborException('Map key without a value in CBOR map.');
            }
            $entry = self::decodeAt($data, $cursor, $depth + 1);
            $cursor += $entry['length'];
            $mapKey = self::mapKey($item['value']);
            if (isset($map[$mapKey])) {
                throw new CborException('Duplicate key in CBOR map.');
            }
            $map[$mapKey] = $entry['value'];
        }
        return ['value' => $isMap ? $map : $items, 'length' => $cursor - $offset];
    }

    /**
     * Read a major-type-7 head: simple value or float.
     *
     * Additional information 20/21/22/23 are false/true/null/
     * undefined; 24 is a one-byte simple value (only values below
     * 32 are legal there); 25/26/27 are half/single/double floats.
     *
     * @param string $data CBOR bytes.
     * @param int $pos Offset just after the head byte.
     * @param int $info Additional information.
     * @param int $offset Offset of the enclosing value head.
     * @param int $total Total input length.
     * @return array{value: mixed, length: int}
     * @throws CborException When the value is malformed.
     */
    private static function readSimpleOrFloat(string $data, int $pos, int $info, int $offset, int $total): array
    {
        if ($info === 20) {
            return ['value' => false, 'length' => $pos - $offset];
        }
        if ($info === 21) {
            return ['value' => true, 'length' => $pos - $offset];
        }
        if ($info === 22) {
            return ['value' => null, 'length' => $pos - $offset];
        }
        if ($info === 23) {
            // Undefined: no PHP equivalent; decode as null.
            return ['value' => null, 'length' => $pos - $offset];
        }
        if ($info === 24) {
            self::requireBytes($total, $pos, 1);
            $value = ord($data[$pos]);
            if ($value < 32) {
                throw new CborException('Invalid one-byte CBOR simple value.');
            }
            return ['value' => $value, 'length' => ($pos + 1) - $offset];
        }
        if ($info === 25) {
            self::requireBytes($total, $pos, 2);
            return ['value' => self::decodeHalfFloat(substr($data, $pos, 2)), 'length' => ($pos + 2) - $offset];
        }
        if ($info === 26) {
            self::requireBytes($total, $pos, 4);
            $bytes = unpack('G', substr($data, $pos, 4));
            if ($bytes === false) {
                throw new CborException('Cannot read CBOR float.');
            }
            return ['value' => (float) $bytes[1], 'length' => ($pos + 4) - $offset];
        }
        if ($info === 27) {
            self::requireBytes($total, $pos, 8);
            $bytes = unpack('E', substr($data, $pos, 8));
            if ($bytes === false) {
                throw new CborException('Cannot read CBOR float.');
            }
            return ['value' => (float) $bytes[1], 'length' => ($pos + 8) - $offset];
        }
        throw new CborException('Invalid CBOR simple value.');
    }

    /**
     * Decode a 16-bit half-precision float.
     *
     * @param string $bytes Two big-endian bytes.
     * @return float
     */
    private static function decodeHalfFloat(string $bytes): float
    {
        $bits = (ord($bytes[0]) << 8) | ord($bytes[1]);
        $sign = ($bits & 0x8000) !== 0 ? -1.0 : 1.0;
        $exponent = ($bits >> 10) & 31;
        $fraction = $bits & 1023;
        if ($exponent === 0) {
            return $sign * $fraction * (2.0 ** -24);
        }
        if ($exponent === 31) {
            return $fraction === 0 ? $sign * INF : NAN;
        }
        return $sign * (1.0 + $fraction / 1024.0) * (2.0 ** ($exponent - 15));
    }

    /**
     * Turn a decoded map key into a PHP array key.
     *
     * Only integers and strings are allowed as map keys (COSE maps
     * use integer keys, attestation objects use text keys). Anything
     * else raises instead of being silently stringified.
     *
     * @param mixed $key Decoded key.
     * @return int|string
     * @throws CborException When the key type is not allowed.
     */
    private static function mapKey(mixed $key): int|string
    {
        if (is_int($key) || is_string($key)) {
            return $key;
        }
        throw new CborException('Unsupported CBOR map key type.');
    }

    /**
     * Require that $length bytes are available from $start.
     *
     * @param int $total Total input length.
     * @param int $start Start offset.
     * @param int $length Bytes required.
     * @return void
     * @throws CborException When the input ends first.
     */
    private static function requireBytes(int $total, int $start, int $length): void
    {
        if ($start < 0 || $length < 0 || $start + $length > $total) {
            throw new CborException('Truncated CBOR input.');
        }
    }

    /**
     * Encode a PHP value as CBOR (test support).
     *
     * The server never encodes CBOR — the browser does — but the
     * test harness needs an encoder to build synthetic attestation
     * objects, so a small one lives here. It covers ints, strings,
     * byte strings (wrapped in CborBytes), booleans, null, floats,
     * lists, and maps with int/string keys.
     *
     * @param mixed $value Value to encode.
     * @return string CBOR bytes.
     */
    public static function encodeTestValue(mixed $value): string
    {
        if ($value instanceof CborBytes) {
            return self::encodeHead(2, strlen($value->bytes)) . $value->bytes;
        }
        if (is_int($value)) {
            if ($value >= 0) {
                return self::encodeHead(0, $value);
            }
            return self::encodeHead(1, -1 - $value);
        }
        if (is_string($value)) {
            return self::encodeHead(3, strlen($value)) . $value;
        }
        if (is_bool($value)) {
            return chr(0xE0 | ($value ? 21 : 20));
        }
        if ($value === null) {
            return chr(0xE0 | 22);
        }
        if (is_float($value)) {
            return chr(0xFB) . pack('E', $value);
        }
        if (is_array($value)) {
            $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
            $out = '';
            if ($isList) {
                $out .= self::encodeHead(4, count($value));
                foreach ($value as $item) {
                    $out .= self::encodeTestValue($item);
                }
                return $out;
            }
            $out .= self::encodeHead(5, count($value));
            foreach ($value as $key => $item) {
                $out .= self::encodeTestValue($key);
                $out .= self::encodeTestValue($item);
            }
            return $out;
        }
        throw new CborException('Cannot encode value as CBOR.');
    }

    /**
     * Encode a CBOR head (major type + argument).
     *
     * @param int $major Major type (0–7).
     * @param int $argument Argument value.
     * @return string Head bytes.
     */
    private static function encodeHead(int $major, int $argument): string
    {
        $base = $major << 5;
        if ($argument < 24) {
            return chr($base | $argument);
        }
        if ($argument < 256) {
            return chr($base | 24) . chr($argument);
        }
        if ($argument < 65536) {
            return chr($base | 25) . pack('n', $argument);
        }
        if ($argument < 4294967296) {
            return chr($base | 26) . pack('N', $argument);
        }
        return chr($base | 27) . pack('J', $argument);
    }
}
