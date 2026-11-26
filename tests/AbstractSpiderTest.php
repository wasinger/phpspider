<?php
namespace Wa72\Spider\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Wa72\Spider\Core\AbstractSpider;
use Wa72\Spider\Core\HttpClientQueue;
use Wa72\Spider\Core\HttpClientResponseEvent;
use Wa72\Spider\Core\UrlFilter;

class AbstractSpiderTest extends TestCase
{
    public function testExtractsHtmlCssAndSrcsetUrlsAndTracksReferers(): void
    {
        $spider = new TestableSpider(new HttpClientQueue());
        $html = <<<'HTML'
<!doctype html><html><head>
<link imagesrcset="/images/preload.png 2x">
<style>.hero { background-image: url('/images/style-tag.png'); }</style>
</head><body>
<a href="/page.html">Page title</a>
<a href="mailto:someone@example.test">Mail</a>
<a href="#section">Section</a>
<a href="data:image/png;base64,abc">Data</a>
<img src="/images/source.png" srcset="/images/one.png 1x, data:image/png;base64,abc 2x">
<picture><source srcset="/images/picture.png 3x"></picture>
<div style="background: url('/images/inline-style.png')"></div>
</body></html>
HTML;

        $spider->process('https://example.test/index.html', $html, [
            'extract_href' => true,
            'extract_src' => true,
            'look_in_css' => true
        ]);

        $this->assertSame([
            'https://example.test/page.html',
            'https://example.test/images/source.png',
            'https://example.test/images/preload.png',
            'https://example.test/images/one.png',
            'https://example.test/images/picture.png',
            'https://example.test/images/inline-style.png',
            'https://example.test/images/style-tag.png'
        ], $spider->drainQueue());
        $this->assertSame(['https://example.test/index.html'], $spider->getReferingPages('https://example.test/page.html'));
        $this->assertSame(
            ['https://example.test/index.html' => 'Page title'],
            $spider->getLinktexts('https://example.test/page.html')
        );
    }

    public function testLinkExtractionFilterPreventsProcessingDocument(): void
    {
        $spider = new TestableSpider(new HttpClientQueue());
        $spider->setUrlfilterLinkextract((new UrlFilter())->rejectPathByRegex('~^/private~'));

        $spider->process('https://example.test/private/index.html', '<a href="/found">Found</a>', [
            'extract_href' => true
        ]);

        $this->assertSame([], $spider->drainQueue());
    }

    public function testParsesSrcsetUrlsContainingCommasAndDescriptorsWithParentheses(): void
    {
        $spider = new TestableSpider(new HttpClientQueue());

        $this->assertSame([
            ['url' => '/images/photo,large.jpg', 'descriptor' => '1x'],
            ['url' => '/images/next.jpg', 'descriptor' => 'calc(2, 3)']
        ], $spider->parseCandidates('/images/photo,large.jpg 1x, /images/next.jpg calc(2, 3)'));
    }

    public function testRewritesSrcsetUrlsAndPreservesDescriptors(): void
    {
        $spider = new TestableSpider(new HttpClientQueue());
        $spider->addUrlRewriter(static function ($accepted, $original, ?UriInterface $rewrittenUrl): UriInterface {
            return $rewrittenUrl->withHost('mirror.example');
        });

        $response = $spider->process('https://example.test/index.html', '<img srcset="/images/one.png 1x, /images/two.png 2x">', [
            'extract_href' => false,
            'extract_src' => true,
            'rewrite_urls' => true
        ]);

        $this->assertStringContainsString(
            'srcset="https://mirror.example/images/one.png 1x, https://mirror.example/images/two.png 2x"',
            (string) $response->getBody()
        );
    }
}

class TestableSpider extends AbstractSpider
{
    public function handleResponseEvent(HttpClientResponseEvent $event)
    {
    }

    public function process(string $url, string $html, array $options = []): Response
    {
        return $this->workOnResponse($url, 'text/html', new Response(200, ['Content-Type' => 'text/html'], $html), $options);
    }

    public function parseCandidates(string $srcset): array
    {
        return $this->parse_srcset($srcset);
    }

    public function drainQueue(): array
    {
        $urls = [];
        while ($url = $this->getClientQueue()->getUrlqueue()->next()) {
            $urls[] = $url;
        }
        return $urls;
    }
}