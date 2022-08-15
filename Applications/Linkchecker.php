<?php
namespace Wa72\Spider\Applications;


use GuzzleHttp\Exception\ClientException;
use Wa72\Spider\Core\AbstractSpider;
use Wa72\Spider\Core\HttpClientExceptionEvent;
use Wa72\Spider\Core\HttpClientResponseEvent;
use GuzzleHttp\Psr7\Utils;

/**
 * Check a website for broken links
 *
 */
class Linkchecker extends AbstractSpider
{
    private $broken_links = [];

    public function reportBrokenLinks()
    {
        $pages_with_broken_links = [];
        foreach ($this->broken_links as $link) {
            foreach (array_keys($this->referers[$link]) as $ref) {
                $pages_with_broken_links[$ref][] = $link;
            }
        }
        foreach ($pages_with_broken_links as $page => $deadlinks) {
            echo 'Tote Links auf Seite `' . $page . '`: ' . join(',', $deadlinks) . "\n";
        }
    }

    public function crawl($start_url)
    {
        $url = Utils::uriFor($start_url);
        // Linkchecker: crawl for more links in responses only on own host
        $this->getUrlfilterLinkextract()->addAllowedHost($url->getHost());
        parent::crawl($start_url);
    }

    public function handleExceptionEvent(HttpClientExceptionEvent $event)
    {
        $e = $event->getException();
        $url = $event->getRequestUrl();

        if ($e instanceof ClientException && $event->getResponse()->getStatusCode() == 404) { // 4xx Error codes
            $this->broken_links[] = $url;
            if ($this->logger) {
                $response = $e->getResponse();
                $refering_pages = [];
                if (!empty($this->referers[$url])) {
                    $refering_pages = array_keys($this->referers[$url]);
                }
                $this->logger->error(sprintf('Error %s on URL %s. Refering pages: %s', $response->getStatusCode(), $url, join(', ', $refering_pages)));
            }
        } else {
            if ($this->logger) $this->logger->error(sprintf('Error on URL %s: %s', $url,  $e->getMessage()));
        }
    }

    public function handleResponseEvent(HttpClientResponseEvent $event)
    {
        $response = $event->getResponse();
        $request_url = $event->getRequestUrl();

        $this->workOnResponse($request_url, $event->getContentType(), $response);
    }
}