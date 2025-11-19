<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphselasticsearch\Transport\Transport;
use Icinga\Module\Perfdatagraphselasticsearch\Transport\HostPool;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use Icinga\Application\Config;
use Icinga\Application\Logger;

use GuzzleHttp\Client;
use Exception;
use DateTime;

/**
 * ElasticsearchDatastreamClient is used with with Icinga2 ElasticsearchDatastreamWriter
 */
class ElasticsearchDatastreamClient extends BaseClient implements ESInterface
{
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

    /**
     * fromConfig returns a new Client from this module's configuration
     *
     * @param Config $moduleConfig configuration to load (used for testing)
     * @return $this
     */
    public static function fromConfig(Config $moduleConfig = null): ESInterface
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

    /**
     * normalizeCheckcommand mimic the ElasticsearchDatastreamWriter's normalization.
     * Any leading whitespace and leading special characters are trimmed;
     * All remaining special (non-alphanumeric) characters are replaced with an underscore;
     * Consecutive underscores are collapsed;
     * Leading/trailing underscores are removed;
     */
    protected function normalizeCheckcommand(string $n)
    {
        $n = preg_replace('/^[\s\W_]+/u', '', $n);
        $n = preg_replace('/[^A-Za-z0-9]+/u', '_', $n);
        $n = preg_replace('/_+/', '_', $n);
        $n = trim($n, '_');
        $n = mb_strtolower($n, 'UTF-8');

        return $n;
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

        do {
            if ($searchAfter !== null) {
                // Set the search_after to get the next page of docs
                $params['body']['search_after'] = [$searchAfter];
            }

            $response = $this->search($params);

            if (array_key_exists('error', $response)) {
                $pfr->addError($response['error']);
                return $pfr;
            }

            $hits = [];

            if (array_key_exists('hits', $response)) {
                $hits = $response['hits']['hits'] ?? [];
            }

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
}
