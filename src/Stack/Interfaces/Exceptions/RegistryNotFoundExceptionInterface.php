<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Interfaces\Exceptions;

use Psr\Container\NotFoundExceptionInterface as PsrNotFoundExceptionInterface;

interface RegistryNotFoundExceptionInterface extends
    NotFoundExceptionInterface,
    RegistryExceptionInterface,
    PsrNotFoundExceptionInterface
{
}
