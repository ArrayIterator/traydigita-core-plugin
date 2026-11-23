<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Components\Logger\Handlers;

use TrayDigita\WP\Plugin\Core\Stack\Components\Logger\AbstractHandler;
use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Logger\LogRecordInterface;

class NullHandler extends AbstractHandler
{
    /**
     * @inheritdoc
     * Handles a log record by doing nothing and returning true.
     */
    public function handle(LogRecordInterface $record): bool
    {
        return $this->isHandling($record);
    }
}
