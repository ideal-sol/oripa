<?php

namespace App\Logging;

use Illuminate\Log\LogManager;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;

class PaidyLogManager extends LogManager
{
    protected function prepareHandler(HandlerInterface $handler, array $config = [])
    {
        $handler = parent::prepareHandler($handler, $config);
        // Includes Laravel's emergency fallback, which does not execute taps.
        if ($handler instanceof FormattableHandlerInterface) {
            $handler->setFormatter(new PaidyLogFormatter($handler->getFormatter()));
        }

        return $handler;
    }
}
