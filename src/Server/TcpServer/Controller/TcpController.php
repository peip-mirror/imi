<?php

declare(strict_types=1);

namespace Imi\Server\TcpServer\Controller;

use Imi\Server\TcpServer\Message\IReceiveData;
use Imi\Server\TcpServer\Server;

/**
 * TCP 控制器.
 */
abstract class TcpController
{
    /**
     * 请求
     *
     * @var \Imi\Server\TcpServer\Server
     */
    public Server $server;

    /**
     * 桢.
     *
     * @var \Imi\Server\TcpServer\Message\IReceiveData
     */
    public IReceiveData $data;

    /**
     * 编码消息，把数据编码为发送给客户端的格式.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    protected function encodeMessage($data)
    {
        return $this->server->getBean(\Imi\Server\DataParser\DataParser::class)->encode($data);
    }
}