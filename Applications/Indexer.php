<?php
namespace Wa72\Spider\Applications;


use Psr\Http\Message\ResponseInterface;
use Wa72\Spider\Core\AbstractSpiderApplication;
use Wa72\Spider\Core\SpiderResponseEvent;
use GuzzleHttp\Psr7;

/**
 * Abstract base class for a custom web search indexer
 *
 */
abstract class Indexer extends AbstractSpiderApplication
{
    public function run($start_url)
    {
        $url = Psr7\uri_for($start_url);
        // Indexer: add Host of start_url to allowed hosts
        // This limits crawling to this host.
        // You can allow more hosts by adding them via addAllowedHost() method.
        $this->getUrlfilterFetch()->addAllowedHost($url->getHost());
        parent::run($start_url);
    }

    public function handleResponseEvent(SpiderResponseEvent $event)
    {
        $response = $event->getResponse();
        $request_url = $event->getRequestUrl();
        // Index the received content
        $this->index($request_url, $event->getContentType(), $response);
        // Look for more URLs to spider
        $this->findUrls($request_url, $event->getContentType(), $response);
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