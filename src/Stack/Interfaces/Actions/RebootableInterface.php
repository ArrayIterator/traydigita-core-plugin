<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Interfaces\Actions;

interface RebootableInterface extends BootableInterface
{
    /**
     * Reboot the object
     */
    public function reboot(); // @phpstan-ignore missingType.return
}
