<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections;

interface SingletonInterface
{
    /**
     * Get the singleton instance of the registry
     *
     * @return self
     */
    public static function getInstance(): self;
}
