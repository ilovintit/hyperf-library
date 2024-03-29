<?php
declare(strict_types=1);

namespace Iit\HyLib\Process;

use Hyperf\Process\AbstractProcess;
use Iit\HyLib\RedisLock\RedisLock;
use Iit\HyLib\Utils\Log;
use Iit\HyLib\Utils\Str;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class CronTaskProcess
 * @package Iit\HyLib\Process
 * @method logEmergency($message, array $context = [])
 * @method logAlert($message, array $context = [])
 * @method logCritical($message, array $context = [])
 * @method logError($message, array $context = [])
 * @method logWarning($message, array $context = [])
 * @method logNotice($message, array $context = [])
 * @method logInfo($message, array $context = [])
 * @method  logDebug($message, array $context = [])
 */
abstract class CronTaskProcess extends AbstractProcess
{
    use ProcessControlHelper;

    /**
     * 睡眠信号
     */
    const SIGNAL_DELAY = 'delay';
    /**
     * @var RedisLock
     */
    public RedisLock $lock;

    /**
     * @var LoggerInterface
     */
    public LoggerInterface $logger;

    /**
     * @var bool 处理任务出现异常是否进入睡眠,如果是false则立即进入下一个循环
     */
    public bool $exceptionSleep = true;

    /**
     * CronTaskProcess constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = Log::driver();
        parent::__construct($container);
    }

    /**
     * The logical of process will place in here.
     */
    public function handle(): void
    {
        $this->logDebug('process-started');
        $lockTime = $this->runInterval();
        $this->logDebug('process-interval' . $lockTime);
        if ($lockTime <= 0) {
            $this->logDebug('process-interval-invalid,exit');
            sleep(3600);
            return;
        }
        $this->logDebug('process-task-key:' . $this->taskKey());
        $this->lock = RedisLock::create($this->taskKey(), $lockTime);
        do {
            if ($this->checkHandleTips()) {
                $this->logDebug('process-handle-tips-reach-max');
                break;
            }
            $this->logDebug('process-try-lock-key');
            if (!$this->lock->get()) {
                $this->logDebug('process-lock-failed,sleep');
                sleep($this->sleepTime());
                continue;
            }
            $this->logDebug('process-lock-successful');
            try {
                $this->cronTask() === self::SIGNAL_DELAY
                && sleep($this->sleepTime());
                $this->lock->release();
            } catch (Throwable $exception) {
                $this->logError('process-task-exception', ['exception' => $exception->__toString()]);
                $this->exceptionSleep
                && sleep($this->sleepTime());
                $this->lock->release();
            }
            if ($this->checkMemoryLimit()) {
                $this->logDebug('process-usage-memory-overstep-limit');
                break;
            }
            $this->addHandleTips();
        } while (true);
    }

    /**
     * @return int
     */
    protected function sleepTime(): int
    {
        return intval(round(rand($this->runInterval() * 100 / 2, $this->runInterval() * 100) / 100, 0));
    }

    /**
     * 任务识别标识,默认使用类名转换,子类可以覆盖此方法自定义标识
     * @return string
     */
    public function taskKey(): string
    {
        return str_replace('_', '-', Str::snakeCase((new \ReflectionClass(static::class))->getShortName()));
    }

    /**
     * @param $name
     * @param $arguments
     */
    public function __call($name, $arguments)
    {
        if (Str::start($name, 'log')) {
            $msg = $this->taskKey() . '-' . $arguments[0] . '/' . microtime(true);
            $cxt = isset($arguments[1]) ? (is_array($arguments[1]) ? $arguments[1] : []) : [];
            $this->logger->{str_replace('log', '', $name)}($msg, $cxt);
        }
    }

    /**
     * 间隔运行时间,单位秒
     * @return integer
     */
    abstract public function runInterval(): int;

    /**
     * 执行任务
     */
    abstract public function cronTask();
}
