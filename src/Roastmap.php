<?php

declare(strict_types=1);

namespace Prerender\Roastmap;

use Amp\CancellationToken;
use Amp\Delayed;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\NetworkInterceptor;
use Amp\Http\Client\Request;
use Amp\NullCancellationToken;
use Amp\Promise;
use Kelunik\Retry\ConstantBackoff;
use League\Uri\Contracts\UriInterface;
use Spacetab\Logger\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use vipnytt\SitemapParser;
use function Amp\call;
use function Kelunik\Retry\retry;

class Roastmap
{
    private const LOG_CHANNEL            = 'Roastmap';
    private const USER_AGENT             = 'Roastmap/1.0.0/bot';
    private const DEFAULT_DELAY          = 100;
    private const MAX_FOLLOW_REDIRECTS   = 3;
    private const REQUEST_RETRY_ATTEMPTS = 10;
    private const REQUEST_RETRY_DELAY    = 10000;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var \League\Uri\Contracts\UriInterface
     */
    private UriInterface $uri;

    private int $processedRequestsCount = 0;

    /**
     * Roastmap constructor.
     *
     * @param \League\Uri\Contracts\UriInterface $uri
     */
    public function __construct(UriInterface $uri)
    {
        $this->uri    = $uri;
        $this->logger = Logger::default(self::LOG_CHANNEL, $this->getLogLevel());
    }

    /**
     * @param int $parallel
     * @param int $times
     * @param int $delay
     * @return Promise
     * @throws \vipnytt\SitemapParser\Exceptions\SitemapParserException
     */
    public function run(int $parallel = 3, int $times = 1, int $delay = 3000): Promise
    {
        $format = 'Start options: host: %s, parallel:%d, times:%d, delay:%d';
        $this->logger->info(sprintf($format, $this->uri->getHost(), $parallel, $times, $delay));

        $client = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool())
            ->followRedirects(self::MAX_FOLLOW_REDIRECTS)
            ->interceptNetwork($this->buildNetworkInterceptor())
            ->build();

        $urls = $this->getUrls();

        return call(function () use ($times, $client, $urls, $parallel, $delay) {
            $statistics = [];
            $count = count($urls);

            while ($times > 0) {
                foreach (array_chunk($urls, $parallel) as $chunk) {
                    $promises = [];

                    foreach ($chunk as $tags) {
                        $promises[$tags['loc']] = $this->reqJob($client, $tags, $delay);
                    }

                    $responses = yield $promises;

                    $this->processedRequestsCount += $parallel;
                    $this->logger->info("{$this->processedRequestsCount} requests of {$count} was send ...");

                    if ($delay > 0) {
                        $this->logger->info("Delay between requests: {$delay}ms");
                    }

                    foreach ($responses as $url => $stat) {
                        $statistics[$url] = $stat;
                    }
                }

                $times--;
            }

            return $statistics;
        });
    }

    /**
     * @return \Amp\Http\Client\NetworkInterceptor
     */
    private function buildNetworkInterceptor(): NetworkInterceptor
    {
        return new class($this->logger) implements NetworkInterceptor {
            private LoggerInterface $logger;

            public function __construct(LoggerInterface $logger) {
                $this->logger = $logger;
            }

            public function requestViaNetwork(
                Request $request,
                CancellationToken $cancellation,
                Stream $stream
            ): Promise {
                return call(function () use ($request, $cancellation, $stream) {
                    $this->logger->debug("Starting request to {$request->getUri()}...");

                    $start = microtime(true);
                    /** @var \Amp\Http\Client\Response $response */

                    $response = yield $stream->request($request, new NullCancellationToken());
                    $end = microtime(true);
                    $time = round($end - $start, 2);

                    $this->logger->debug("Done in {$time}ms @ {$response->getStatus()} | {$request->getUri()}");

                    return $response;
                });
            }
        };
    }

    /**
     * @param $client
     * @param $tags
     * @param $delay
     *
     * @return \Amp\Promise
     */
    private function reqJob(HttpClient $client, array $tags, int $delay = self::DEFAULT_DELAY): Promise
    {
        return $this->retry(function() use ($client, $tags, $delay) {
            if ($delay > 0) {
                yield new Delayed($delay);
            }

            $request = new Request($tags['loc']);
            $request->setHeader('User-Agent', self::USER_AGENT);

            /** @var \Amp\Http\Client\Response $response */
            $response = yield $client->request($request);
            $body = yield $response->getBody()->buffer();

            return [
                $response->getStatus(), strlen($body)
            ];
        });
    }

    /**
     * @return array
     * @throws \vipnytt\SitemapParser\Exceptions\SitemapParserException
     */
    private function getUrls(): array
    {
        $parser = new SitemapParser(self::USER_AGENT);
        $parser->parseRecursive($this->getRobotsUri());

        foreach ($parser->getSitemaps() as $url => $tags) {
            $this->logger->info("Parsed SiteMap URI from robots.txt: {$url}");
        }

        $this->logger->info('Let\'s Go receiving links from XML files...');

        return $parser->getURLs();
    }

    /**
     * @return string
     */
    private function getRobotsUri(): string
    {
        return "{$this->uri->getScheme()}://{$this->uri->getHost()}/robots.txt";
    }

    /**
     * @return string
     */
    private function getLogLevel(): string
    {
        return getenv('DEBUG') === '1' || getenv('DEBUG') === 'true'
            ? LogLevel::DEBUG
            : LogLevel::INFO;
    }

    /**
     * @param callable $callback
     * @return \Amp\Promise
     */
    protected function retry(callable $callback): Promise
    {
        return retry(self::REQUEST_RETRY_ATTEMPTS, $callback, \Exception::class, new ConstantBackoff(self::REQUEST_RETRY_DELAY));
    }
}
