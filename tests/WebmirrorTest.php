<?php
namespace Wa72\Spider\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Wa72\Spider\Applications\Webmirror;
use Wa72\Spider\Core\HttpClientQueue;

class WebmirrorTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $this->removeDirectory($directory);
        }
        $this->directories = [];
        parent::tearDown();
    }

    public function testCrawlsAssetsAndHandlesPermissionsHardLinksRedirectAliasesAndCleanup(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Filesystem permission and hard-link assertions require POSIX.');
        }

        $directory = $this->createDirectory();
        file_put_contents($directory . '/stale.txt', 'stale');
        $html = '<html><head><link href="/assets/site.css"></head><body>'
            . '<a href="/old">old</a><a href="/new">new</a>'
            . '<img src="/assets/image.png"><img srcset="/assets/retina.png 2x">'
            . '</body></html>';
        $responses = [
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/css'], 'body { color: red; }'),
            new Response(302, ['Location' => '/new']),
            new Response(200, ['Content-Type' => 'text/plain'], 'identical-content'),
            new Response(200, ['Content-Type' => 'image/png'], 'identical-content'),
            new Response(200, ['Content-Type' => 'image/png'], 'retina-content')
        ];
        $mirror = $this->createMirror($directory, $responses);
        $mirror->setCleanupMinRatio(0);

        $previousUmask = umask(0077);
        try {
            $this->assertTrue($mirror->crawl('https://example.test/'));
        } finally {
            umask($previousUmask);
        }

        $this->assertSame($html, file_get_contents($directory . '/index.html'));
        $this->assertSame('body { color: red; }', file_get_contents($directory . '/assets/site.css'));
        $this->assertSame('identical-content', file_get_contents($directory . '/new'));
        $this->assertSame('identical-content', file_get_contents($directory . '/assets/image.png'));
        $this->assertSame('retina-content', file_get_contents($directory . '/assets/retina.png'));
        $this->assertTrue(is_link($directory . '/old'));
        $this->assertSame('new', readlink($directory . '/old'));
        $this->assertSame(fileinode($directory . '/new'), fileinode($directory . '/assets/image.png'));
        $this->assertFalse(file_exists($directory . '/stale.txt'));

        foreach (['index.html', 'assets/site.css', 'new', 'assets/image.png', 'assets/retina.png'] as $path) {
            clearstatcache(true, $directory . '/' . $path);
            $this->assertNotSame(0, fileperms($directory . '/' . $path) & 0004, $path . ' should be world-readable');
        }
        $this->assertNoTemporaryFiles($directory);
    }

    public function testRepairsWorldReadPermissionOnUnchangedFile(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Filesystem permission assertions require POSIX.');
        }

        $directory = $this->createDirectory();
        $path = $directory . '/unchanged.txt';
        file_put_contents($path, 'same body');
        chmod($path, 0600);
        $mirror = $this->createMirror($directory, [new Response(200, ['Content-Type' => 'text/plain'], 'same body')]);

        $previousUmask = umask(0077);
        try {
            $this->assertTrue($mirror->crawl('https://example.test/unchanged.txt'));
        } finally {
            umask($previousUmask);
        }

        clearstatcache(true, $path);
        $this->assertNotSame(0, fileperms($path) & 0004);
    }

    public function testSkipsCleanupWhenStartPageFails(): void
    {
        $directory = $this->createDirectory();
        file_put_contents($directory . '/keep.txt', 'keep');
        $mirror = $this->createMirror($directory, [new Response(500, [], 'error')]);

        $this->assertFalse($mirror->crawl('https://example.test/'));
        $this->assertFileExists($directory . '/keep.txt');
    }

    public function testCleanupRatioMustBeBetweenZeroAndOne(): void
    {
        $mirror = $this->createMirror($this->createDirectory(), []);
        $this->assertSame($mirror, $mirror->setCleanupMinRatio(0));

        $this->expectException(\InvalidArgumentException::class);
        $mirror->setCleanupMinRatio(1.01);
    }

    public function testCleanupRatioRejectsValuesBelowZero(): void
    {
        $mirror = $this->createMirror($this->createDirectory(), []);

        $this->expectException(\InvalidArgumentException::class);
        $mirror->setCleanupMinRatio(-0.01);
    }

    private function createMirror(string $directory, array $responses): Webmirror
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        return new Webmirror($directory, new HttpClientQueue(['concurrent_requests' => 1], $client));
    }

    private function createDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/wa72-spider-' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;
        return $directory;
    }

    private function assertNoTemporaryFiles(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $this->assertStringNotContainsString('.tmp-', $file->getFilename());
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                unlink($directory);
            }
            return;
        }

        foreach (new \FilesystemIterator($directory) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                $this->removeDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}