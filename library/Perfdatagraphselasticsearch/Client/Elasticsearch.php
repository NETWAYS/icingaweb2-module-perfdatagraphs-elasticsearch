<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use Icinga\Application\Config;
use Icinga\Application\Logger;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateInterval;
use DateTime;
use Exception;

/**
 * Elasticsearch handles calling the API and returning the data.
 */
class Elasticsearch
{
    protected $client = null;

    protected string $index;

    public function __construct(
        string $urls,
        string $index,
        string $username,
        string $password,
        int $timeout,
        bool $tlsVerify,
    ) {
        // TODO: We need a custom Client that can handle multiple hosts
        // $u = explode(',', $urls);

        $this->client = new Client([
            'base_uri' => $urls,
            'timeout' => $timeout,
            'auth' => [$username, $password],
            'verify' => $tlsVerify
        ]);
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
    public function search(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics,
    ): PerfdataResponse {
        $query = [
            'body' => [
                // Still might cause an out-out-memory, but a lower value means more HTTP requests to the API
                'size' => 2000,
                'sort' => [
                    ['@timestamp' => [ 'order' => 'asc' ]]
                ],
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
            $query['body']['query']['bool']['must'] = [
                            [ 'term' => [ 'host.name' => $hostName ] ],
            ];
        }

        $searchAfter = null;

        // Where we store the data for each page of hits.
        $timestamps = [];
        $values = [];
        $warnings = [];
        $criticals = [];
        $units = [];

        $indexName = '/metrics-icinga2.' . $checkCommand . '-default/_search';

        do {
            if ($searchAfter !== null) {
                $query['body']['search_after'] = [$searchAfter];
            }

            $resp = $this->client->request('POST', $indexName, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $query['body']
            ]);

            $response = json_decode($resp->getBody(), true);

            $hits = $response['hits']['hits'];

            foreach ($hits as $d) {
                $perfdata = $d['_source']['perfdata'];

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

                    $values[$label][] = $metric['value'];

                    $date = new DateTime($d['_source']['@timestamp']);
                    $timestamps[$label][] = $date->getTimestamp();

                    if (array_key_exists('unit', $metric)) {
                        $units[$label][] = $metric['unit'];
                    }

                    if (array_key_exists('warn', $metric)) {
                        $warnings[$label][] = $metric['warn'];
                    }

                    if (array_key_exists('crit', $metric)) {
                        $criticals[$label][] = $metric['crit'];
                    }
                }
            }

            $hitCount = count($hits);
            $searchAfter = end($hits)['sort'][0] ?? null;

            // unset to save some memory
            unset($response);
            unset($hits);
        } while ($hitCount > 0);

        $pfr = new PerfdataResponse();

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
            'api_index' => 'icinga2',
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
        $index = $moduleConfig->get('elasticsearch', 'api_index', $default['api_index']);
        $username = $moduleConfig->get('elasticsearch', 'api_username', $default['api_username']);
        $password = $moduleConfig->get('elasticsearch', 'api_password', $default['api_password']);
        $tlsVerify = (bool) $moduleConfig->get('elasticsearch', 'api_tls_insecure', $default['api_tls_insecure']);

        return new static($baseURI, $index, $username, $password, $timeout, $tlsVerify);
    }
}
