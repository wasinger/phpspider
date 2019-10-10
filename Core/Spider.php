<?php
namespace Wa72\Spider\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class that wraps a GuzzleHttp Client together with a URL queue
 * and dispatches events when responses are receiceved.
 *
 * Use addUrl() to add URLs to the queue and run($starturl) to start spidering
 *
 */
class Spider
{
    use LoggerAwareTrait;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var UriInterface
     */
    private $starturl;

    /**
     * @var EventDispatcher
     */
    private $dispatcher;

    /**
     * @var UrlQueueInterface
     */
    private $urlqueue;

    /**
     * @var UrlFilter
     */
    private $urlfilter;

    /**
     * @var bool
     */
    private $started = false;

    /**
     * @param Client|null $client
     * @param EventDispatcher|null $dispatcher
     * @param UrlQueueInterface|null $urlqueue
     */
    public function __construct($client = null, $dispatcher = null, $urlqueue = null)
    {
        if ($client instanceof Client) $this->client = $client;
        if ($dispatcher instanceof EventDispatcher) $this->dispatcher = $dispatcher;
        if ($urlqueue instanceof UrlQueueInterface) $this->urlqueue = $urlqueue;
    }

    /**
     * Add a URL to the run queue. Must be an absolute http(s) url.
     * If the URL is already queued or visited it will not be added.
     * Set the 'force' parameter to true to add an already visited url.
     *
     * @param UriInterface|string $url Absolute http(s) url to add to the run queue
     * @param bool $force If true, the url will be added to the queue even if it is already visited.
     * @throws \Exception
     */
    public function addUrl($url, $force = false)
    {
        if (!$this->started) {
            throw new \Exception('Spider must be started via run() method before adding more URLs');
        }
        if (!$url instanceof UriInterface) {
            $url = Psr7\uri_for($url);
        }

        if (!Psr7\Uri::isAbsolute($url)) {
            throw new \InvalidArgumentException('Only absolute HTTP(S) urls are accepted by spider.');
        }

        $url = Psr7\UriNormalizer::normalize($url);

        $url = (string) $url;

        $this->urlqueue->addUrl($url, $force);
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        if (!($this->client instanceof Client)) {
            $this->client = new Client();
        }
        return $this->client;
    }

    /**
     * @param Client $client
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return EventDispatcher
     */
    public function getDispatcher()
    {
        if (!($this->dispatcher instanceof EventDispatcher)) {
            $this->dispatcher = new EventDispatcher();
        }
        return $this->dispatcher;
    }

    /**
     * @param EventDispatcher $dispatcher
     */
    public function setDispatcher(EventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @return UrlQueueInterface
     */
    public function getUrlqueue()
    {
        return $this->urlqueue;
    }

    /**
     * @param UrlQueueInterface $urlqueue
     */
    public function setUrlqueue(UrlQueueInterface $urlqueue)
    {
        $this->urlqueue = $urlqueue;
    }

    /**
     * @param callable $listener A callable that expects one parameter of type SpiderResponseEvent
     */
    public function addResponseListener(callable $listener)
    {
        $this->getDispatcher()->addListener(SpiderResponseEvent::NAME, $listener);
    }
    /**
     * @param callable $listener A callable that expects one parameter of type SpiderRedirectEvent
     */
    public function addRedirectListener(callable $listener)
    {
        $this->getDispatcher()->addListener(SpiderRedirectEvent::NAME, $listener);
    }
    /**
     * @param callable $listener A callable that expects one parameter of type SpiderExceptionEvent
     */
    public function addExceptionListener(callable $listener)
    {
        $this->getDispatcher()->addListener(SpiderExceptionEvent::NAME, $listener);
    }

    /**
     * @param $start_url
     * @param array $options {
     *   int $concurrent_requests
     * }
     */
    public function run($start_url, $options = [])
    {
        $options = \array_replace([
            'concurrent_requests' => 1,
            'method' => 'get'
        ], $options);

        $this->starturl = psr7\uri_for($start_url);

        if (!($this->client instanceof Client)) {
            $this->client = new Client();
        }

        if (!($this->dispatcher instanceof EventDispatcher)) $this->dispatcher = new EventDispatcher();
        if (!($this->urlqueue instanceof UrlQueueInterface)) {
            $this->urlqueue = new UrlQueue();
            if ($this->logger) $this->urlqueue->setLogger($this->logger);
        }

        $this->started = true;

        if ($this->logger) $this->logger->info('Start spidering at ' . $start_url);

        try {
            $this->addUrl($start_url);
        } catch (\Exception $e) {

        }

        if ($options['concurrent_requests'] > 1 && function_exists('curl_multi_exec')) {
            // async: we need a handle for CurlMultiHandler
            $curl = new CurlMultiHandler();
            $this->client->getConfig('handler')->setHandler($curl);
            if ($this->logger) $this->logger->info('Spider: Use ' . $options['concurrent_requests'] . ' concurrent requests.');

            /**
             * @var Promise\PromiseInterface[] $promises
             */
            $promises = [];
            $dispatcher = &$this->dispatcher;
            $request_counter = 0;

            $check_promises_state = function () use (&$promises, &$dispatcher, &$request_counter) {
                foreach ($promises as $key => $promise) {
                    if (Promise\is_settled($promise)) {
                        unset($promises[$key]);
                        $request_counter--;
                    }
                }
            };

            while (($url = $this->urlqueue->next()) || $request_counter > 0) {
                if (empty($url)) {
                    // no url, but request pending:
                    // wait for at least one request to complete, then check again for new urls
                    $old_request_counter = $request_counter;
                    do {
                        $curl->tick();
                        \usleep(250);
                        $check_promises_state();
                    } while ($request_counter == $old_request_counter);
                    continue;
                }

                if ($request_counter >= $options['concurrent_requests']) {
                    if ($this->logger) {
                        $this->logger->debug('Request counter to high, waiting for requests to complete...');
                    }
                    do {
                        $curl->tick();
                        // max. $options['concurrent_requests'] concurrent requests
                        // wait until at least one finishes
                        \usleep(250);
                        $check_promises_state();
                    } while ($request_counter >= $options['concurrent_requests']);

                    if ($this->logger) {
                        $this->logger->debug('Requests settled, continue adding more requests.');
                    }
                }
                $promise = $this->client->requestAsync($options['method'], $url, [
                    'allow_redirects' => false
                ]);
                if ($this->logger) {
                    $this->logger->info('Async request started for ' . $url);
                }
                $request_counter++;
                $promise->then(
                    function (ResponseInterface $response) use (&$dispatcher, $url) {
                        $code = $response->getStatusCode();
                        if (\in_array($code, [301, 302, 303, 307, 308])) { // Response is a redirect
                            $redirect_url = $response->getHeaderLine('Location');
                            $dispatcher->dispatch(
                                SpiderRedirectEvent::NAME,
                                new SpiderRedirectEvent($url, $redirect_url, $code, $response)
                            );
                        } else {
                            $dispatcher->dispatch(
                                SpiderResponseEvent::NAME,
                                new SpiderResponseEvent($url, $response)
                            );
                        }
                    },
                    function (RequestException $e) use (&$dispatcher, $url) {
                        $dispatcher->dispatch(
                            SpiderExceptionEvent::NAME,
                            new SpiderExceptionEvent($url, $e)
                        );
                    }
                );
                $promises[] = $promise;
                $curl->tick();
            }
        } else { // synchronous calls
            while ($url = $this->urlqueue->next()) {
                try {
                    $response = $this->client->request($options['method'], $url, [
                        'allow_redirects' => false
                    ]);
                    $code = $response->getStatusCode();
                    if (\in_array($code, [301, 302, 303, 307, 308])) { // Response is a redirect
                        $redirect_url = $response->getHeaderLine('Location');
                        $this->dispatcher->dispatch(
                            SpiderRedirectEvent::NAME,
                            new SpiderRedirectEvent($url, $redirect_url, $code, $response)
                        );
                    } else {
                        $this->dispatcher->dispatch(
                            SpiderResponseEvent::NAME,
                            new SpiderResponseEvent($url, $response)
                        );
                    }
                } catch (RequestException  $e) {
                    $this->dispatcher->dispatch(
                        SpiderExceptionEvent::NAME,
                        new SpiderExceptionEvent($url, $e)
                    );
                }
            }
        }
    }

    /**
     * @return UriInterface
     */
    public function getStartUrl()
    {
        return $this->starturl;
    }

}