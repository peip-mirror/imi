<?php

declare(strict_types=1);

namespace Imi\WorkermanGateway\Test\AppServer\WebSocketServer\Controller;

use Imi\ConnectionContext;
use Imi\RequestContext;
use Imi\Server\Server;
use Imi\Server\WebSocket\Controller\WebSocketController;
use Imi\Server\WebSocket\Route\Annotation\WSAction;
use Imi\Server\WebSocket\Route\Annotation\WSController;
use Imi\Server\WebSocket\Route\Annotation\WSRoute;
use Imi\Worker;

/**
 * 数据收发测试.
 *
 * @WSController
 */
class TestController extends WebSocketController
{
    /**
     * 登录.
     *
     * @WSAction
     *
     * @WSRoute({"action": "login"})
     */
    public function login(\stdClass $data): array
    {
        ConnectionContext::set('username', $data->username);
        $this->server->joinGroup('g1', $this->frame->getClientId());
        ConnectionContext::bind($data->username);

        return [
            'success'                               => true,
            'middlewareData'                        => RequestContext::get('middlewareData'),
            'requestUri'                            => ConnectionContext::get('requestUri'),
            'uri'                                   => (string) ConnectionContext::get('uri'),
            'token'                                 => $data->username,
            'clientId'                              => $this->frame->getClientId(),
            'getClientIdByFlag'                     => ConnectionContext::getClientIdByFlag($data->username),
            'getFlagByClientId'                     => ConnectionContext::getFlagByClientId($this->frame->getClientId()),
            'getClientIdsByFlags'                   => ConnectionContext::getClientIdsByFlags([$data->username]),
            'getFlagsByClientIds'                   => ConnectionContext::getFlagsByClientIds([$this->frame->getClientId()]),
        ];
    }

    /**
     * 发送消息.
     *
     * @WSAction
     *
     * @WSRoute({"action": "send"})
     */
    public function send(\stdClass $data): void
    {
        $message = ConnectionContext::get('username') . ':' . $data->message;
        Server::sendRawToGroup('g1', $message);
    }

    /**
     * 连接信息.
     *
     * @WSAction
     *
     * @WSRoute({"action": "info"})
     */
    public function info(): array
    {
        return [
            'clientId'       => ConnectionContext::getClientId(),
            'workerId'       => Worker::getWorkerId(),
        ];
    }

    /**
     * 多级参数的路由定位.
     *
     * @WSAction
     *
     * @WSRoute({"a.b.c": "test1"})
     */
    public function test1(\stdClass $data): array
    {
        return ['data' => $data];
    }

    /**
     * 多个参数条件的路由定位.
     *
     * @WSAction
     *
     * @WSRoute({"a": "1", "b": 2})
     */
    public function test2(\stdClass $data): array
    {
        return ['data' => $data];
    }

    /**
     * 测试重复路由警告.
     *
     * @WSAction
     *
     * @WSRoute({"duplicated": 1})
     */
    public function duplicated1(): void
    {
    }

    /**
     * 测试重复路由警告.
     *
     * @WSAction
     *
     * @WSRoute({"duplicated": 1})
     */
    public function duplicated2(): void
    {
    }
}
