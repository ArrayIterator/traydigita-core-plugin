<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections;

/**
 * @template TName of string
 * @template TResult
 * @template-extends RegistryDefinitionInterface<TName, TResult>
 */
interface RegistryNoChainingInterface extends RegistryDefinitionInterface
{
}
