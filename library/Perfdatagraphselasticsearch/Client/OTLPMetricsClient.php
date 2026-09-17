<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphselasticsearch\Transport\Transport;
use Icinga\Module\Perfdatagraphselasticsearch\Transport\HostPool;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Exception\QueryException;
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
    protected readonly string $index;

    protected readonly bool $useTsAggregation;

    public function __construct(
        string $urls,
        int $maxDataPoints,
        int $timeout,
        bool $tlsVerify,
        bool $tsAggregation = true,
        string $index = '.ds-metrics-generic.otel-default-*',
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
        $this->maxDataPoints = $maxDataPoints;
        $this->useTsAggregation = $tsAggregation;
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
            'api_max_data_points' => 10000,
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
            'api_timeseries_aggregation' => true,
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
                    maxDataPoints: 1000,
                    timeout: 10,
                    tlsVerify: tue,
                    tsAggregation: true,
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
        $maxDataPoints = (int) $moduleConfig->get('elasticsearch', 'api_max_data_points', $default['api_max_data_points']);
        $tsAggregation = (bool) $moduleConfig->get('elasticsearch', 'api_timeseries_aggregation', $default['api_timeseries_aggregation']);

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
            maxDataPoints: $maxDataPoints,
            timeout: $timeout,
            tlsVerify: $tlsVerify,
            tsAggregation: $tsAggregation,
            index: $index,
            auth: $auth
        );
    }

    /**
     * calculateSteps uses the start and end timestamps to calculate the step parameter
     */
    protected function calculateSteps(int $start, int $end, int $maxDataPoints, int $checkInterval = 0): int
    {
        $totalSeconds = $end - $start;

        // Ensure we don't divide by zero
        if ($maxDataPoints < 1) {
            Logger::warning('Perfdatagraphs Elasticsearch maxDataPoints is set too small. Review the module configuration');
            $maxDataPoints = 1;
        }

        $stepSeconds = $totalSeconds / $maxDataPoints;
        // Use the check interval as the minimum step so we don't over-sample.
        // Fall back to 1s when no check interval is available.
        $minStep = $checkInterval > 0 ? $checkInterval : 1;
        $stepSeconds = max($stepSeconds, $minStep);

        return (int)ceil($stepSeconds);
    }

    /**
     * buildQueryWithAggregation generates the ES|QL TS query to fetch the data with TBUCKET aggregation
     */
    protected function buildQueryWithAggregation(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
        int $step,
    ): string {
        // The index for the query
        $query = sprintf("TS %s", $this->index);

        // The service or host filter
        if (!$isHostCheck) {
            $query .= sprintf(
                "| WHERE resource.attributes.icinga2.host.name == \"%s\""
                    . " AND resource.attributes.icinga2.service.name == \"%s\""
                    . " AND resource.attributes.icinga2.command.name == \"%s\"",
                $hostName,
                $serviceName,
                $checkCommand,
            );
        } else {
            $query .= sprintf(
                "| WHERE resource.attributes.icinga2.host.name == \"%s\""
                    . " AND resource.attributes.icinga2.command.name == \"%s\"",
                $hostName,
                $checkCommand,
            );
        }

        $query .= sprintf(" AND @timestamp >= TO_DATETIME(\"%s\") AND @timestamp <= NOW()", $from);

        // The aggregated values we want
        $query .= sprintf(
            " | STATS metrics.state_check.threshold_avg = AVG(AVG_OVER_TIME(metrics.state_check.threshold)),"
                . "metrics.state_check.perfdata_avg = AVG(AVG_OVER_TIME(metrics.state_check.perfdata)) "
                . "BY attributes.perfdata_label, attributes.threshold_type, attributes.unit, bucket = TBUCKET(%s seconds)",
            $step,
        );

        // Sort and transforming the bucket timestamp to seconds. Note that, the KEEP order matters for the parser
        // Hint: ESQL uses an implicit LIMIT 1000 if nothing is set. As of ES9.3 the ESQL does not support pagination yet.
        // https://github.com/elastic/elasticsearch/issues/100000
        // I think the upper limit for TS aggregations is 10,000,000 - If I understand this correctly:
        // https://www.elastic.co/docs/reference/query-languages/esql/limitations#esql-max-rows
        $query .= " | LIMIT 1000000 | EVAL epoch_seconds = TO_LONG(bucket) / 1000 "
            . " | KEEP epoch_seconds, metrics.state_check.threshold_avg, metrics.state_check.perfdata_avg, attributes.perfdata_label, attributes.threshold_type, attributes.unit"
            . " | SORT epoch_seconds ASC, attributes.perfdata_label, attributes.unit DESC";

        return $query;
    }

    /**
     * buildQuery generates the ES|QL TS query to fetch the data
     */
    protected function buildQuery(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
    ): string {
        // The index for the query
        $query = sprintf("TS %s", $this->index);

        // The service or host filter
        if (!$isHostCheck) {
            $query .= sprintf(
                "| WHERE resource.attributes.icinga2.host.name == \"%s\""
                    . " AND resource.attributes.icinga2.service.name == \"%s\""
                    . " AND resource.attributes.icinga2.command.name == \"%s\"",
                $hostName,
                $serviceName,
                $checkCommand,
            );
        } else {
            $query .= sprintf(
                "| WHERE resource.attributes.icinga2.host.name == \"%s\""
                    . " AND resource.attributes.icinga2.command.name == \"%s\"",
                $hostName,
                $serviceName,
            );
        }

        // Sort and transforming the bucket timestamp to seconds. Note that, the KEEP order matters for the parser
        // Hint: ESQL uses an implicit LIMIT 1000 if nothing is set. As of ES9.3 the ESQL does not support pagination yet.
        // https://github.com/elastic/elasticsearch/issues/100000
        $query .= sprintf(" AND @timestamp >= TO_DATETIME(\"%s\") AND @timestamp <= NOW() | LIMIT 10000", $from);

        $query .= "| EVAL epoch_seconds = TO_LONG(@timestamp) / 1000 "
            . " | KEEP epoch_seconds, metrics.state_check.threshold, metrics.state_check.perfdata, attributes.perfdata_label, attributes.threshold_type, attributes.unit, @timestamp "
            . " | SORT @timestamp ASC, attributes.perfdata_label, attributes.unit DESC";

        return $query;
    }

    /**
     * fetchMetrics calls the ES HTTP API, decodes and returns the data.
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

        $start = $now->sub(new DateInterval($from))->getTimestamp();
        $end = $now->getTimestamp();

        $step = $this->calculateSteps($start, $end, $this->maxDataPoints, $checkInterval);
        $parsedFrom = $this->parseDuration($now, $from);

        // Escape double quotes and backslashes to prevent breaking the ESQL
        $escapedHost = addcslashes($hostName, '"\\');
        $escapedService = addcslashes($serviceName, '"\\');
        $escapedCommand = addcslashes($checkCommand, '"\\');

        // Check to see which query we're using
        if ($this->useTsAggregation) {
            $query = $this->buildQueryWithAggregation(
                hostName: $escapedHost,
                serviceName: $escapedService,
                checkCommand: $escapedCommand,
                from: $parsedFrom,
                isHostCheck: $isHostCheck,
                step: $step,
            );
        } else {
            $query = $this->buildQuery(
                hostName: $escapedHost,
                serviceName: $escapedService,
                checkCommand: $escapedCommand,
                from: $parsedFrom,
                isHostCheck: $isHostCheck,
            );
        }

        $pfr = new PerfdataResponse();

        Logger::debug('Calling query API with query: %s', $query);

        try {
            $response = $this->query($query);
        } catch (QueryException $e) {
            $pfr->addError($e);
            return $pfr;
        }

        $pfr = Transformer::transform($response, $includeMetrics, $excludeMetrics);

        return $pfr;
    }
}
