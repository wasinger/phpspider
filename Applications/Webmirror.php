<?php
namespace Wa72\Spider\Applications;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\UriInterface;
use Wa72\Spider\Core\AbstractSpider;
use Wa72\Spider\Core\HttpClientQueue;
use Wa72\Spider\Core\HttpClientRedirectEvent;
use Wa72\Spider\Core\HttpClientResponseEvent;

/**
 * Mirror a web site to a specified directory
 *
 */
class Webmirror extends AbstractSpider
{
    protected string $output_dir;
    protected string $path_prefix = '';
    protected array $additional_urls = [];
    protected string $hostname;
    protected array $hashes;
    protected array $redirect_paths;
    protected array $files_seen;

    /**
     * Functions that are called before saving response body content
     * Must accept two arguments: content type, response
     *
     * @var Callable[]
     */
    protected array $body_save_listeners = [];

    public function __construct(string $output_dir, HttpClientQueue $clientQueue, string $path_prefix = '')
    {
        parent::__construct($clientQueue);
        $this->output_dir = realpath($output_dir);
        $this->path_prefix = $path_prefix;
//        $this->addUrlRewriter([$this, 'rewrite_links']);
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
        file_put_contents($this->output_dir . '/.archive', date('Y-m-d H:i'));

        $this->clientQueue->start();

        // remove files not existing anymore
        $ff = function() {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->output_dir));
            foreach ($rii as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isDir() || substr($file->getFilename(), 0, 1) === '.') {
                    continue;
                }
                yield $file->getPathname();
            }
        };
        foreach ($ff() as $file) {
            if (!in_array($file, $this->files_seen)) {
                unlink($file);
                $this->logger->info(sprintf('file %s does not exist anymore, deleted.', $file));
            }
        }
    }

    public function handleResponseEvent(HttpClientResponseEvent $event)
    {
        $response = $event->getResponse();
        $request_url = $event->getRequestUrl();
        $response = $this->workOnResponse($request_url, $event->getContentType(), $response, [
            'extract_href' => true,
            'extract_src' => true,
            'look_in_css' => true,
            'rewrite_urls' => false
        ]);
        if (!empty($this->body_save_listeners)) {
            foreach ($this->body_save_listeners as $callable) {
                $response = \call_user_func($callable, $event->getContentType(), $response);
            }
        }
        $this->save($request_url, $response);
    }

    public function handleRedirectEvent(HttpClientRedirectEvent $e) {
        parent::handleRedirectEvent($e);

        // remember redirects for saving
        $request_url = $e->getRequestUrl();
        $redirect_url = $e->getRedirectUrl();

        $request_uri_object = UriNormalizer::normalize(Utils::uriFor($request_url));
        $redirect_uri_object = UriNormalizer::normalize(Utils::uriFor($redirect_url));

        if (!Uri::isAbsolute($redirect_uri_object)) {
            $redirect_uri_object = UriResolver::resolve($request_uri_object, $redirect_uri_object);
        }
        if ($redirect_uri_object->getHost() == $this->hostname) {
            $file1 = $this->compute_filename_for_uri($request_uri_object);
            $file2 = $this->compute_filename_for_uri($redirect_uri_object);
            if ($file1 != $file2) {
                $aliases = [$file1];
                if (!empty($this->redirect_paths[$file2])) {
                    $aliases = array_merge($aliases, $this->redirect_paths[$file2]);
                }
                $this->redirect_paths[$file2] = $aliases;
            }
        }

    }

//    public function rewrite_links($accepted, string $original_url, UriInterface $rewritten_url = null)
//    {
//        $url = Utils::uriFor($original_url);
//        if ($url->getScheme() == 'data' || $url->getScheme() == 'mailto' || $url->getScheme() == 'tel') {
//            return $url;
//        }
//        if ($accepted && $rewritten_url) {
//            if ($rewritten_url->getHost() == $this->hostname) {
//                $rewritten_url = $rewritten_url->withScheme('')->withHost('');
//            }
//
//            # merge query hash into path
//            if ($rewritten_url->getQuery()) {
//                $rewritten_url = $rewritten_url->withPath($this->compute_filename_for_uri($rewritten_url));
//                $rewritten_url = $rewritten_url->withQuery('');
//            }
//
//            # re-add fragment from original url
//            if ($url->getFragment()) {
//                $rewritten_url = $rewritten_url->withFragment($url->getFragment());
//            }
//
//            //            if (substr($url, 0, 1) === '/') {
////                $url = $this->link_prefix . $url;
////            }
//            if ($this->path_prefix) {
//                $rewritten_url = $rewritten_url->withPath($this->path_prefix . $rewritten_url->getPath());
//            }
//
//            $url = $rewritten_url;
//        }
//        return $url;
//    }

    /**
     * Compute a file path for saving.
     * If there is no filename with an extension, add /index.html.
     * Merge query hash into file path.
     *
     * @param UriInterface $uri
     * @return string
     */
    protected function compute_filename_for_uri(UriInterface $uri): string
    {
        $query = $uri->getQuery();
        $path = $uri->getPath();
        $filename = basename($path);
        if (substr($path, -1) == '/') {
            $path .= 'index.html';
        } else if (strpos($filename, '.') === false) {
            $path = $path . '/index.html';
        }
        if ($query) {
//            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $path = $path . '?' . $query;
        }
        return $path;
    }

    /**
     * @param $url
     * @param ResponseInterface $response
     */
    protected function save($url, ResponseInterface $response)
    {
        $filename = $this->compute_filename_for_uri(Utils::uriFor($url));
        $path = $this->output_dir . $filename;
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        $save = true;
        $last_modified = $response->getHeader('last-modified');
        $md5_response = md5((string) $response->getBody());
        if (empty($this->hashes[$md5_response])) {
            $this->hashes[$md5_response] = [];
        }
        if (!in_array($path, $this->hashes[$md5_response])) {
            $this->hashes[$md5_response][] = $path;
        }

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
            } else {
                $md5_existing = md5_file($path);
                if ($md5_existing === $md5_response) {
                    $save = false;
                    $this->logger->debug(sprintf('%s not saved because checksum has not changed', $path));
                }
            }
        }
        if ($save) {
            $firstpath = $this->hashes[$md5_response][0];
            if (count($this->hashes[$md5_response]) > 1 && file_exists($firstpath)) {
                link($firstpath, $path);
                $this->logger->info(sprintf('%s created as link to %s because of identical content', $path, $firstpath));
            } else {
                file_put_contents($path, $response->getBody());
                if ($this->logger) $this->logger->info('File saved: ' . $path);
            }
            if (!empty($last_modified)) {
                touch($path, $last_modified);
            }
        } else {
            if ($this->logger) $this->logger->info('File already exists: ' . $path);
        }

        $this->files_seen[] = $path;

        // check for aliases (links)
        if (!empty($this->redirect_paths[$filename])) {
            foreach ($this->redirect_paths[$filename] as $alias) {
                $aliaspath = $this->output_dir . $alias;
                $this->files_seen[] = $aliaspath;
                if (!file_exists($aliaspath)) {
                    $aliasdir = dirname($aliaspath);
                    if (!file_exists($aliasdir)) {
                        mkdir($aliasdir, 0777, true);
                    }
                    symlink($path, $aliaspath);
                    $this->logger->info(sprintf('%s created as symlink to %s because of redirect', $aliaspath, $path));
                }
            }
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