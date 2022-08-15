<?php
namespace Wa72\Spider\Applications;


use Psr\Http\Message\ResponseInterface;
use Wa72\Spider\Core\AbstractSpider;
use Wa72\Spider\Core\HttpClientResponseEvent;
use GuzzleHttp\Psr7\Utils;

/**
 * Abstract base class for a custom web search indexer
 *
 */
abstract class Indexer extends AbstractSpider
{
    protected $start_host;

    public function crawl($start_url)
    {
        $url = Utils::uriFor($start_url);
        // Indexer: add Host of start_url to allowed hosts
        // This limits crawling to this host.
        // You can allow more hosts by adding them via addAllowedHost() method.
        $this->start_host = $url->getHost();
        $this->getUrlfilterFetch()->addAllowedHost($this->start_host);
        parent::crawl($start_url);
    }

    public function handleResponseEvent(HttpClientResponseEvent $event)
    {
        $response = $event->getResponse();
        $request_url = $event->getRequestUrl();
        // Index the received content
        $this->index($request_url, $event->getContentType(), $response);
        // Look for more URLs to spider
        $this->workOnResponse($request_url, $event->getContentType(), $response);
    }

    /**
     * Implement this function to index the received content.
     * Get the string content of the response body via `(string) $response->getBody()`.
     *
     * @param string $request_url
     * @param string $contentType
     * @param ResponseInterface $response
     * @return void
     */
    abstract public function index($request_url, $contentType, ResponseInterface $response);
}