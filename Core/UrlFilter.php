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
    private $reject_params = [];
    private $allowed_hosts = [];
    private $filterfunctions = [];
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
     * @param array $filter key => value array of query parameters that should be rejected
     * @return UrlFilter
     */
    public function rejectByQueryparam(array $reject_params)
    {
        $this->reject_params = array_merge($this->reject_params, $reject_params);
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
     * Set custom filter function.
     * Pass a callable that accepts UriInterface as paramter and returns true or false.
     *
     * @param callable $func
     * @return $this
     */
    public function addFilterFunction(callable $func)
    {
        $this->filterfunctions[] = $func;
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
        return $this->filterScheme($url) && $this->filterHost($url) && $this->filterPath($url) && $this->filterQuery($url) && $this->filterByCustomFunction($url);
    }

    private function filterHost(UriInterface $url)
    {
        // if no host filter is set allow all hosts
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

    private function filterQuery(UriInterface $url)
    {
        parse_str($url->getQuery(), $urlparams);
        foreach ($this->reject_params as $param => $value) {
            if (!empty($urlparams[$param])) {
                if (is_array($urlparams[$param]) && !is_array($value)) {
                    if (in_array($value, $urlparams[$param])) {
                        return false;
                    }
                } else {
                    if ($urlparams[$param] == $value) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function filterByCustomFunction(UriInterface $url)
    {
        foreach ($this->filterfunctions as $func) {
            if (false === \call_user_func($func, $url)) {
                return false;
            }
        }
        return true;
    }
}