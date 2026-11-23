<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Passkeys;

/**
 * Wrapper marking a string as CBOR byte-string data.
 *
 * Used by Cbor::encodeTestValue() (test support) so the encoder can
 * tell a byte string apart from a text string. The server never
 * encodes CBOR in production; the browser does.
 */
final class CborBytes
{
    /**
     * Raw bytes.
     */
    public readonly string $bytes;

    /**
     * @param string $bytes Raw bytes.
     */
    public function __construct(string $bytes)
    {
        $this->bytes = $bytes;
    }
}
