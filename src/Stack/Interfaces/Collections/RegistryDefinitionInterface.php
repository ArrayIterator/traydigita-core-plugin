<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections;

use TrayDigita\WP\Plugin\Core\Stack\Libs\Collections\RegistryChainingIdentity;

/**
 * @template TName of string
 * @template TResult
 */
interface RegistryDefinitionInterface
{
    /**
     * Get the value of data
     *
     * @return TResult
     */
    public function value(RegistryInterface $registry, bool $autoResolveParameters = true) : mixed;

    /**
     * Get the raw value of data
     *
     * @return TResult|mixed
     */
    public function rawValue() : mixed;

    /**
     * Check if the definition is resolved
     *
     * @return bool
     */
    public function isResolved() : bool;

    /**
     * Resolve the definition and return the chaining identity
     *
     * @param string $id
     * @param RegistryInterface $registry
     * @return ?RegistryChainingIdentity
     */
    public function resolveChain(string $id, RegistryInterface $registry) : ?RegistryChainingIdentity;
}
