<?php
/**
 * 协程调度器
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF\Coroutine;

use PG\MSF\Marco;
use PG\MSF\Controllers\ControllerFactory;
use PG\MSF\Models\ModelFactory;

class Scheduler
{
    public $IOCallBack;
    public $taskQueue;
    public $taskMap = [];
    public $cache;

    public function __construct()
    {
        $this->taskQueue = new \SplQueue();

        getInstance()->sysTimers[] = swoole_timer_tick(1, function ($timerId) {
            $this->run();
        });

        getInstance()->sysTimers[] = swoole_timer_tick(1000, function ($timerId) {
            // 当前进程的协程统计信息
            if (getInstance()::mode != 'console') {
                $this->stat();
            }

            if (empty($this->IOCallBack)) {
                return true;
            }

            foreach ($this->IOCallBack as $logId => $callBacks) {
                foreach ($callBacks as $key => $callBack) {
                    if ($callBack->ioBack) {
                        continue;
                    }

                    if ($callBack->isTimeout()) {
                        $this->schedule($this->taskMap[$logId]);
                    }
                }
            }
        });
    }

    public function stat()
    {
        $data = [
            // 进程ID
            'pid' => 0,
            // 协程统计信息
            'coroutine' => [
                // 当前正在处理的请求数
                'total' => 0,
            ],
            // 内存使用
            'memory' => [
                // 峰值
                'peak'  => '',
                // 当前使用
                'usage' => '',
            ],
            // 请求信息
            'request' => [
                // 当前Worker进程收到的请求次数
                'worker_request_count' => 0,
            ],
            // 其他对象池
            'object_poll' => [
                // 'xxx' => 22
            ],
            // 控制器对象池
            'controller_poll' => [
                // 'xxx' => 22
            ],
            // Model对象池
            'model_poll' => [
                // 'xxx' => 22
            ],
            // Http DNS Cache
            'dns_cache_http' => [
                // domain => [ip, time(), times]
            ],
            // Tcp DNS Cache
            'dns_cache_tcp' => [
                // domain => [ip, time(), times]
            ],
        ];
        $routineList = getInstance()->coroutine->taskMap;
        $data['pid'] = getInstance()->server->worker_pid;
        $data['coroutine']['total']   = count($routineList);
        $data['memory']['peak_byte']  = memory_get_peak_usage();
        $data['memory']['usage_byte'] = memory_get_usage();
        $data['memory']['peak']       = strval(number_format($data['memory']['peak_byte'] / 1024 / 1024, 3, '.', '')) . 'M';
        $data['memory']['usage']      = strval(number_format($data['memory']['usage_byte'] / 1024 / 1024, 3, '.', '')) . 'M';
        $data['request']['worker_request_count'] = getInstance()->server->stats()['worker_request_count'];

        if (!empty(getInstance()->objectPool->map)) {
            foreach (getInstance()->objectPool->map as $class => $objects) {
                if (APPLICATION_ENV == 'docker') {
                    foreach ($objects as $object) {
                        $data['object_poll'][$class][] = [
                            'gen_time'  => property_exists($object, 'genTime')  ? $object->genTime : 0,
                            'use_count' => property_exists($object, 'useCount') ? $object->useCount : 0,
                            'ref_count' => refcount($object),
                        ];
                    }
                } else {
                    $data['object_poll'][$class] = $objects->count() + $data['coroutine']['total'];
                }
            }
        }

        if (!empty(ControllerFactory::getInstance()->pool)) {
            foreach (ControllerFactory::getInstance()->pool as $class => $objects) {
                if (APPLICATION_ENV == 'docker') {
                    foreach ($objects as $object) {
                        $data['controller_poll'][$class][] = [
                            'gen_time'  => property_exists($object, 'genTime')  ? $object->genTime : 0,
                            'use_count' => property_exists($object, 'useCount') ? $object->useCount : 0,
                            'ref_count' => refcount($object),
                        ];
                    }
                } else {
                    $data['controller_poll'][$class] = $objects->count() + $data['coroutine']['total'];
                }
            }
        }

        if (!empty(ModelFactory::getInstance()->pool)) {
            foreach (ModelFactory::getInstance()->pool as $class => $objects) {
                if (APPLICATION_ENV == 'docker') {
                    foreach ($objects as $object) {
                        $data['model_poll'][$class][] = [
                            'gen_time'  => property_exists($object, 'genTime')  ? $object->genTime : 0,
                            'use_count' => property_exists($object, 'useCount') ? $object->useCount : 0,
                            'ref_count' => refcount($object),
                        ];
                    }
                } else {
                    $data['model_poll'][$class] = $objects->count() + $data['coroutine']['total'];
                }
            }
        }

        $data['dns_cache_http'] = \PG\MSF\Client\Http\Client::$dnsCache;
        $data['dns_cache_tcp']  = \PG\MSF\Client\Tcp\Client::$dnsCache;
        getInstance()->sysCache->set(Marco::SERVER_STATS . getInstance()->server->worker_id, $data);
    }

    public function run()
    {
        while (!$this->taskQueue->isEmpty()) {
            $task = $this->taskQueue->dequeue();
            $task->run();
            if (empty($task->routine)) {
                continue;
            }
            if ($task->routine->valid() && ($task->routine->current() instanceof IBase)) {
            } else {
                if ($task->isFinished()) {
                    $task->destroy();
                } else {
                    $this->schedule($task);
                }
            }
        }
    }

    public function schedule(Task $task)
    {
        $this->taskQueue->enqueue($task);
        return $this;
    }

    public function start(\Generator $routine, GeneratorContext $generatorContext)
    {
        $task = new Task($routine, $generatorContext);
        $this->IOCallBack[$generatorContext->getController()->PGLog->logId] = [];
        $this->taskMap[$generatorContext->getController()->PGLog->logId] = $task;
        $this->taskQueue->enqueue($task);
    }
}