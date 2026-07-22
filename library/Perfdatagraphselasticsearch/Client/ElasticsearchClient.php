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

use DateTimeImmutable;
use Exception;
use GuzzleHttp\Client;

/**
 * ElasticsearchClient is used with with Icinga2 ElasticsearchWriter
 *
 * Note that the Icinga2 ElasticsearchWriter is deprecated
 */
class ElasticsearchClient extends BaseClient implements ESInterface
{
    protected readonly string $index;

    public function __construct(
        string $urls,
        int $timeout,
        bool $tlsVerify,
        string $index = 'icinga2',
        array $auth = [],
    ) {
        $u = explode(',', $urls);

        $clientConf = [
            'timeout' => $timeout,
            'verify' => $tlsVerify,
        ];

        $mtls = $auth['mtls'] ?? false;
        if ($mtls) {
            $clientConf['cert'] = $auth['mtls_cert'] ?? '';
            $clientConf['ssl_key'] = $auth['mtls_key'] ?? '';
            if (($auth['mtls_ca'] ?? '') !== '') {
                $clientConf['verify'] = $auth['mtls_ca'] ?? '';
            }
        }

        $HTTPClient = new Client($clientConf);

        $pool = new HostPool($HTTPClient);
        $pool->setHosts($u);
        $transport = new Transport($HTTPClient, $pool);

        $method = $auth['method'] ?? '';
        if ($method === 'basic') {
            $transport->setBasicAuth($auth['username'] ?? '', $auth['password'] ?? '');
        }

        if ($method === 'token') {
            $transport->setHeader($auth['tokentype'] ?? 'Bearer', $auth['tokenvalue'] ?? '');
        }

        $this->index = $index;
        $this->transport = $transport;
    }

    /**
     * fromConfig returns a new Elasticsearch Client from this module's configuration
     *
     * @param Config $moduleConfig configuration to load (used for testing)
     * @return $this
     */
    public static function fromConfig(?Config $moduleConfig = null): ESInterface
    {
        $default = [
            'api_url' => 'http://localhost:9200',
            'api_index' => 'icinga2',
            'api_timeout' => 10,
            'api_auth_method' => 'none',
            'api_auth_tokentype' => 'Bearer',
            'api_auth_tokenvalue' => '',
            'api_auth_username' => '',
            'api_auth_password' => '',
            'api_auth_mtls' => false,
            'api_auth_mtls_cert' => '',
            'api_auth_mtls_key' => '',
            'api_auth_mtls_ca' => '',
            'api_tls_insecure' => false,
        ];

        // Try to load the configuration
        if ($moduleConfig === null) {
            try {
                Logger::debug('Loaded Perfdata Graphs Elasticsearch module configuration to get Config');
                $moduleConfig = Config::module('perfdatagraphselasticsearch');
            } catch (Exception $e) {
                Logger::error('Failed to load Perfdata Graphs Elasticsearch module configuration: %s', $e);
                return new static(
                    urls: $default['api_url'],
                    timeout: 10,
                    tlsVerify: true,
                    index: 'icinga2',
                    auth: []
                );
            }
        }

        $baseURI = rtrim($moduleConfig->get('elasticsearch', 'api_url', $default['api_url']), '/');
        $index = $moduleConfig->get('elasticsearch', 'api_index', $default['api_index']);
        $timeout = (int) $moduleConfig->get('elasticsearch', 'api_timeout', $default['api_timeout']);

        // Auth values
        $authMethod = $moduleConfig->get('elasticsearch', 'api_auth_method', $default['api_auth_method']);
        $authTokenType = $moduleConfig->get('elasticsearch', 'api_auth_tokentype', $default['api_auth_tokentype']);
        $authTokenValue = $moduleConfig->get('elasticsearch', 'api_auth_tokenvalue', $default['api_auth_tokenvalue']);
        $authUsername = $moduleConfig->get('elasticsearch', 'api_auth_username', $default['api_auth_username']);
        $authPassword = $moduleConfig->get('elasticsearch', 'api_auth_password', $default['api_auth_password']);
        // mTLS values
        $authMTLS = $moduleConfig->get('elasticsearch', 'api_auth_mtls', $default['api_auth_mtls']);
        $authMTLSCert = $moduleConfig->get('elasticsearch', 'api_auth_mtls_cert', $default['api_auth_mtls_cert']);
        $authMTLSKey = $moduleConfig->get('elasticsearch', 'api_auth_mtls_key', $default['api_auth_mtls_key']);
        $authMTLSCA = $moduleConfig->get('elasticsearch', 'api_auth_mtls_ca', $default['api_auth_mtls_ca']);

        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('elasticsearch', 'api_tls_insecure', $default['api_tls_insecure']);

        $auth = [
            'method' => strtolower($authMethod),
            'tokentype' => $authTokenType,
            'tokenvalue' => $authTokenValue,
            'username' => $authUsername,
            'password' => $authPassword,
            'mtls' => $authMTLS,
            'mtls_cert' => $authMTLSCert,
            'mtls_key' => $authMTLSKey,
            'mtls_ca' => $authMTLSCA,
        ];

        return new static(
            urls: $baseURI,
            timeout: $timeout,
            tlsVerify: $tlsVerify,
            index: $index,
            auth: $auth
        );
    }

    protected function getDatasetKeys(array $fields): array
    {
        // There can be multiple datasets (pl, rta, etc) in a document,
        // we need to get their keys to fetch the values
        // "@timestamp": ["1751293383.713"],
        // "check_result.perfdata./.unit.keyword": ["bytes"],
        // "check_result.perfdata./.value": [14774000000],
        // "check_result.perfdata./.warn": [80176000000],
        // "check_result.perfdata./.unit": ["bytes"],
        // "check_result.perfdata./.crit": [90198000000]
        $keys = preg_grep('/check_result\.perfdata.*\.value/', array_keys($fields));
        return $keys;
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
        int $checkInterval = 0
    ): PerfdataResponse {
        $now = new DateTimeImmutable();
        $parsedFrom = $this->parseDuration($now, $from);

        $params = [
            'size' => 2000,
            'sort' => '@timestamp:asc',
            'body' => [
                '_source' => false,
                'fields' => [
                    'check_result.perfdata.*',
                    ['field' => '@timestamp', 'format' => 'epoch_second' ]
                ],
                'query' => [
                    'bool' => [
                        'must' => [
                            [ 'term' => [ 'host.keyword' => $hostName ] ],
                            [ 'term' => [ 'service.keyword' => $serviceName ] ],
                            [ 'term' => [ 'check_command.keyword' => $checkCommand ] ]
                        ],
                        'filter' => [
                            'range' => [ 'timestamp' => [ 'gte' => $parsedFrom, 'lte' => 'now', ] ]
                        ],
                    ]
                ]
            ]
        ];

        // If it's a hostalive check we dont need the service term
        if ($isHostCheck) {
            $params['body']['query']['bool']['must'] = [
                [ 'term' => [ 'host.keyword' => $hostName ] ],
                [ 'term' => [ 'check_command.keyword' => $checkCommand ] ]
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
                $doc = $d['fields'];
                $keys = $this->getDatasetKeys($doc);

                foreach ($keys as $valueKey) {
                    $metricname = preg_replace('/\.value$/', '', str_replace('check_result.perfdata.', '', $valueKey));

                    if (!Transformer::isIncluded($metricname, $includeMetrics)) {
                        continue;
                    }

                    if (Transformer::isExcluded($metricname, $excludeMetrics)) {
                        continue;
                    }

                    $unitKey = preg_replace('/\.value$/', '.unit', $valueKey);
                    $warnKey = preg_replace('/\.value$/', '.warn', $valueKey);
                    $critKey = preg_replace('/\.value$/', '.crit', $valueKey);

                    $values[$metricname] ??= [];
                    $warnings[$metricname] ??= [];
                    $criticals[$metricname] ??= [];

                    if (array_key_exists($unitKey, $doc)) {
                        $units[$metricname][] = $doc[$unitKey][0] ?? '';
                    }

                    $timestamps[$metricname][] = (int) ($doc['@timestamp'][0] ?? 0);
                    $values[$metricname][] = $doc[$valueKey][0] ?? null;
                    $warnings[$metricname][] = $doc[$warnKey][0] ?? null;
                    $criticals[$metricname][] = $doc[$critKey][0] ?? null;
                }
            }

            $hitCount = count($hits);
            // Note, can change this to array_last in the future
            $searchAfter = end($hits)['sort'][0] ?? null;

            unset($response);
            unset($hits);
        } while ($hitCount > 0);

        $seriesMap = [
            'value' => $values,
            'warning' => $warnings,
            'critical' => $criticals,
        ];

        // Add it to the PerfdataResponse
        foreach (array_keys($values) as $metric) {
            $u = '';
            if (array_key_exists($metric, $units)) {
                // Note, can change this to array_last in the future
                $u = end($units[$metric]);
            }

            $s = new PerfdataSet($metric, $u);

            $s->setTimestamps($timestamps[$metric]);

            // Add the actual series to the response
            foreach ($seriesMap as $label => $data) {
                if (!array_key_exists($metric, $data)) {
                    continue;
                }
                $series = $data[$metric];

                // Check if the series contains only null
                $hasValues = false;
                foreach ($series as $v) {
                    if ($v !== null) {
                        $hasValues = true;
                        break;
                    }
                }
                if (!$hasValues) {
                    continue;
                }

                $s->addSeries(new PerfdataSeries($label, $series));
            }

            $pfr->addDataset($s);
        }

        return $pfr;
    }
}
