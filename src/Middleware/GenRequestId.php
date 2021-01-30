<?php

declare(strict_types=1);

namespace Iit\HyLib\Middleware;

use Iit\HyLib\Util;
use Hyperf\Utils\Context;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GenRequestId implements MiddlewareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * GenRequestId constructor.
     * @param ContainerInterface $container
     */

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->hasHeader('X-Request-Id')) {
            $request = Context::set(ServerRequestInterface::class, $request->withHeader('X-Request-Id', uuid()));
        }
        logs()->info('receive-request', [
            'method' => $request->getMethod(),
            'url' => $request->getUri()->__toString(),
            'headers' => $request->getHeaders(),
            'body' => $request->getBody()->getContents(),
            'serverParams' => $request->getServerParams(),
        ]);
        return $handler->handle($request);
    }
}
