<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

/**
 * Exception raised when CBOR input is malformed.
 *
 * Callers translate this into a generic verification failure; the
 * message itself is never shown to the visitor.
 */
final class CborException extends \Exception
{
}
