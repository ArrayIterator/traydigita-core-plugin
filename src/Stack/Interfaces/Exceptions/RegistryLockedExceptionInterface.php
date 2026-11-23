<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Interfaces\Exceptions;

use Psr\Container\ContainerExceptionInterface;

interface RegistryLockedExceptionInterface extends
    LockedExceptionInterface,
    RegistryExceptionInterface,
    ContainerExceptionInterface
{
}
