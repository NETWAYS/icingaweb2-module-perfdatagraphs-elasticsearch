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
    protected readonly string $index;

    public function __construct(
        string $urls,
        int $maxDataPoints,
        int $timeout,
        bool $tlsVerify,
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
                return new static($default['api_url'], $default['api_max_data_points'], 10, true, 'icinga2', []);
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

        return new static($baseURI, $maxDataPoints, $timeout, $tlsVerify, $index, $auth);
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

        // The index for the query
        $esql = sprintf("TS %s", $this->index);

        // The service or host filter
        if (!$isHostCheck) {
            $esql .= sprintf(
                "| WHERE resource.attributes.icinga2.host.name == \"%s\""
                    . " AND resource.attributes.icinga2.service.name == \"%s\""
                    . " AND resource.attributes.icinga2.command.name == \"%s\"",
                $hostName,
                $serviceName,
                $checkCommand,
            );
        } else {
            $esql .= sprintf(
                "| WHERE resource.attributes.icinga2.host.name == \"%s\""
                    . " AND resource.attributes.icinga2.command.name == \"%s\"",
                $hostName,
                $checkCommand,
            );
        }

        $esql .= sprintf(" AND @timestamp >= TO_DATETIME(\"%s\") AND @timestamp <= NOW()", $parsedFrom);

        // The aggregated values we want
        $esql .= sprintf(
            " | STATS avg_threshold = AVG(AVG_OVER_TIME(metrics.state_check.threshold)),"
                . "avg_perfdata = AVG(AVG_OVER_TIME(metrics.state_check.perfdata)) "
                . "BY attributes.perfdata_label, attributes.threshold_type, attributes.unit, bucket = TBUCKET(%s seconds)",
            $step,
        );

        // Sort and transforming the bucket timestamp to seconds
        $esql .= "| EVAL bucket_epoch_s = TO_LONG(bucket) / 1000 | DROP bucket | SORT bucket_epoch_s";

        $pfr = new PerfdataResponse();

        $response = $this->query($esql);

        if (is_array($response) && array_key_exists('error', $response)) {
            $pfr->addError(Json::encode($response['error']));
            return $pfr;
        }

        $pfr = Transformer::transform($response, $includeMetrics, $excludeMetrics);

        return $pfr;
    }
}
