<?php
namespace Wa72\Spider\Applications;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\UriInterface;
use Wa72\Spider\Core\AbstractSpider;
use Wa72\Spider\Core\HttpClientQueue;
use Wa72\Spider\Core\HttpClientResponseEvent;

/**
 * Mirror a web site to a specified directory
 *
 */
class Webmirror extends AbstractSpider
{
    protected string $output_dir;
    protected string $link_prefix = '';
    protected array $additional_urls = [];
    protected string $hostname;

    /**
     * Functions that are called before saving response body content
     * Must accept two arguments: content type, response
     *
     * @var Callable[]
     */
    protected array $body_save_listeners = [];

    public function __construct(string $output_dir, HttpClientQueue $clientQueue, string $link_prefix = '')
    {
        parent::__construct($clientQueue);
        $this->output_dir = $output_dir;
        $this->link_prefix = $link_prefix;
        $this->addUrlRewriter([$this, 'rewrite_links']);
    }

    public function crawl($start_url)
    {
        $url = Utils::uriFor($start_url);
        // Webmirror: stay on one host
        $this->hostname = $url->getHost();
        $this->getUrlfilterFetch()->addAllowedHost($this->hostname);
        $this->clientQueue->addUrl($start_url);
        foreach ($this->additional_urls as $addurl) {
            $this->clientQueue->addUrl($addurl);
        }

        if (!file_exists($this->output_dir)) {
            mkdir($this->output_dir, 0777, true);
        }
        copy(__DIR__ . '/not_mirrored.php', $this->output_dir. '/not_mirrored.php');

        $this->clientQueue->start();
    }

    public function handleResponseEvent(HttpClientResponseEvent $event)
    {
        $response = $event->getResponse();
        $request_url = $event->getRequestUrl();
        $response = $this->workOnResponse($request_url, $event->getContentType(), $response, [
            'extract_href' => true,
            'extract_src' => true,
            'look_in_css' => true,
            'rewrite_urls' => true
        ]);
        if (!empty($this->body_save_listeners)) {
            foreach ($this->body_save_listeners as $callable) {
                $response = \call_user_func($callable, $event->getContentType(), $response);
            }
        }
        $this->save($request_url, $response);
    }

    public function rewrite_links($accepted, $url)
    {
        if (!$url instanceof UriInterface) {
            $url = Utils::uriFor($url);
            if ($url->getScheme() == 'data' || $url->getScheme() == 'mailto' || $url->getScheme() == 'tel') {
                return $url;
            }
        }
        if (!$accepted) {
            return $this->link_prefix . '/not_mirrored.php?url=' . urlencode((string) $url);
        }
        if ($url instanceof UriInterface) {
            $url = $url->getPath();
        }
        if (substr($url, 0, 1) === '/') {
            $url = $this->link_prefix . $url;
        }
        return $url;
    }

    protected function correct_path($path)
    {
        $filename = basename($path);
        if (substr($path, -1) == '/') {
            $path .= 'index.html';
        } else if (strpos($filename, '.') === false) {
            $path = $path . '/index.html';
        }
        return $path;
    }

    protected function prepare_fs_path($request_path)
    {
        $path = $this->correct_path($request_path);
        $path = $this->output_dir . $path;
        return $path;
    }

    /**
     * @param $url
     * @param ResponseInterface|string $response
     */
    protected function save($url, $response)
    {
        $path = $this->prepare_fs_path(Utils::uriFor($url)->getPath());
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        $save = true;
        $last_modified = $response->getHeader('last-modified');
        if (!empty($last_modified)) {
            $last_modified = (is_array($last_modified) ? $last_modified[0] : $last_modified);
            $last_modified = new \DateTimeImmutable($last_modified);
            $last_modified = $last_modified->getTimestamp();
        }
        if (file_exists($path)) {
            $filemtime = filemtime($path);
            if (!empty($last_modified)) {
                if ($last_modified <= $filemtime) {
                    $save = false;
                }
            }
        }
        if ($save) {
            file_put_contents($path, ($response instanceof ResponseInterface ? $response->getBody() : $response));
            if (!empty($last_modified)) {
                touch($path, $last_modified);
            }
            if ($this->logger) $this->logger->info('File saved: ' . $path);
        } else {
            if ($this->logger) $this->logger->info('File already exists: ' . $path);
        }
    }

    /**
     * Add a function that is called before saving response body content
     * Must accept two arguments: content type, response
     *
     * @param callable $callable
     * @return void
     */
    public function addBodySaveListener(callable $callable)
    {
        $this->body_save_listeners[] = $callable;
    }

    public function addAdditionalUrl($url)
    {
        $this->additional_urls[] = $url;
    }
}