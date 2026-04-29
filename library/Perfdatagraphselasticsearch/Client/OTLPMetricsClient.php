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

use DateInterval;
use DateTimeImmutable;
use DateTime;
use Exception;
use GuzzleHttp\Client;

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
        $username = $moduleConfig->get('elasticsearch', 'api_username', $default['api_username']);
        $password = $moduleConfig->get('elasticsearch', 'api_password', $default['api_password']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('elasticsearch', 'api_tls_insecure', $default['api_tls_insecure']);
        $maxDataPoints = (int) $moduleConfig->get('elasticsearch', 'api_max_data_points', $default['api_max_data_points']);

        return new static($baseURI, $username, $password, $maxDataPoints, $timeout, $tlsVerify, $index);
    }

    /**
     * calculateSteps uses the start and end timestamps to calculate the step parameter
     */
    protected function calculateSteps(int $start, int $end, int $maxDataPoints): string
    {
        $totalSeconds = $end - $start;
        $stepSeconds = $totalSeconds / $maxDataPoints;
        // NOTE: This means we can never get a resolution below 60s, even if Icinga2 would send data every 15s
        $stepSeconds = max($stepSeconds, 60);

        return (int)round($stepSeconds) . 's';
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
        $endTime = new DateTimeImmutable();
        $startTime = $endTime->sub(new DateInterval($from));
        $start = $startTime->getTimestamp();
        $end = $endTime->getTimestamp();
        $step = $this->calculateSteps($start, $end, $this->maxDataPoints);

        $now = new DateTime();
        $from = $this->parseDuration($now, $from);

        $params = [
            'size' => 0,
            'sort' => '@timestamp:asc',
            'body' => [
                '_source' => 'false',
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
                ],
                'aggs' => [
                    'by_time_window' => [
                        'date_histogram' => [
                            'field' => '@timestamp',
                            'fixed_interval' => $step,
                            'format' => 'epoch_millis'
                        ],
                        'aggs' => [
                            'by_perfdata_label' => [
                                'terms' => [
                                    'field' => 'attributes.perfdata_label',
                                    'size' => 10
                                ],
                                'aggs' => [
                                    'by_threshold_type' => [
                                        'terms' => [
                                            'field' => 'attributes.threshold_type',
                                            'size' => 10,
                                            'missing' => 'VALUE'
                                        ],
                                        'aggs' => [
                                            'avg_threshold' => [
                                                'avg' => [ 'field' => 'metrics.state_check.threshold' ]
                                            ],
                                            'avg_perfdata' => [
                                                'avg' => [ 'field' => 'metrics.state_check.perfdata' ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
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

        $pfr = new PerfdataResponse();

        $response = $this->search($params);

        if (array_key_exists('error', $response)) {
            $pfr->addError(Json::encode($response['error']));
            return $pfr;
        }

        $buckets = [];
        if (array_key_exists('aggregations', $response)) {
            $buckets = $response['aggregations']['by_time_window']['buckets'] ?? [];
        }

        // We need to add the timestamps later, thus we store them here
        $timestamps = [];

        foreach ($buckets as $b) {
            $timestamps[] = $b['key'] / 1000;
            $labelbuckets = $b['by_perfdata_label']['buckets'];

            foreach ($labelbuckets as $lbucket) {
                $label = $lbucket['key'];

                if (!$this->isIncluded($label, $includeMetrics)) {
                    continue;
                }
                if ($this->isExcluded($label, $excludeMetrics)) {
                    continue;
                }

                $dataset = $pfr->getDataset($label);
                // No, then create a new one
                if (empty($dataset)) {
                    $dataset = new PerfdataSet($label, '');
                    $pfr->addDataset($dataset);
                }

                $series = $dataset->getSeries();

                // Do we have a value series already?
                if (array_key_exists('value', $series)) {
                    $values = $series['value'];
                } else {
                    $values = new PerfdataSeries('value');
                    $dataset->addSeries($values);
                }
                // Do we have a warn series already?
                if (array_key_exists('warning', $series)) {
                    $warns = $series['warning'];
                } else {
                    $warns = new PerfdataSeries('warning');
                    $dataset->addSeries($warns);
                }
                // Do we have a crit series already?
                if (array_key_exists('critical', $series)) {
                    $crits = $series['critical'];
                } else {
                    $crits = new PerfdataSeries('critical');
                    $dataset->addSeries($crits);
                }

                $valbuckets = $lbucket['by_threshold_type']['buckets'];
                // Get each value and add it, we need to add nulls when there's nothing
                $wValue = $cValue = $vValue = null;
                foreach ($valbuckets as $v) {
                    if ($v['key'] === 'VALUE') {
                        $vValue = $v['avg_perfdata']['value'] ?? null;
                    }
                    if ($v['key'] === 'warning') {
                        $wValue = $v['avg_threshold']['value'] ?? null;
                    }
                    if ($v['key'] === 'critical') {
                        $cValue = $v['avg_threshold']['value'] ?? null;
                    }
                }
                $values->addValue($vValue);
                $crits->addValue($cValue);
                $warns->addValue($wValue);
            }
        }

        // Remove the empty series from the datasets
        $ds = $pfr->getDatasets();
        foreach ($ds as $dataset) {
            $dataset->setTimestamps($timestamps);
            $series = $dataset->getSeries();
            foreach ($series as $ser) {
                if ($ser->isEmpty()) {
                    $dataset->removeSeries($ser->getName());
                }
            }
        }

        return $pfr;
    }
}
