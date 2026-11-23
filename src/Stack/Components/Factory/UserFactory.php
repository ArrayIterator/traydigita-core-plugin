<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Components\Factory;

use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections\RegistryComponentInterface;
use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Collections\RegistryInterface;
use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Data\UserFactoryInterface;
use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Data\UserInterface;
use TrayDigita\WP\Plugin\Core\Stack\Libs\Collections\RegistryChainingIdentity;
use TrayDigita\WP\Plugin\Core\Stack\Libs\Data\User;
use WP_User;
use function is_numeric;
use function is_string;
use function strtolower;
use function trim;

/**
 * User Factory class to create User instances based on different identifiers.
 */
class UserFactory implements UserFactoryInterface, RegistryComponentInterface
{
    /**
     * @inheritdoc
     */
    public function findById(int $id): ?UserInterface
    {
        if ($id <= 0) {
            return null;
        }
        $userdata = WP_User::get_data_by('id', $id);
        if (!$userdata) {
            return null;
        }
        $wpUser = new WP_User();
        $wpUser->init($userdata);
        return new User($wpUser);
    }

    /**
     * @inheritdoc
     */
    public function findByUsername(string $username): ?UserInterface
    {
        $user = strtolower(trim($username));
        if (!$user) {
            return null;
        }
        $userdata = WP_User::get_data_by('login', $user);
        if (!$userdata) {
            return null;
        }
        $wpUser = new WP_User();
        $wpUser->init($userdata);
        return new User($wpUser);
    }

    /**
     * @inheritdoc
     */
    public function findOrEmpty(int|string|UserInterface $identity): UserInterface
    {
        if (is_numeric($identity)) {
            $identity = (int)$identity;
        }
        if ($identity instanceof UserInterface) {
            return $identity;
        }
        $user = is_string($identity) ? $this->findByUsername($identity) : $this->findById($identity);
        return $user ?? new User(0);
    }

    /**
     * @inheritdoc
     */
    public static function registerSelf(string $id, RegistryInterface $registry): ?RegistryChainingIdentity
    {
        return RegistryChainingIdentity::create(
            $id,
            [
                self::class,
                UserFactoryInterface::class
            ],
            $registry->locked($id),
            $registry->unlockable($id)
        );
    }
}
