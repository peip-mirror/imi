<?php

namespace Imi\Server;

use Imi\App;
use Imi\ConnectContext;
use Imi\Event\Event;
use Imi\RequestContext;
use Imi\Server\ConnectContext\ConnectionBinder;
use Imi\Server\DataParser\DataParser;
use Imi\Server\Event\Param\PipeMessageEventParam;
use Imi\ServerManage;
use Imi\Util\Co\ChannelContainer;
use Imi\Util\Process\ProcessAppContexts;
use Imi\Util\Process\ProcessType;
use Imi\Worker;

/**
 * 服务器工具类.
 */
abstract class Server
{
    /**
     * 发送消息给 Worker 进程，使用框架内置格式.
     *
     * 返回成功发送消息数量
     *
     * @param string         $action
     * @param array          $data
     * @param int|int[]|null $workerId
     *
     * @return int
     */
    public static function sendMessage(string $action, array $data = [], $workerId = null): int
    {
        if (null === $workerId)
        {
            $workerId = range(0, Worker::getWorkerNum() - 1);
        }
        $data['action'] = $action;
        $message = json_encode($data);
        $server = ServerManage::getServer('main');
        $swooleServer = $server->getSwooleServer();
        $success = 0;
        $currentWorkerId = Worker::getWorkerID();
        foreach ((array) $workerId as $tmpWorkerId)
        {
            if ($tmpWorkerId === $currentWorkerId)
            {
                go(function () use ($server, $currentWorkerId, $message) {
                    Event::trigger('IMI.MAIN_SERVER.PIPE_MESSAGE', [
                        'server'    => $server,
                        'workerID'  => $currentWorkerId,
                        'message'   => $message,
                    ], $server, PipeMessageEventParam::class);
                });
                ++$success;
            }
            elseif ($swooleServer->sendMessage($message, $tmpWorkerId))
            {
                ++$success;
            }
        }

        return $success;
    }

    /**
     * 发送消息给 Worker 进程.
     *
     * 返回成功发送消息数量
     *
     * @param string         $message
     * @param int|int[]|null $workerId
     *
     * @return int
     */
    public static function sendMessageRaw(string $message, $workerId = null): int
    {
        if (null === $workerId)
        {
            $workerId = range(0, Worker::getWorkerNum() - 1);
        }
        $server = ServerManage::getServer('main')->getSwooleServer();
        $success = 0;
        $currentWorkerId = Worker::getWorkerID();
        foreach ((array) $workerId as $tmpWorkerId)
        {
            if ($tmpWorkerId === $currentWorkerId)
            {
                go(function () use ($server, $currentWorkerId, $message) {
                    Event::trigger('IMI.MAIN_SERVER.PIPE_MESSAGE', [
                        'server'    => $server,
                        'workerID'  => $currentWorkerId,
                        'message'   => $message,
                    ], $server, PipeMessageEventParam::class);
                });
                ++$success;
            }
            elseif ($server->sendMessage($message, $tmpWorkerId))
            {
                ++$success;
            }
        }

        return $success;
    }

    /**
     * 发送数据给指定客户端，支持一个或多个（数组）.
     *
     * 数据将会通过处理器编码
     *
     * @param mixed          $data
     * @param int|int[]|null $fd           为 null 时，则发送给当前连接
     * @param string|null    $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool           $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function send($data, $fd = null, $serverName = null, bool $toAllWorkers = true): int
    {
        $server = static::getServer($serverName);
        /** @var \Imi\Server\DataParser\DataParser $dataParser */
        $dataParser = $server->getBean(DataParser::class);
        if (null === $serverName)
        {
            $serverName = $server->getName();
        }

        return static::sendRaw($dataParser->encode($data, $serverName), $fd, $serverName, $toAllWorkers);
    }

    /**
     * 发送数据给指定标记的客户端，支持一个或多个（数组）.
     *
     * 数据将会通过处理器编码
     *
     * @param mixed                $data
     * @param string|string[]|null $flag         为 null 时，则发送给当前连接
     * @param string|null          $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool                 $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function sendByFlag($data, $flag = null, $serverName = null, bool $toAllWorkers = true): int
    {
        /** @var ConnectionBinder $connectionBinder */
        $connectionBinder = App::getBean('ConnectionBinder');

        if (null === $flag)
        {
            $fd = ConnectContext::getFd();
            if (!$fd)
            {
                return 0;
            }
            $fds = [$fd];
        }
        else
        {
            $fds = [];
            foreach ((array) $flag as $tmpFlag)
            {
                $fd = $connectionBinder->getFdByFlag($tmpFlag);
                if ($fd)
                {
                    $fds[] = $fd;
                }
            }
            if (!$fds)
            {
                return 0;
            }
        }

        return static::send($data, $fds, $serverName, $toAllWorkers);
    }

    /**
     * 发送数据给指定客户端，支持一个或多个（数组）.
     *
     * @param string         $data
     * @param int|int[]|null $fd           为 null 时，则发送给当前连接
     * @param string|null    $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool           $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function sendRaw(string $data, $fd = null, $serverName = null, bool $toAllWorkers = true): int
    {
        $server = static::getServer($serverName);
        $swooleServer = $server->getSwooleServer();
        if (null === $fd)
        {
            $fd = ConnectContext::getFd();
            if (!$fd)
            {
                return 0;
            }
        }
        $fds = (array) $fd;
        $success = 0;
        if ($server instanceof \Imi\Server\WebSocket\Server)
        {
            $method = 'push';
        }
        else
        {
            $method = 'send';
        }
        if (\SWOOLE_BASE === $swooleServer->mode && $toAllWorkers && 'push' === $method)
        {
            $id = uniqid('', true);
            try
            {
                $channel = ChannelContainer::getChannel($id);
                $count = static::sendMessage('sendToFdsRequest', [
                    'messageId'  => $id,
                    'fds'        => $fds,
                    'data'       => $data,
                    'serverName' => $server->getName(),
                ]);
                if (ProcessType::PROCESS !== App::get(ProcessAppContexts::PROCESS_TYPE))
                {
                    for ($i = $count; $i > 0; --$i)
                    {
                        $result = $channel->pop(30);
                        if (false === $result)
                        {
                            break;
                        }
                        $success += ($result['result'] ?? 0);
                    }
                }
            }
            finally
            {
                ChannelContainer::removeChannel($id);
            }
        }
        else
        {
            foreach ($fds as $tmpFd)
            {
                // @phpstan-ignore-next-line
                if ('push' === $method && !$swooleServer->isEstablished($tmpFd))
                {
                    continue;
                }
                if ($swooleServer->$method($tmpFd, $data))
                {
                    ++$success;
                }
            }
        }

        return $success;
    }

    /**
     * 发送数据给指定标记的客户端，支持一个或多个（数组）.
     *
     * @param string               $data
     * @param string|string[]|null $flag         为 null 时，则发送给当前连接
     * @param string|null          $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool                 $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function sendRawByFlag(string $data, $flag = null, $serverName = null, bool $toAllWorkers = true): int
    {
        /** @var ConnectionBinder $connectionBinder */
        $connectionBinder = App::getBean('ConnectionBinder');

        if (null === $flag)
        {
            $fd = ConnectContext::getFd();
            if (!$fd)
            {
                return 0;
            }
            $fds = [$fd];
        }
        else
        {
            $fds = [];
            foreach ((array) $flag as $tmpFlag)
            {
                $fd = $connectionBinder->getFdByFlag($tmpFlag);
                if ($fd)
                {
                    $fds[] = $fd;
                }
            }
            if (!$fds)
            {
                return 0;
            }
        }

        return static::sendRaw($data, $fds, $serverName, $toAllWorkers);
    }

    /**
     * 发送数据给所有客户端.
     *
     * 数据将会通过处理器编码
     *
     * @param mixed       $data
     * @param string|null $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool        $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function sendToAll($data, $serverName = null, bool $toAllWorkers = true): int
    {
        $server = static::getServer($serverName);
        /** @var \Imi\Server\DataParser\DataParser $dataParser */
        $dataParser = $server->getBean(DataParser::class);
        if (null === $serverName)
        {
            $serverName = $server->getName();
        }

        return static::sendRawToAll($dataParser->encode($data, $serverName), $serverName, $toAllWorkers);
    }

    /**
     * 发送数据给所有客户端.
     *
     * 数据原样发送
     *
     * @param string      $data
     * @param string|null $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool        $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function sendRawToAll(string $data, $serverName = null, bool $toAllWorkers = true): int
    {
        $server = static::getServer($serverName);
        $swooleServer = $server->getSwooleServer();
        $success = 0;
        if ($server instanceof \Imi\Server\WebSocket\Server)
        {
            $method = 'push';
        }
        else
        {
            $method = 'send';
        }
        if (\SWOOLE_BASE === $swooleServer->mode && $toAllWorkers && 'push' === $method)
        {
            $id = uniqid('', true);
            try
            {
                $channel = ChannelContainer::getChannel($id);
                $count = static::sendMessage('sendRawToAllRequest', [
                    'messageId'     => $id,
                    'data'          => $data,
                    'serverName'    => $server->getName(),
                ]);
                if (ProcessType::PROCESS !== App::get(ProcessAppContexts::PROCESS_TYPE))
                {
                    for ($i = $count; $i > 0; --$i)
                    {
                        $result = $channel->pop(30);
                        if (false === $result)
                        {
                            break;
                        }
                        $success += ($result['result'] ?? 0);
                    }
                }
            }
            finally
            {
                ChannelContainer::removeChannel($id);
            }
        }
        else
        {
            foreach ($server->getSwoolePort()->connections as $fd)
            {
                // @phpstan-ignore-next-line
                if ('push' === $method && !$swooleServer->isEstablished($fd))
                {
                    continue;
                }
                if ($swooleServer->$method($fd, $data))
                {
                    ++$success;
                }
            }
        }

        return $success;
    }

    /**
     * 发送数据给分组中的所有客户端，支持一个或多个（数组）.
     *
     * 数据将会通过处理器编码
     *
     * @param string|string[] $groupName
     * @param mixed           $data
     * @param string|null     $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool            $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function sendToGroup($groupName, $data, $serverName = null, bool $toAllWorkers = true): int
    {
        $server = static::getServer($serverName);
        /** @var \Imi\Server\DataParser\DataParser $dataParser */
        $dataParser = $server->getBean(DataParser::class);
        if (null === $serverName)
        {
            $serverName = $server->getName();
        }

        return static::sendRawToGroup($groupName, $dataParser->encode($data, $serverName), $serverName, $toAllWorkers);
    }

    /**
     * 发送数据给分组中的所有客户端，支持一个或多个（数组）.
     *
     * 数据原样发送
     *
     * @param string|string[] $groupName
     * @param string          $data
     * @param string|null     $serverName   服务器名，默认为当前服务器或主服务器
     * @param bool            $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function sendRawToGroup($groupName, string $data, $serverName = null, bool $toAllWorkers = true): int
    {
        $server = static::getServer($serverName);
        $swooleServer = $server->getSwooleServer();
        $groups = (array) $groupName;
        $success = 0;
        if ($server instanceof \Imi\Server\WebSocket\Server)
        {
            $method = 'push';
        }
        else
        {
            $method = 'send';
        }
        if (\SWOOLE_BASE === $swooleServer->mode && $toAllWorkers && 'push' === $method)
        {
            $id = uniqid('', true);
            try
            {
                $channel = ChannelContainer::getChannel($id);
                $count = static::sendMessage('sendToGroupsRequest', [
                    'messageId'     => $id,
                    'groups'        => $groups,
                    'data'          => $data,
                    'serverName'    => $server->getName(),
                ]);
                if (ProcessType::PROCESS !== App::get(ProcessAppContexts::PROCESS_TYPE))
                {
                    for ($i = $count; $i > 0; --$i)
                    {
                        $result = $channel->pop(30);
                        if (false === $result)
                        {
                            break;
                        }
                        $success += ($result['result'] ?? 0);
                    }
                }
            }
            finally
            {
                ChannelContainer::removeChannel($id);
            }
        }
        else
        {
            foreach ($groups as $tmpGroupName)
            {
                $group = $server->getGroup($tmpGroupName);
                if ($group)
                {
                    $result = $group->$method($data);
                    foreach ($result as $item)
                    {
                        if ($item)
                        {
                            ++$success;
                        }
                    }
                }
            }
        }

        return $success;
    }

    /**
     * 关闭一个或多个连接.
     *
     * @param int|int[]|null $fd
     * @param string|null    $serverName
     * @param bool           $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function close($fd = null, ?string $serverName = null, bool $toAllWorkers = true): int
    {
        $server = static::getServer($serverName);
        $swooleServer = $server->getSwooleServer();
        $success = 0;
        if (null === $fd)
        {
            $fd = ConnectContext::getFd();
            if (!$fd)
            {
                return 0;
            }
            $fds = [$fd];
        }
        else
        {
            $fds = (array) $fd;
        }
        // @phpstan-ignore-next-line
        if (\SWOOLE_BASE === $swooleServer->mode && $toAllWorkers && version_compare(\SWOOLE_VERSION, '4.6', '<'))
        {
            $id = uniqid('', true);
            try
            {
                $channel = ChannelContainer::getChannel($id);
                $count = static::sendMessage('closeConnectionRequest', [
                    'messageId'     => $id,
                    'fds'           => $fds,
                    'serverName'    => $server->getName(),
                ]);
                if (ProcessType::PROCESS !== App::get(ProcessAppContexts::PROCESS_TYPE))
                {
                    for ($i = $count; $i > 0; --$i)
                    {
                        $result = $channel->pop(30);
                        if (false === $result)
                        {
                            break;
                        }
                        $success += ($result['result'] ?? 0);
                    }
                }
            }
            finally
            {
                ChannelContainer::removeChannel($id);
            }
        }
        else
        {
            foreach ($fds as $currentFd)
            {
                if ($swooleServer->close($currentFd))
                {
                    ++$success;
                }
            }
        }

        return $success;
    }

    /**
     * 关闭一个或多个指定标记的连接.
     *
     * @param string|string[]|null $flag
     * @param string|null          $serverName
     * @param bool                 $toAllWorkers BASE模式下，发送给所有 worker 中的连接
     *
     * @return int
     */
    public static function closeByFlag($flag = null, ?string $serverName = null, bool $toAllWorkers = true): int
    {
        /** @var ConnectionBinder $connectionBinder */
        $connectionBinder = App::getBean('ConnectionBinder');

        if (null === $flag)
        {
            $fd = ConnectContext::getFd();
            if (!$fd)
            {
                return 0;
            }
            $fds = [$fd];
        }
        else
        {
            $fds = [];
            foreach ((array) $flag as $tmpFlag)
            {
                $fd = $connectionBinder->getFdByFlag($tmpFlag);
                if ($fd)
                {
                    $fds[] = $fd;
                }
            }
            if (!$fds)
            {
                return 0;
            }
        }

        return static::close($fds, $serverName, $toAllWorkers);
    }

    /**
     * 获取服务器.
     *
     * @param string|null $serverName
     *
     * @return \Imi\Server\Base|null
     */
    public static function getServer(?string $serverName = null): ?Base
    {
        if (null === $serverName)
        {
            $server = RequestContext::getServer();
            if ($server)
            {
                return $server;
            }
            $serverName = 'main';
        }

        return ServerManage::getServer($serverName);
    }
}
