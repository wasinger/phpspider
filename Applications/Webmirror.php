<?php
namespace Wa72\Spider\Applications;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7;
use Wa72\Spider\Core\AbstractSpider;
use Wa72\Spider\Core\HttpClientQueue;
use Wa72\Spider\Core\HttpClientResponseEvent;

/**
 * Mirror a web site to a specified directory
 *
 */
class Webmirror extends AbstractSpider
{
    private $output_dir;

    /**
     * @param string $output_dir
     * @param \Wa72\Spider\Core\HttpClientQueue $clientQueue
     */
    public function __construct($output_dir, $clientQueue = null)
    {
        parent::__construct($clientQueue);
        $this->output_dir = $output_dir;
    }

    public function crawl($start_url)
    {
        $url = Psr7\uri_for($start_url);
        // Webmirror: stay on one host
        $this->getUrlfilterFetch()->addAllowedHost($url->getHost());
        $this->clientQueue->addUrl($start_url);
        $this->clientQueue->start();
    }

    public function handleResponseEvent(HttpClientResponseEvent $event)
    {
        $response = $event->getResponse();
        $request_url = $event->getRequestUrl();
        $this->findUrls($request_url, $event->getContentType(), $response, [
            'extract_href' => true,
            'extract_src' => true,
            'look_in_css' => true,
        ]);
        $this->save($request_url, $response);
    }

    private function correct_path($path)
    {
        $filename = basename($path);
        if (substr($path, -1) == '/') {
            $path .= 'index.html';
        } else if (strpos($filename, '.') === false) {
            $path = $path . '/index.html';
        }
        return $path;
    }

    private function prepare_fs_path($request_path)
    {
        $path = $this->correct_path($request_path);
        $path = $this->output_dir . $path;
        return $path;
    }

    /**
     * @param $url
     * @param ResponseInterface|string $response
     */
    private function save($url, $response)
    {
        $path = $this->prepare_fs_path(Psr7\uri_for($url)->getPath());
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, ($response instanceof ResponseInterface ? $response->getBody() : $response));
    }
}