<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Exceptions;

use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Exceptions\LockedExceptionInterface;

class LockedException extends PermissionException implements LockedExceptionInterface
{
}
