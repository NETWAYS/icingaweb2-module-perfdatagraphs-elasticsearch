<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphselasticsearch\Transport\Transport;

use Icinga\Application\Logger;
use Icinga\Exception\Json\JsonDecodeException;
use Icinga\Exception\QueryException;
use Icinga\Util\Json;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Exception;

/**
 * BaseClient
 */
abstract class BaseClient
{
    protected Transport $transport;
    protected int $maxDataPoints;

    /**
     * parseDuration parses the duration string from the frontend
     * into something we can use with the API (from parameter).
     *
     * @param string $duration ISO8601 Duration
     * @param DateTime $now current time (used in testing)
     * @return string
     */
    public function parseDuration(\DateTimeImmutable $now, string $duration): string
    {
        try {
            $int = new DateInterval($duration);
        } catch (Exception $e) {
            Logger::error('Failed to parse date interval: %s', $e);
            $int = new DateInterval('PT12H');
        }

        return $now->sub($int)->format('Y-m-d\TH:i:s');
    }

    protected function createQuery(array $params): string
    {
        return Query::build($params);
    }

    protected function extractArgument(array &$params, string $arg): mixed
    {
        if (array_key_exists($arg, $params) === true) {
            $value = $params[$arg];
            $value = (is_object($value) && !is_iterable($value)) ?
                (array) $value :
                $value;
            unset($params[$arg]);
            return $value;
        } else {
            return null;
        }
    }

    /**
     * search runs the provided query against the Search REST API
     */
    public function search(array $params = []): array
    {
        $index = $this->extractArgument($params, 'index');
        $body = $this->extractArgument($params, 'body');

        $query = $this->createQuery($params);

        $uri = isset($index) ? "/$index/_search" : '_search';
        $uri = $uri . '?' . $query;
        $method = isset($body) ? 'POST' : 'GET';

        $body = isset($body) ? Json::encode($body) : null;

        $req = new Request($method, $uri, [], $body);

        $response = $this->transport->sendRequest($req);
        $responseBody = $response->getBody()->getContents();

        if ($response->getStatusCode() !== 200) {
            throw new QueryException('Failed to run query: %s', $responseBody);
        }

        $d = [];
        try {
            $d = Json::decode($responseBody, true);
        } catch (JsonDecodeException $e) {
            throw new QueryException('Failed to decode query response: %s', $e);
        }

        return $d;
    }

    /**
     * query runs the provided query string against the ES|QL REST API
     * with the CSV format.
     * @throws QueryException
     */
    public function query(string $query = ''): Response
    {
        $uri = '_query?format=csv';
        $method = 'POST';

        $body = Json::encode(['query' => $query]);

        $req = new Request($method, $uri, [], $body);

        $response = $this->transport->sendRequest($req, true);

        // We only want the contents if there's an error otherwise we stream the response
        // Not the prettiest solution, but works for now.
        if ($response->getStatusCode() >= 400) {
            $responseBody = $response->getBody()->getContents();
            // We just pack the error response in an exception and the caller needs to handle it
            throw new QueryException('Failed to run query: %s', $responseBody);
        }

        return $response;
    }

    /**
     * status tests connectivity to the Elasticsearch cluster
     * @return array
     */
    public function status(array $auth): array
    {
        $method = $auth['method'] ?? 'none';

        $authOptions = [];

        $authOptions['verify'] = $auth['tlsverify'] ?? true;

        if ($method === 'basic') {
            $authOptions['auth'] = [
                $auth['username'] ?? '',
                $auth['password'] ?? ''
            ];
        }

        if ($method === 'token') {
            $t = $auth['tokentype'] ?? 'Bearer';
            $v = $auth['tokenvalue'] ?? '';
            $authOptions['headers'] = [
                    'Authorization' =>  $t .' '. $v,
            ];
        }

        $mtls = $auth['mtls'] ?? false;

        if ($mtls === false) {
            return $authOptions;
        }

        if ($mtls) {
            $authOptions['cert'] = $auth['mtls_cert'] ?? '';
            $authOptions['ssl_key'] = $auth['mtls_key'] ?? '';
            if (($auth['mtls_ca'] ?? '') !== '') {
                $authOptions['verify'] = $auth['mtls_ca'] ?? '';
            }
        }

        // We're injecting the client-level options here to keep the
        // status check simple. The other clients configure the HTTP client instead
        $req = new Request('GET', '/', $authOptions, null);

        try {
            $response = $this->transport->sendRequest($req);
            return ['output' =>  $response->getBody()->getContents()];
        } catch (ConnectException $e) {
            return ['output' => 'Connection error: ' . $e->getMessage(), 'error' => true];
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return ['output' => 'HTTP error: ' . $e->getResponse()->getStatusCode() . ' - ' .
                                      $e->getResponse()->getReasonPhrase(), 'error' => true];
            } else {
                return ['output' => 'Request error: ' . $e->getMessage(), 'error' => true];
            }
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $e->getMessage(), 'error' => true];
        }

        return ['output' => 'Unknown error', 'error' => true];
    }
}
