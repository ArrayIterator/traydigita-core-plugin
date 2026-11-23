<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Libs\Collections\RegistryDefinitions;

use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections\RegistryInterface;
use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections\RegistryNoChainingInterface;
use TrayDigita\WP\Plugin\Core\Stack\Libs\Collections\RegistryChainingIdentity;

/**
 * No chaining value definition class that extends the Value class
 * and overrides the resolveChain method to return null.
 *
 * @template-implements RegistryNoChainingInterface<string, mixed>
 */
class ValueNoChaining extends Value implements RegistryNoChainingInterface
{
    /**
     * @inheritdoc
     */
    public function resolveChain(string $id, RegistryInterface $registry): ?RegistryChainingIdentity
    {
        return null;
    }
}
