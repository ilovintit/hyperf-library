<?php

declare(strict_types=1);

namespace App\Extension\Middleware;

use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Utils\Contracts\Arrayable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class CoreMiddleware extends \Hyperf\HttpServer\CoreMiddleware
{
    /**
     * @param Dispatched $dispatched
     * @param ServerRequestInterface $request
     * @return array|Arrayable|mixed|ResponseInterface|string
     */

    protected function handleFound(Dispatched $dispatched, ServerRequestInterface $request)
    {
        $response = parent::handleFound($dispatched, $request);
        if ($response instanceof Response) {
            return $response->getContent();
        }
        if (empty($response)) {
            return $response;
        }
        if ($response->getStatusCode() === 500 && !$response->hasHeader('x-error-code')) {
            return errors(trans('framework.response.handler-not-exists'));
        }
        return $response;
    }

    /**
     * @param ServerRequestInterface $request
     * @return array|Arrayable|mixed|ResponseInterface|string
     */

    protected function handleNotFound(ServerRequestInterface $request)
    {
        return errors(trans('framework.response.not-found'), 1, [], 404);
    }

    /**
     * @param array $methods
     * @param ServerRequestInterface $request
     * @return array|Arrayable|mixed|ResponseInterface|string
     */

    protected function handleMethodNotAllowed(array $methods, ServerRequestInterface $request)
    {
        return errors(trans('framework.response.method-not-allow'), 1, ['allow' => $methods], 405);
    }

}