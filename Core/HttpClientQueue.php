<?php
namespace Wa72\Spider\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Class that wraps a GuzzleHttp Client together with a URL queue
 * and dispatches events when responses are receiceved.
 *
 * Use addUrl() to add URLs to the queue
 *
 */
class HttpClientQueue
{
    use LoggerAwareTrait;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var UrlQueueInterface
     */
    private $urlqueue;

    /**
     * @var array
     */
    private $options;

    /**
     * @var bool
     */
    private $started = false;

    /**
     * @var bool
     */
    private $running = false;

    /**
     * @var array
     */
    private $response_listeners = [];

    /**
     * @var array
     */
    private $redirect_listeners = [];

    /**
     * @var array
     */
    private $exception_listeners = [];

    /**
     * @param array $options
     * @param Client|null $client
     * @param UrlQueueInterface|null $urlqueue
     */
    public function __construct(array $options = [], ?Client $client = null, ?UrlQueueInterface $urlqueue = null)
    {
        $this->client = $client ?: new Client();
        $this->urlqueue = $urlqueue ?: new UrlQueue();

        $this->options = \array_replace([
            'concurrent_requests' => 1,
            'method' => 'GET',
            'timeout' => 0
        ], $options);
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        foreach ([$this->client, $this->urlqueue] as &$prop) {
            if ($prop instanceof LoggerAwareInterface) {
                $prop->setLogger($logger);
            }
        }
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
        if (!$url instanceof UriInterface) {
            $url = Psr7\uri_for($url);
        }

        if (!Psr7\Uri::isAbsolute($url)) {
            throw new \InvalidArgumentException('Only absolute HTTP(S) urls are accepted by spider.');
        }

        $url = Psr7\UriNormalizer::normalize($url);

        $url = (string) $url;

        $this->urlqueue->addUrl($url, $force);
        if ($this->started && !$this->running) {
            $this->run();
        }
    }

    /**
     * @param callable $listener A callable that expects one parameter of type SpiderResponseEvent
     */
    public function addResponseListener(callable $listener)
    {
        $this->response_listeners[] = $listener;
    }
    /**
     * @param callable $listener A callable that expects one parameter of type SpiderRedirectEvent
     */
    public function addRedirectListener(callable $listener)
    {
        $this->redirect_listeners[] = $listener;
    }
    /**
     * @param callable $listener A callable that expects one parameter of type SpiderExceptionEvent
     */
    public function addExceptionListener(callable $listener)
    {
        $this->exception_listeners[] = $listener;
    }

    public function start()
    {
        if (!$this->started) {
            $this->started = true;
            $this->run();
        }
    }


    private function run()
    {
        $this->running = true;
        if ($this->logger) $this->logger->debug('HttpClientQueue: start running.');
        if ($this->options['concurrent_requests'] > 1 && function_exists('curl_multi_exec')) {
            $this->run_async();
        } else { // synchronous calls
            $this->run_sync();
        }
        $this->running = false;
        if ($this->logger) $this->logger->debug('HttpClientQueue: stop running.');
    }

    /**
     * Get the GuzzleHttp\Client instance
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @return UrlQueueInterface
     */
    public function getUrlqueue()
    {
        return $this->urlqueue;
    }

    private function run_async()
    {
        // async: we need a handle for CurlMultiHandler
        $curl = new CurlMultiHandler();
        $this->client->getConfig('handler')->setHandler($curl);
        if ($this->logger) $this->logger->debug('HttpClientQueue: Use ' . $this->options['concurrent_requests'] . ' concurrent requests.');

        /**
         * @var Promise\PromiseInterface[] $promises
         */
        $promises = [];
        $request_counter = 0;

        $check_promises_state = function () use (&$promises, &$request_counter) {
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

            if ($request_counter >= $this->options['concurrent_requests']) {
                if ($this->logger) {
                    $this->logger->debug('Request counter to high, waiting for requests to complete...');
                }
                do {
                    $curl->tick();
                    // max. $options['concurrent_requests'] concurrent requests
                    // wait until at least one finishes
                    \usleep(250);
                    $check_promises_state();
                } while ($request_counter >= $this->options['concurrent_requests']);

                if ($this->logger) {
                    $this->logger->debug('Requests settled, continue adding more requests.');
                }
            }
            $promise = $this->client->requestAsync($this->options['method'], $url, [
                'allow_redirects' => false,
                'timeout' => $this->options['timeout']
            ]);
            if ($this->logger) {
                $this->logger->debug('Async request started for ' . $url);
            }
            $request_counter++;
            $promise->then(
                function (ResponseInterface $response) use ($url) {
                    $code = $response->getStatusCode();
                    if (\in_array($code, [301, 302, 303, 307, 308])) { // Response is a redirect
                        $redirect_url = $response->getHeaderLine('Location');
                        $this->dispatchRedirectEvent(
                            new HttpClientRedirectEvent($url, $redirect_url, $code, $response)
                        );
                    } else {
                        $this->dispatchResponseEvent(
                            new HttpClientResponseEvent($url, $response)
                        );
                    }
                },
                function (RequestException $e) use ($url) {
                    $this->dispatchExceptionEvent(
                        new HttpClientExceptionEvent($url, $e)
                    );
                }
            );
            $promises[] = $promise;
            $curl->tick();
        }
    }

    private function run_sync()
    {
        if ($this->logger) $this->logger->debug('HttpClientQueue: run in synchronous mode.');
        while ($url = $this->urlqueue->next()) {
            try {
                $response = $this->client->request($this->options['method'], $url, [
                    'allow_redirects' => false,
                    'timeout' => $this->options['timeout']
                ]);
                $code = $response->getStatusCode();
                if (\in_array($code, [301, 302, 303, 307, 308])) { // Response is a redirect
                    $redirect_url = $response->getHeaderLine('Location');
                    $this->dispatchRedirectEvent(
                        new HttpClientRedirectEvent($url, $redirect_url, $code, $response)
                    );
                } else {
                    $this->dispatchResponseEvent(
                        new HttpClientResponseEvent($url, $response)
                    );
                }
            } catch (RequestException  $e) {
                $this->dispatchExceptionEvent(
                    new HttpClientExceptionEvent($url, $e)
                );
            }
        }
    }

    protected function dispatchResponseEvent(HttpClientResponseEvent $e)
    {
        foreach ($this->response_listeners as &$listener) {
            \call_user_func($listener, $e);
        }
    }

    protected function dispatchRedirectEvent(HttpClientRedirectEvent $e)
    {
        foreach ($this->redirect_listeners as &$listener) {
            \call_user_func($listener, $e);
        }
    }

    protected function dispatchExceptionEvent(HttpClientExceptionEvent $e)
    {
        foreach ($this->exception_listeners as &$listener) {
            \call_user_func($listener, $e);
        }
    }
}