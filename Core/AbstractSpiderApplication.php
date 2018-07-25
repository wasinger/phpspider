<?php
namespace Wa72\Spider\Core;

use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareTrait;
use GuzzleHttp\Psr7;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 *  This is a class used as base for other classes that handle spider responses.
 */
abstract class AbstractSpiderApplication
{
    use LoggerAwareTrait;

    /**
     * @var Spider
     */
    protected $spider;

    /**
     * @var array
     */
    protected $referer = [];

    /**
     * @var UrlFilter Urls filtered out by this filter will not be fetched.
     */
    protected $urlfilter_fetch;

    /**
     * @var UrlFilter Urls filtered out by this filter will not be crawled for more links.
     */
    protected $urlfilter_linkextract;

    /**
     * Callables to transform discovered urls before adding to spider.
     * Must accept and return an UriInterface
     *
     * @var callable[]
     */
    protected $url_normalizers = [];

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @param Spider|null $spider
     * @param array $options
     */
    public function __construct($spider = null, $options = [])
    {
        $this->options = \array_replace([
            'discard_fragment' => true,
            'concurrent_requests' => 6
        ], $options);

        if (!($spider instanceof Spider)) {
            $spider = new Spider();
        }
        $this->spider = $spider;

        $spider->addResponseListener([$this, 'handleResponseEvent']);
        $spider->addExceptionListener([$this, 'handleExceptionEvent']);
        $spider->addRedirectListener([$this, 'handleRedirectEvent']);
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->spider->setLogger($logger);
    }

    /**
     * @param $start_url
     */
    public function run($start_url)
    {
        $this->spider->run($start_url, [
            'concurrent_requests' => $this->options['concurrent_requests']
        ]);
    }

    /**
     * @return Spider
     */
    public function getSpider()
    {
        return $this->spider;
    }

    /**
     * Urls matched by this filter will not be fetched.
     *
     * @return UrlFilter
     */
    public function getUrlfilterFetch()
    {
        if (!$this->urlfilter_fetch instanceof UrlFilter) {
            $this->urlfilter_fetch = new UrlFilter();
        }
        return $this->urlfilter_fetch;
    }

    /**
     * @param UrlFilter $urlfilter_fetch
     * @return AbstractSpiderApplication
     */
    public function setUrlfilterFetch($urlfilter_fetch)
    {
        $this->urlfilter_fetch = $urlfilter_fetch;
        return $this;
    }

    /**
     * Urls matched by this filter will be fetched but not crawled for more links.
     *
     * @return UrlFilter
     */
    public function getUrlfilterLinkextract()
    {
        if (!$this->urlfilter_linkextract instanceof UrlFilter) {
            $this->urlfilter_linkextract = new UrlFilter();
        }
        return $this->urlfilter_linkextract;
    }

    /**
     * @param UrlFilter $urlfilter_linkextract
     * @return AbstractSpiderApplication
     */
    public function setUrlfilterLinkextract($urlfilter_linkextract)
    {
        $this->urlfilter_linkextract = $urlfilter_linkextract;
        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @param array $options
     * @return AbstractSpiderApplication
     */
    public function setOptions($options)
    {
        $this->options = $options;
        return $this;
    }

    public function getReferingPages($url)
    {
        if (!empty($this->referer[$url])) {
            return array_keys($this->referer[$url]);
        } else {
            return [];
        }
    }

    public function handleExceptionEvent(SpiderExceptionEvent $event)
    {
        if ($this->logger) {
            $e = $event->getException();
            $url = $event->getRequestUrl();

            if ($e instanceof ClientException) { // 4xx Error codes
                $response = $e->getResponse();
                $refering_pages = $this->getReferingPages($url);

                $this->logger->error(sprintf('Error %s on URL %s. Refering pages: %s', $response->getStatusCode(), $url, join(', ', $refering_pages)));
            } else {
                $this->logger->error(sprintf('Error on URL %s: %s', $url, $e->getMessage()));
            }
        }
    }

    public function handleRedirectEvent(SpiderRedirectEvent $e)
    {
        $response = $e->getResponse();
        $this->handleFoundUrl($e->getRedirectUrl(), $e->getRequestUrl(), $response);
    }

    /**
     * do something with the response.
     *
     * E.g., use $this->findUrls() to find more URLs in the response content (if it is an HTML document)
     *
     * @param SpiderResponseEvent $event
     * @return mixed
     */
    abstract function handleResponseEvent(SpiderResponseEvent $event);

    /**
     * @param string $request_url
     * @param string $content_type
     * @param ResponseInterface $response
     * @param array $options
     */
    protected function findUrls($request_url, $content_type, ResponseInterface $response, $options = [])
    {
        $options = \array_replace([
            'extract_href' => true,
            'extract_src' => false,
            'look_in_css' => false,
        ], $options);

        if ($this->getUrlfilterLinkextract()->filter($request_url)) {

            if ($content_type == 'text/html') {
                if ($this->logger) {
                    $this->logger->debug('Suche nach weiteren Links...');
                }
                $hp = new Crawler((string)$response->getBody());

                if ($hp && count($hp)) {

                    if ($options['extract_href']) {
                        // Extrahiere HREF-Attribute
                        $links = $hp->filter('[href]');
                        if ($this->logger) {
                            $this->logger->debug('Anzahl gefundene HREFs: ' . count($links));
                        }
                        foreach ($links as $link) {
                            $url = $link->getAttribute('href');
                            $this->handleFoundUrl($url, $request_url, $response);
                        }
                    }

                    if ($options['extract_src']) {
                        // Extrahiere SRC-Attribute
                        $links = $hp->filter('[src]');
                        if ($this->logger) {
                            $this->logger->debug('Anzahl gefundene SRCs: ' . count($links));
                        }
                        foreach ($links as $link) {
                            $url = $link->getAttribute('src');
                            $this->handleFoundUrl($url, $request_url, $response);
                        }
                        // TODO: extract srcset attributes
                    }
                }
            } else {
                if ($options['look_in_css'] && $content_type == 'text/css') {
                    // Extrahiere URLs aus CSS
                    $css = $response->getBody()->getContents();
                    if ($i = preg_match_all('/url\(["\']?([^)]+)["\']?\)/', $css, $matches)) {
                        if ($this->logger) {
                            $this->logger->debug('Anzahl gefundene CSS-URLs: ' . $i);
                        }
                        foreach ($matches[1] as $url) {
                            $this->handleFoundUrl($url, $request_url, $response);
                        }
                    }
                }
            }
        }
    }

    /**
     * Do something with a URL found in a reponse.
     * (In most cases, add it to spider via $this->spider->addUrl() if it matches some criteria)
     *
     * @param string $url
     * @param string $refering_url
     * @param ResponseInterface $response Reponse where the url was found
     * @return string The (possibly transformed) url
     */
    protected function handleFoundUrl($url, $refering_url, &$response)
    {
        $url = trim($url);
        if ($url && $url != $refering_url) {
            $urlo = Psr7\UriNormalizer::normalize(Psr7\uri_for($url));

            // ignore local part of url
            if ($urlo->getFragment() && $this->options['discard_fragment']) {
                $urlo = $urlo->withFragment('');
            }

            $referer_urlo = Psr7\UriNormalizer::normalize(Psr7\uri_for($refering_url));

            if (!Psr7\Uri::isAbsolute($urlo)) {
                $urlo = psr7\UriResolver::resolve($referer_urlo, $urlo);
            }

            // more normalizers
            foreach ($this->url_normalizers as $normalizer) {
                $urlo = \call_user_func($normalizer, $urlo);
            }

            if (
                !(Psr7\Uri::isSameDocumentReference($urlo, $referer_urlo))
                && !(Psr7\UriNormalizer::isEquivalent($urlo, $referer_urlo))
                && $this->getUrlfilterFetch()->filter($urlo)
            ) {
                if ($this->logger) $this->logger->debug('URL will be added to spider: ' . $urlo);

                if (!isset($this->referer[(string) $urlo])) {
                    $this->referer[(string) $urlo] = [];
                }
                $this->referer[(string) $urlo][$refering_url] = $url;
                try {
                    $this->spider->addUrl($urlo);
                } catch (\Exception $e) {
                    if ($this->logger) {
                        $this->logger->warning(sprintf('URL %s could not be added to spider.', $urlo));
                    }
                }
            }
            return (string) $urlo;
        }
        return $url;
    }

    /**
     * Add a callable to transform discovered urls before adding to spider.
     * Must accept and return an UriInterface
     * @param callable $normalizer
     */
    public function addUrlNormalizer(callable $normalizer)
    {
        $this->url_normalizers[] = $normalizer;
    }
}