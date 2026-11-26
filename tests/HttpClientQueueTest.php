<?php
namespace Wa72\Spider\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Wa72\Spider\Core\HttpClientExceptionEvent;
use Wa72\Spider\Core\HttpClientQueue;
use Wa72\Spider\Core\HttpClientRedirectEvent;
use Wa72\Spider\Core\HttpClientResponseEvent;

class HttpClientQueueTest extends TestCase
{
    public function testDispatchesResponseEventWithContentTypeWithoutCharset(): void
    {
        $queue = $this->createQueue([new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], 'ok')]);
        $events = [];
        $queue->addResponseListener(static function (HttpClientResponseEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $queue->addUrl('https://example.test/page');
        $queue->start();

        $this->assertCount(1, $events);
        $this->assertSame('text/html', $events[0]->getContentType());
        $this->assertSame('https://example.test/page', $events[0]->getRequestUrl());
        $this->assertSame('ok', (string) $events[0]->getResponse()->getBody());
    }

    public function testDispatchesRedirectEvent(): void
    {
        $queue = $this->createQueue([new Response(301, ['Location' => '/destination'])]);
        $events = [];
        $queue->addRedirectListener(static function (HttpClientRedirectEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $queue->addUrl('https://example.test/source');
        $queue->start();

        $this->assertCount(1, $events);
        $this->assertSame('/destination', $events[0]->getRedirectUrl());
        $this->assertSame(301, $events[0]->getStatuscode());
    }

    public function testDispatchesExceptionEventForNotFoundResponse(): void
    {
        $queue = $this->createQueue([new Response(404, [], 'missing')]);
        $events = [];
        $queue->addExceptionListener(static function (HttpClientExceptionEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $queue->addUrl('https://example.test/missing');
        $queue->start();

        $this->assertCount(1, $events);
        $this->assertSame('https://example.test/missing', $events[0]->getRequestUrl());
        $this->assertSame(404, $events[0]->getResponse()->getStatusCode());
    }

    public function testRejectsRelativeUrls(): void
    {
        $queue = $this->createQueue([]);

        $this->expectException(\InvalidArgumentException::class);
        $queue->addUrl('/relative/path');
    }

    public function testRetriesServerErrorAndDispatchesSuccessfulResponse(): void
    {
        $mock = new MockHandler([
            new Response(503, [], 'unavailable'),
            new Response(200, [], 'recovered')
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $queue = new HttpClientQueue(['concurrent_requests' => 1, 'retries' => 1], $client);
        $events = [];
        $queue->addResponseListener(static function (HttpClientResponseEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $queue->addUrl('https://example.test/retry');
        $queue->start();

        $this->assertSame(0, $mock->count());
        $this->assertCount(1, $events);
        $this->assertSame('recovered', (string) $events[0]->getResponse()->getBody());
    }

    private function createQueue(array $responses, array $options = []): HttpClientQueue
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $stack]);

        return new HttpClientQueue(array_merge(['concurrent_requests' => 1], $options), $client);
    }
}