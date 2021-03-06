<?php
declare(strict_types=1);

namespace Iit\HyLib\Event;

use Hyperf\Database\Model\Model;

/**
 * Class CreateEvent
 * @package Iit\HyLib\Contracts
 */
abstract class CreateEvent extends ValidatorEvent
{
    use HeaderToBag;

    /**
     * @var Model
     */
    public $newModel;

    /**
     * CreateEvent constructor.
     * @param $input
     * @param array $headers
     */
    public function __construct($input, $headers = [])
    {
        $this->headerToBag($headers);
        $this->validateInput($input);
        $modelClass = $this->modelClass();
        $this->newModel = new $modelClass();
    }

    /**
     * @return string
     */
    abstract protected function modelClass(): string;

    /**
     * @return string
     */
    public function successMessage(): string
    {
        return '保存成功';
    }

    /**
     * @return string
     */
    public function failedMessage(): string
    {
        return '保存失败';
    }
}
