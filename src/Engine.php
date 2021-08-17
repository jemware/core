<?php

declare(strict_types=1);

/**
 * Copyright (c) 2021 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/roach-php/roach
 */

namespace Sassnowski\Roach;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use Iterator;
use League\Container\Container;
use League\Container\ReflectionContainer;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Sassnowski\Roach\Http\Middleware\MiddlewareStack;
use Sassnowski\Roach\Http\Request;
use Sassnowski\Roach\Http\Response;
use Sassnowski\Roach\ItemPipeline\Pipeline;
use Sassnowski\Roach\Queue\ArrayRequestQueue;
use Sassnowski\Roach\Queue\RequestQueue;
use Sassnowski\Roach\Spider\AbstractSpider;
use Sassnowski\Roach\Spider\ParseResult;

final class Engine
{
    private Client $client;

    private Pipeline $itemPipeline;

    private MiddlewareStack $middlewareStack;

    public function __construct(
        private AbstractSpider $spider,
        private RequestQueue $requestQueue,
        private ContainerInterface $container,
        ?Client $client = null,
    ) {
        $this->client = $client ?: new Client();
        $this->itemPipeline = new Pipeline(
            \array_map(
                fn (string $processor) => $this->container->get($processor),
                $this->spider->processors(),
            ),
        );
        $this->middlewareStack = MiddlewareStack::create(
            ...\array_map(
                fn (string $middleware) => $this->container->get($middleware),
                $this->spider->middleware(),
            ),
        );
    }

    public static function run(AbstractSpider $spider): void
    {
        $container = new Container();
        $delegate = new ReflectionContainer();
        $container->delegate($delegate);

        $container->share(Logger::class, static function () {
            return (new Logger('default'))->pushHandler(new StreamHandler('php://stdout'));
        });

        $engine = new self(
            $spider,
            new ArrayRequestQueue(),
            $container,
        );

        $engine->start();
    }

    public function start(): void
    {
        foreach ($this->spider->startRequests() as $request) {
            $this->scheduleRequest($request);
        }

        $this->work();
    }

    private function work(): void
    {
        while (!$this->requestQueue->empty()) {
            $requests = function () {
                foreach ($this->requestQueue->dequeue() as $request) {
                    yield function () use ($request) {
                        return $this->sendRequest($request);
                    };
                }
            };

            $this->sendRequestsConcurrently($requests());
        }
    }

    private function onFulfilled(Http\Response $response, Request $request): void
    {
        /** @var ParseResult[] $parseResults */
        $parseResults = ($request->callback)($response);

        foreach ($parseResults as $result) {
            $result->isRequest()
                ? $this->scheduleRequest($result->getRequest())
                : $this->itemPipeline->sendThroughPipeline($result->getItem());
        }
    }

    private function sendRequest(Request $request): ?PromiseInterface
    {
        return $this->middlewareStack->dispatchRequest(
            $request,
            fn (Request $r) => $this->client->sendAsync($r)->then(
                static fn (ResponseInterface $response) => new Http\Response($response, $r),
            ),
        )?->then(function (Response $response) use ($request): void {
            $this->onFulfilled($response, $request);
        });
    }

    private function scheduleRequest(Request $request): void
    {
        $this->requestQueue->enqueue($request);
    }

    private function sendRequestsConcurrently(array | Iterator $requests): void
    {
        $pool = new Pool($this->client, $requests, [
            'concurrency' => 2,
        ]);

        $promise = $pool->promise();

        $promise->wait();
    }
}