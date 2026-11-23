<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Enums;

enum ManifestAssetType: string
{
    /**
     * Manifest type Merge
     */
    case MERGE = 'merge';

    /**
     * Manifest type Environment
     */
    case ENVIRONMENT = 'environment';
}
