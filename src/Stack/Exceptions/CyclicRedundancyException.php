<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Exceptions;

use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Exceptions\CyclicRedundancyExceptionInterface;

class CyclicRedundancyException extends RuntimeException implements CyclicRedundancyExceptionInterface
{
}
