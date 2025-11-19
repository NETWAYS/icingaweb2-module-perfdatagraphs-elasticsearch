<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphselasticsearch\Transport\Transport;

use Icinga\Application\Logger;
use Icinga\Util\Json;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateInterval;
use DateTime;
use Exception;

/**
 * BaseClient
 */
abstract class BaseClient
{
    protected Transport $transport;

    // TODO: Currently unused
    protected int $maxDataPoints;

    /**
     * parseDuration parses the duration string from the frontend
     * into something we can use with the API (from parameter).
     *
     * @param string $duration ISO8601 Duration
     * @param string $now current time (used in testing)
     * @return string
     */
    public function parseDuration(\DateTime $now, string $duration): string
    {
        try {
            $int = new DateInterval($duration);
        } catch (Exception $e) {
            Logger::error('Failed to parse date interval: %s', $e);
            $int = new DateInterval('PT12H');
        }

        $now->sub($int);
        return $now->format('Y-m-d\TH:i:s');
    }

    /**
     * isIncluded checks if the given metric should be included in the response
     *
     * @param string $metricname
     * @param array $includeMetrics
     * @return bool
     */
    protected function isIncluded($metricname, array $includeMetrics = []): bool
    {
        // All are included if not set
        if (count($includeMetrics) === 0) {
            return true;
        }
        foreach ($includeMetrics as $pattern) {
            if (fnmatch($pattern, $metricname)) {
                return true;
            }
        }
        return false;
    }

    /**
     * isExcluded checks if the given metric should be excluded from the response
     *
     * @param string $metricname
     * @param array $excludeMetrics
     * @return bool
     */
    protected function isExcluded($metricname, array $excludeMetrics = []): bool
    {
        // None are exlucded if not set
        if (count($excludeMetrics) === 0) {
            return false;
        }

        foreach ($excludeMetrics as $pattern) {
            if (fnmatch($pattern, $metricname)) {
                return true;
            }
        }
        return false;
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

    public function search(array $params = [])
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

        try {
            $d = Json::decode($responseBody, true);
        } catch (JsonDecodeException $e) {
            Logger::error('Failed to decode response: %s', $e);
            return [];
        }

        return $d;
    }

    /**
     * status tests connectivity to the Elasticsearch cluster
     * @return array
     */
    public function status(): array
    {
        $req = new Request('GET', '/', [], null);

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
