<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

/**
 * Exception raised when a WebAuthn ceremony fails.
 *
 * Callers translate this into a generic "verification failed"
 * message; the message itself is never shown to the visitor, and
 * the reason code (see WebAuthnException::reason) is only written
 * to the module's failed-attempt log for the administrator.
 */
final class WebAuthnException extends \Exception
{
    /**
     * Short machine-readable reason for the failure.
     */
    public readonly string $reason;

    /**
     * @param string $reason Machine-readable reason (e.g. 'bad_origin').
     * @param string $message Human-readable detail for the log only.
     */
    public function __construct(string $reason, string $message)
    {
        $this->reason = $reason;
        parent::__construct($message);
    }
}
