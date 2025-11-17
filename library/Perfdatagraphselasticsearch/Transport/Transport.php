<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Transport;

use GuzzleHttp\Psr7\Request;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use RuntimeException;
use InvalidArgumentException;

/**
 * Transport uses an HTTP client and HostPool to send requests to a host
 */
final class Transport implements ClientInterface
{
    private array $headers = [];
    private string $user;
    private string $password;

    // Number of retries when sending HTTP requests
    protected int $retries = 1;

    // HTTP client to connect to hosts
    private ClientInterface $client;
    // Hosts to connect to
    private HostPoolInterface $hostPool;

    public function __construct(ClientInterface $client, HostPoolInterface $pool)
    {
        $this->client = $client;
        $this->hostPool = $pool;

        // Default headers
        $this->headers['Accept'] = 'application/json';
        $this->headers['Content-Type'] = 'application/json';
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    public function setRetries(int $n): self
    {
        if ($n < 0) {
            throw new InvalidArgumentException('Retries must be a positive integer');
        }

        $this->retries = $n;
        return $this;
    }

    /**
     * getClient returns the configured HTTP client
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * getHeaders returns the configured HTTP headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * setHeader adds a new HTTP header
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * setBasicAuth sets the user and password for basic auth
     */
    public function setBasicAuth(string $user, string $password = ''): self
    {
        $this->user = $user;
        $this->password = $password;
        return $this;
    }

    /**
     * sendRequest uses the HostPool to send the given request to a reachable host
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $pingRequest = new Request('HEAD', '/');
        $pingRequest = $this->prepareRequest($pingRequest);

        $host = $this->hostPool->next($pingRequest);
        $req = $this->prepareRequest($request, $host);

        $retryCount = 0;

        while ($retryCount < $this->getRetries()) {
            $retryCount++;
            try {
                $response = $this->client->sendRequest($req);
                return $response;
            } catch (NetworkExceptionInterface $e) {
                // Did not reach this host
                $host->setReachable(false);
                // Use the next host
                $host = $this->hostPool->next($pingRequest);
                // Update the request
                $req = $this->prepareRequest($request, $host);
            }
        }

        throw new RuntimeException('No host reachable');
    }

    /**
     * prepareRequest enriches the given request with the Transport's configuration
     */
    protected function prepareRequest(RequestInterface $request, ?Host $host = null): RequestInterface
    {
        $path = $request->getUri()->getPath();

        // Set the host if passed
        if (isset($host)) {
            $uri = $host->getURL();

            $request = $request->withUri(
                $request->getUri()
                    ->withHost($uri->getHost())
                    ->withPort($uri->getPort())
                    ->withScheme($uri->getScheme())
                    ->withPath($path)
            );
        }

        // Add the configured headers to the request
        foreach ($this->headers as $name => $value) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }

        // Add the configured basic auth to the request
        $uri = $request->getUri();
        if (empty($uri->getUserInfo())) {
            if (isset($this->user)) {
                $request = $request->withUri($uri->withUserInfo($this->user, $this->password));
            }
        }

        return $request;
    }
}
