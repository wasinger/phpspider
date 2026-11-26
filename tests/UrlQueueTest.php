<?php
namespace Wa72\Spider\Tests;

use PHPUnit\Framework\TestCase;
use Wa72\Spider\Core\UrlQueue;

class UrlQueueTest extends TestCase
{
    public function testDeduplicatesQueuedAndVisitedUrls(): void
    {
        $queue = new UrlQueue();
        $queue->addUrl('https://example.test/one');
        $queue->addUrl('https://example.test/one');

        $this->assertTrue($queue->isQueued('https://example.test/one'));
        $this->assertSame('https://example.test/one', $queue->next());
        $this->assertTrue($queue->isVisited('https://example.test/one'));
        $queue->addUrl('https://example.test/one');

        $this->assertFalse($queue->isQueued('https://example.test/one'));
        $this->assertNull($queue->next());
    }

    public function testForceCanRequeueVisitedUrlButNotDuplicateAnExistingEntry(): void
    {
        $queue = new UrlQueue();
        $queue->addUrl('https://example.test/one');
        $queue->next();
        $queue->addUrl('https://example.test/one', true);
        $queue->addUrl('https://example.test/one', true);

        $this->assertTrue($queue->isQueued('https://example.test/one'));
        $this->assertSame('https://example.test/one', $queue->next());
        $this->assertFalse($queue->isQueued('https://example.test/one'));
    }

    public function testReturnsUrlsInInsertionOrder(): void
    {
        $queue = new UrlQueue();
        $queue->addUrl('https://example.test/one');
        $queue->addUrl('https://example.test/two');

        $this->assertSame('https://example.test/one', $queue->next());
        $this->assertSame('https://example.test/two', $queue->next());
    }
}