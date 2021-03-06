<?php
declare(strict_types=1);

namespace Iit\HyLib\Event;

/**
 * Class QueryEvent
 * @package ZhiEq\Events
 */
abstract class QueryEvent extends AbstractEvent
{
    use EventQueryModelTrait, HeaderToBag;

    /**
     * QueryEvent constructor.
     * @param $code
     * @param array $headers
     */
    public function __construct($code, $headers = [])
    {
        $this->headerToBag($headers);
        $this->query($code);
    }

    /**
     * @return string
     */
    public function successMessage(): string
    {
        return '查询成功';
    }

    /**
     * @return string
     */
    public function failedMessage(): string
    {
        return '查询失败';
    }
}
