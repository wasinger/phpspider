<?php
namespace Wa72\Spider\Core;

use GuzzleHttp\Psr7;
use Psr\Http\Message\UriInterface;

/**
 * Class for filtering which URLs to spider
 */
class UrlFilter
{
    private $reject_paths = [];

    private $allowed_hosts = [];

    private $allowed_schemes = ['http', 'https'];

    /**
     * @param $regex
     * @return UrlFilter
     */
    public function rejectPathByRegex($regex)
    {
        $this->reject_paths[] = $regex;
        return $this;
    }

    /**
     * @param $hostname
     * @return UrlFilter
     */
    public function addAllowedHost($hostname)
    {
        $this->allowed_hosts[] = $hostname;
        return $this;
    }

    /**
     * @param array $schemes
     * @return UrlFilter
     */
    public function setAllowedSchemes(array $schemes = ['http', 'https'])
    {
        $this->allowed_schemes = $schemes;
        return $this;
    }

    /**
     * @param $url
     * @return boolean
     */
    public function filter($url)
    {
        if (!($url instanceof UriInterface)) {
            $url = Psr7\uri_for($url);
        }
        return $this->filterScheme($url) && $this->filterHost($url) && $this->filterPath($url);
    }

    private function filterHost(UriInterface $url)
    {
        // if not host filter is set allow all hosts
        if (empty($this->allowed_hosts)) {
            return true;
        }
        foreach ($this->allowed_hosts as $host) {
            if ($url->getHost() == $host) {
                return true;
            }
        }
        return false;
    }

    private function filterScheme(UriInterface $url)
    {
        foreach ($this->allowed_schemes as $scheme) {
            if ($url->getScheme() == $scheme) {
                return true;
            }
        }
        return false;
    }

    private function filterPath(UriInterface $url)
    {
        foreach ($this->reject_paths as $regex) {
            if (preg_match($regex, $url->getPath())) {
                return false;
            }
        }
        return true;
    }
}