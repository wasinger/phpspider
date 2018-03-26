<?php
namespace Wa72\Spider\Applications;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7;
use Wa72\Spider\Core\AbstractSpiderApplication;
use Wa72\Spider\Core\Spider;
use Wa72\Spider\Core\SpiderResponseEvent;

/**
 * Mirror a web site to a specified directory
 *
 */
class Webmirror extends AbstractSpiderApplication
{
    private $output_dir;

    /**
     * @param string $output_dir
     * @param \Wa72\Spider\Core\Spider $spider
     */
    public function __construct($output_dir, $spider = null)
    {
        parent::__construct($spider);
        $this->output_dir = $output_dir;
    }

    public function run($start_url)
    {
        $url = Psr7\uri_for($start_url);
        // Webmirror: stay on one host
        $this->getUrlfilterFetch()->addAllowedHost($url->getHost());
        $this->spider->run($start_url);
    }

    public function handleResponseEvent(SpiderResponseEvent $event)
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