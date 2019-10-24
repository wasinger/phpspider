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
 *  This class is to be used as base class for applications that spider web pages.
 */
abstract class AbstractSpider
{
    use LoggerAwareTrait;

    /**
     * @var HttpClientQueue
     */
    protected $clientQueue;

    /**
     * @var array holds refering pages for each found url
     */
    protected $referers = [];

    /**
     * @var array holds linked text for each found url
     */
    protected $linktexts = [];

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
     * @param HttpClientQueue $clientQueue
     * @param array $options
     */
    public function __construct(HttpClientQueue $clientQueue, $options = [])
    {
        $this->options = \array_replace([
            'discard_fragment' => true
        ], $options);

        $clientQueue->addResponseListener([$this, 'handleResponseEvent']);
        $clientQueue->addExceptionListener([$this, 'handleExceptionEvent']);
        $clientQueue->addRedirectListener([$this, 'handleRedirectEvent']);
        $this->clientQueue = $clientQueue;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->clientQueue->setLogger($logger);
    }

    /**
     * @param $start_url
     */
    public function crawl($start_url)
    {
        $this->clientQueue->addUrl($start_url);
        $this->clientQueue->start();
    }

    /**
     * @return HttpClientQueue
     */
    public function getClientQueue()
    {
        return $this->clientQueue;
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
     * @return AbstractSpider
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
     * @return AbstractSpider
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
     * @return AbstractSpider
     */
    public function setOptions($options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Get URLs of documents where a link to $url is found
     *
     * @param string $url
     * @return array of urls
     */
    public function getReferingPages($url)
    {
        if (!empty($this->referers[$url])) {
            return array_keys($this->referers[$url]);
        } else {
            return [];
        }
    }

    /**
     * Get link texts for $url from refering pages
     *
     * @param string $url
     * @return array of text strings
     */
    public function getLinktexts($url)
    {
        if (!empty($this->linktexts[$url])) {
            return $this->linktexts[$url];
        } else {
            return [];
        }
    }

    public function handleExceptionEvent(HttpClientExceptionEvent $event)
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

    public function handleRedirectEvent(HttpClientRedirectEvent $e)
    {
        $response = $e->getResponse();
        $this->handleFoundUrl($e->getRedirectUrl(), $e->getRequestUrl(), $response);
    }

    /**
     * do something with the response.
     *
     * E.g., use $this->findUrls() to find more URLs in the response content (if it is an HTML document)
     *
     * @param HttpClientResponseEvent $event
     * @return mixed
     */
    abstract function handleResponseEvent(HttpClientResponseEvent $event);

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
                    $this->logger->debug('HTML document: Looking for more links...');
                }
                $hp = new Crawler((string)$response->getBody());

                if ($hp && count($hp)) {

                    if ($options['extract_href']) {
                        // Extrahiere HREF-Attribute
                        $links = $hp->filter('[href]');
                        if ($this->logger) {
                            $this->logger->debug('Found number of HREFs: ' . count($links));
                        }
                        foreach ($links as $link) {
                            $url = $link->getAttribute('href');
                            $this->handleFoundUrl($url, $request_url, $response, $link->textContent);
                        }
                    }

                    if ($options['extract_src']) {
                        // Extrahiere SRC-Attribute
                        $links = $hp->filter('[src]');
                        if ($this->logger) {
                            $this->logger->debug('Found number of SRCs: ' . count($links));
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
                            $this->logger->debug('Found CSS urls: ' . $i);
                        }
                        foreach ($matches[1] as $url) {
                            $this->handleFoundUrl($url, $request_url, $response);
                        }
                    }
                }
            }
        } else {
            if ($this->logger) {
                $this->logger->debug('UrlFilterLinkextract: not looking for more links in ' . $request_url);
            }
        }
    }

    /**
     * Do something with a URL found in a reponse content.
     * (In most cases, add it to the queue of the urls to fetch via $this->clientQueue->addUrl() if it matches some criteria)
     *
     * For URLs rejected by UrlFilterFetch the method handleRejectedUrl() will be called
     *
     * @param string $url
     * @param string $refering_url
     * @param ResponseInterface $response Reponse where the url was found
     * @return string The (possibly transformed) url
     */
    protected function handleFoundUrl($url, $refering_url, &$response, $linktext = '')
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
            ) {
                if ($this->getUrlfilterFetch()->filter($urlo)) {
                    if ($this->logger) {
                        $this->logger->debug('URL will be added to spider: ' . $urlo);
                    }

                    // add to referers array
                    if (!isset($this->referers[(string)$urlo])) {
                        $this->referers[(string)$urlo] = [];
                    }
                    $this->referers[(string)$urlo][$refering_url] = $url;
                    if ($linktext) {
                        // add to linktexts array
                        if (!isset($this->linktexts[(string)$urlo])) {
                            $this->linktexts[(string)$urlo] = [];
                        }
                        $this->linktexts[(string)$urlo][$refering_url] = $linktext;
                    }
                    try {
                        $this->clientQueue->addUrl($urlo);
                    } catch (\Exception $e) {
                        if ($this->logger) {
                            $this->logger->warning(sprintf('URL %s could not be added to spider.', $urlo));
                        }
                    }
                } else {
                    $this->handleRejectedUrl($urlo, $refering_url, $response, $linktext);
                }
            }
            return (string) $urlo;
        }
        return $url;
    }

    /**
     * @param UriInterface $url
     * @param $refering_url
     * @param $response
     * @param string $linktext
     */
    protected function handleRejectedUrl(UriInterface $url, $refering_url, &$response, $linktext = '')
    {
        if ($this->logger) $this->logger->debug('REJECTED by UrlFilter: ' . $url);
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