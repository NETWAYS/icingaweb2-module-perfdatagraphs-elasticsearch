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
use Exception;

/**
 * OTLPMetricsClient is used with with Icinga2 ElasticsearchWriter
 */
class OTLPMetricsClient extends BaseClient implements ESInterface
{
    protected string $index;

    public function __construct(
        string $urls,
        string $username,
        string $password,
        int $maxDataPoints,
        int $timeout,
        bool $tlsVerify,
        string $index = 'icinga2'
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
            $transport->setBasicAuth($username, $password);
        }

        $this->transport = $transport;
        $this->maxDataPoints = $maxDataPoints;
    }

    /**
     * fromConfig returns a new Elasticsearch Client from this module's configuration
     *
     * @param Config $moduleConfig configuration to load (used for testing)
     * @return $this
     */
    public static function fromConfig(Config $moduleConfig = null): ESInterface
    {
        $default = [
            'api_url' => 'http://localhost:9200',
            'api_index' => 'icinga2',
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
        $index = rtrim($moduleConfig->get('elasticsearch', 'api_index', $default['api_index']), 'icinga2');
        $timeout = (int) $moduleConfig->get('elasticsearch', 'api_timeout', $default['api_timeout']);
        $maxDataPoints = (int) $moduleConfig->get('elasticsearch', 'api_max_data_points', $default['api_max_data_points']);
        $username = $moduleConfig->get('elasticsearch', 'api_username', $default['api_username']);
        $password = $moduleConfig->get('elasticsearch', 'api_password', $default['api_password']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('elasticsearch', 'api_tls_insecure', $default['api_tls_insecure']);

        return new static($baseURI, $username, $password, $maxDataPoints, $timeout, $tlsVerify, $index);
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
                'fields' => [
                    ['field' => '@timestamp', 'format' => 'epoch_second' ]
                ],
                'query' => [
                    'bool' => [
                        'must' => [
                            [ 'match' => [ 'resource.attributes.icinga2.host.name' => $hostName ] ],
                            [ 'match' => [ 'resource.attributes.icinga2.service.name' => $serviceName ] ],
                            [ 'match' => [ 'resource.attributes.icinga2.command.name' => $checkCommand ] ]
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
                [ 'term' => [ 'resource.attributes.icinga2.host.name' => $hostName ] ],
                [ 'term' => [ 'resource.attributes.icinga2.command.name' => $checkCommand ] ]
            ];
        }

        $searchAfter = null;

        $pfr = new PerfdataResponse();

        // Where we store the data for each page of hits.
        $timestamps = [];
        $values = [];
        $warnings = [];
        $criticals = [];
        $units = [];

        do {
            if ($searchAfter !== null) {
                $params['body']['search_after'] = [$searchAfter];
            }

            $response = $this->search($params);

            if (array_key_exists('error', $response)) {
                $pfr->addError(Json::encode($response['error']));
                return $pfr;
            }

            $hits = [];

            if (array_key_exists('hits', $response)) {
                $hits = $response['hits']['hits'] ?? [];
            }

            foreach ($hits as $d) {
                $doc = $d['_source'];
                $label = $doc['attributes']['perfdata_label'];

                if (!$this->isIncluded($label, $includeMetrics)) {
                    continue;
                }

                if ($this->isExcluded($label, $excludeMetrics)) {
                    continue;
                }

                if (!isset($values[$label])) {
                    $values[$label] = [];
                }

                if (!isset($warnings[$label])) {
                    $warnings[$label] = [];
                }

                if (!isset($criticals[$label])) {
                    $criticals[$label] = [];
                }
                $timestamps[$label][] = (int) end($d['fields']['@timestamp']);

                if (array_key_exists('threshold_type', $doc['attributes'])) {
                    if ($doc['attributes']['threshold_type'] === 'warning') {
                        $warnings[$label][] = $doc['metrics']['state_check.threshold'];
                    }
                    if ($doc['attributes']['threshold_type'] === 'critical') {
                        $criticals[$label][] = $doc['metrics']['state_check.threshold'];
                    }
                } else {
                    $warnings[$label][] = null;
                    $criticals[$label][] = null;
                }

                if (array_key_exists('state_check.perfdata', $doc['metrics'])) {
                    $values[$label][] = $doc['metrics']['state_check.perfdata'];
                } else {
                    $values[$label][] = null;
                }
            }

            $hitCount = count($hits);
            $searchAfter = end($hits)['sort'][0] ?? null;

            unset($response);
            unset($hits);
        } while ($hitCount > 0);

        // Add it to the PerfdataResponse
        foreach (array_keys($values) as $metric) {
            $u = '';
            // if (array_key_exists($metric, $units)) {
            //     $u = end($units[$metric]);
            // }

            $uniqueTimestamps[$metric] = array_unique($timestamps[$metric]);
            $s = new PerfdataSet($metric, $u);
            $s->setTimestamps($uniqueTimestamps[$metric]);

            if (array_key_exists($metric, $values)) {
                $values[$metric] = array_filter($values[$metric], fn($v) => $v !== null);
                if (!empty($values[$metric])) {
                    $v = new PerfdataSeries('value', $values[$metric]);
                    $s->addSeries($v);
                }
            }

            if (array_key_exists($metric, $warnings) && !empty($warnings[$metric])) {
                $warnings[$metric] = array_filter($warnings[$metric], fn($v) => $v !== null);
                if (!empty($warnings[$metric])) {
                    $w = new PerfdataSeries('warning', $warnings[$metric]);
                    $s->addSeries($w);
                }
            }

            if (array_key_exists($metric, $criticals) && !empty($criticals[$metric])) {
                $criticals[$metric] = array_filter($criticals[$metric], fn($v) => $v !== null);
                if (!empty($criticals[$metric])) {
                    $c = new PerfdataSeries('critical', $criticals[$metric]);
                    $s->addSeries($c);
                }
            }

            $pfr->addDataset($s);
        }

        return $pfr;
    }
}
