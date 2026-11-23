<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections;

use TrayDigita\WP\Plugin\Core\Stack\Libs\Collections\RegistryChainingIdentity;

interface RegistryComponentInterface
{
    /**
     * Register the chaining component to the registry
     *
     * @param string $id
     * @param RegistryInterface $registry
     * @return ?RegistryChainingIdentity
     */
    public static function registerSelf(string $id, RegistryInterface $registry) : ?RegistryChainingIdentity;
}
