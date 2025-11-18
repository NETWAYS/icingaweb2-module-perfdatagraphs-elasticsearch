<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphselasticsearch\Transport\Transport;
use Icinga\Module\Perfdatagraphselasticsearch\Transport\HostPool;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Util\Json;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Query;

use DateInterval;
use DateTime;
use Exception;

/**
 * Elasticsearch handles calling the API and returning the data.
 */
class Elasticsearch
{
    protected $transport = null;

    // TODO: Currently unsed
    protected int $maxDataPoints;

    public function __construct(
        string $urls,
        string $username,
        string $password,
        int $maxDataPoints,
        int $timeout,
        bool $tlsVerify,
    ) {
        $u = explode(',', $urls);

        $HTTPClient = new Client([
            'timeout' => $timeout,
            'verify' => $tlsVerify
        ]);

        $pool = new HostPool($HTTPClient);
        $pool->setHosts($u);
        $transport = new Transport($HTTPClient, $pool);

        if (isset($username)) {
            $transport->setBasicAuth($username, $password = '');
        }

        $this->transport = $transport;

        $this->maxDataPoints = $maxDataPoints;
    }

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
            // TODO: Logger::error
            return [];
        }

        return $d;
    }

    protected function normalizeCheckcommand(string $name)
    {
        // TODO: Needs the same normalization is the Writer uses
        return $name;
    }

    /**
     * request calls the Opensearch HTTP API, decodes and returns the data.
     *
     * @param string $hostName host name for the performance data query
     * @param string $serviceName service name for the performance data query
     * @param string $checkCommand checkcommand name for the performance data query
     * @param string $from specifies the beginning for which to fetch the data
     * @param bool $isHostCheck is this a hostcheck, so that we can modify the query
     * @param array $includeMetrics metrics that should included
     * @param array $excludeMetrics metrics that are excluded
     * @return PerfdataResponse
     */
    public function fetchMetrics(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics,
    ): PerfdataResponse {
        $params = [
            'size' => 2000,
            'sort' => '@timestamp:asc',
            'body' => [
                // Still might cause an out-out-memory, but a lower value means more HTTP requests to the API
                'query' => [
                    'bool' => [
                        'must' => [
                            [ 'match' => [ 'service.name' => $hostName .'!'. $serviceName ] ],
                        ],
                        'filter' => [
                            'range' => [ '@timestamp' => [ 'gte' => $from, 'lte' => 'now', ] ]
                        ],
                    ]
                ]
            ]
        ];

        // If it's a hostalive check we dont need the service term
        if ($isHostCheck) {
            $params['body']['query']['bool']['must'] = [
                [ 'term' => [ 'host.name' => $hostName ] ],
            ];
        }

        $pfr = new PerfdataResponse();

        $searchAfter = null;

        // Where we store the data for each page of hits.
        $timestamps = [];
        $values = [];
        $warnings = [];
        $criticals = [];
        $units = [];

        $params['index'] = 'metrics-icinga2.' . $this->normalizeCheckcommand($checkCommand) . '-default';

        $response = $this->search($params);

        if (array_key_exists('error', $response)) {
            $pfr->addError($response['error']);
            return $pfr;
        }

        do {
            if ($searchAfter !== null) {
                // Set the search_after to get the next page of docs
                $params['body']['search_after'] = [$searchAfter];
            }

            // TODO: Should check if the response has these keys
            $hits = $response['hits']['hits'] ?? [];

            foreach ($hits as $doc) {
                $perfdata = $doc['_source']['perfdata'] ?? [];

                foreach ($perfdata as $label => $metric) {
                    if (!$this->isIncluded($label, $includeMetrics)) {
                        continue;
                    }

                    if ($this->isExcluded($label, $excludeMetrics)) {
                        continue;
                    }

                    if (!isset($values[$label])) {
                        $values[$label] = [];
                    }

                    $values[$label][] = $metric['value'] ?? null;
                    $date = new DateTime($doc['_source']['@timestamp']);
                    $timestamps[$label][] = $date->getTimestamp();
                    $warnings[$label][] = $metric['warn'] ?? null;
                    $criticals[$label][] = $metric['crit'] ?? null;
                    $units[$label][] = $metric['unit'] ?? '';
                }
            }

            $hitCount = count($hits);
            $searchAfter = end($hits)['sort'][0] ?? null;

            unset($response);
            unset($hits);
        } while ($hitCount > 0);

        // Add it to the PerfdataResponse
        // TODO: we can optize that by doing it in the while loop
        foreach (array_keys($values) as $label) {
            $u = '';
            if (array_key_exists($label, $units)) {
                $u = end($units[$label]);
            }

            $s = new PerfdataSet($label, $u);
            $s->setTimestamps($timestamps[$label]);

            if (array_key_exists($label, $values)) {
                $vSeries = new PerfdataSeries('value', $values[$label]);
                $s->addSeries($vSeries);
            }

            if (array_key_exists($label, $warnings) && !empty($warnings)) {
                $wSeries = new PerfdataSeries('warning', $warnings[$label]);
                $s->addSeries($wSeries);
            }

            if (array_key_exists($label, $criticals) && !empty($criticals)) {
                $cSeries = new PerfdataSeries('critical', $criticals[$label]);
                $s->addSeries($cSeries);
            }

            $pfr->addDataset($s);
        }

        return $pfr;
    }

    /**
     * @return array
     */
    public function status(): array
    {
        return ['output' => 'not yet implemented', 'error' => false];
    }


    /**
     * parseDuration parses the duration string from the frontend
     * into something we can use with the API (from parameter).
     *
     * @param string $duration ISO8601 Duration
     * @param string $now current time (used in testing)
     * @return string
     */
    public static function parseDuration(\DateTime $now, string $duration): string
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
     * fromConfig returns a new Elasticsearch Client from this module's configuration
     *
     * @param Config $moduleConfig configuration to load (used for testing)
     * @return $this
     */
    public static function fromConfig(Config $moduleConfig = null): Elasticsearch
    {
        $default = [
            'api_url' => 'http://localhost:9200',
            'api_timeout' => 10,
            'api_max_data_points' => 10000,
            'api_username' => '',
            'api_password' => '',
            'api_tls_insecure' => false,
        ];

        // Try to load the configuration
        if ($moduleConfig === null) {
            try {
                Logger::debug('Loaded Perfdata Graphs Elasticsearch module configuration to get Config');
                $moduleConfig = Config::module('perfdatagraphselasticsearch');
            } catch (Exception $e) {
                Logger::error('Failed to load Perfdata Graphs Elasticsearch module configuration: %s', $e);
                return $default;
            }
        }

        $baseURI = rtrim($moduleConfig->get('elasticsearch', 'api_url', $default['api_url']), '/');
        $timeout = (int) $moduleConfig->get('elasticsearch', 'api_timeout', $default['api_timeout']);
        $maxDataPoints = (int) $moduleConfig->get('elasticsearch', 'api_max_data_points', $default['api_max_data_points']);
        $username = $moduleConfig->get('elasticsearch', 'api_username', $default['api_username']);
        $password = $moduleConfig->get('elasticsearch', 'api_password', $default['api_password']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('elasticsearch', 'api_tls_insecure', $default['api_tls_insecure']);

        return new static($baseURI, $username, $password, $maxDataPoints, $timeout, $tlsVerify);
    }
}
