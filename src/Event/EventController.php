<?php
declare(strict_types=1);

namespace Iit\HyLib\Event;

/**
 * Interface EventController
 * @package Iit\HyLib\Contracts
 */
interface EventController
{
    /**
     * @return string
     */
    public function namespace(): string;

}
