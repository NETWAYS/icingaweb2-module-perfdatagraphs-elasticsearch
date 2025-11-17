<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Transport;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

use RuntimeException;
use Exception;

/**
 * HostPool represents a list of hosts to connect to.
 * It handles marking hosts dead or alive.
 */
class HostPool implements HostPoolInterface
{
    // List of hosts for this HostPool
    protected $hosts = [];
    // HTTP client to connect to hosts
    protected ClientInterface $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function setHosts(array $hosts): self
    {
        $this->hosts = [];

        foreach ($hosts as $host) {
            $this->hosts[] = new Host($host);
        }

        return $this;
    }

    /**
     * ping uses an HTTP request to determine if a host is reachable or not
     */
    public function ping(Host $host, RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $uri = $host->getURL();

        $request->withUri(
            $request->getUri()
                ->withHost($uri->getHost())
                ->withPort($uri->getPort())
                ->withScheme($uri->getScheme())
                ->withPath($path)
        );

        try {
            $response = $this->client->sendRequest($request);
            return $response->getStatusCode() === 200;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * next returns a reachable host
     */
    public function next(RequestInterface $request): Host
    {
        // TODO: We could use a RequestFactory in the constructur,
        // then we would not have to pass a request to ping hosts here.
        foreach ($this->hosts as $host) {
            if ($host->isReachable()) {
                return $host;
            }

            if ($this->ping($host, $request)) {
                $host->setReachable(true);
                return $host;
            }
        }

        throw new RuntimeException('No host in pool reachable');
    }
}
