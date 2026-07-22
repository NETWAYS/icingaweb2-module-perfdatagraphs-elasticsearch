<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use GuzzleHttp\Psr7\Stream;

/**
 * EsqlCsvParser takes a CSV Stream and returns nice little Records
 */
class EsqlCsvParser
{
    private $response;
    private $resource;
    private $stream;

    public $closed;

    public function __construct(Stream $response)
    {
        $this->response = $response;
        $this->resource = $response->detach();
        $this->closed = false;
    }

    public function each()
    {
        // avg_threshold,avg_perfdata,attributes.perfdata_label,attributes.threshold_type,bucket_epoch_s
        // 0.0,,load1,min,1783430880
        // ,0.09,load1,,1783430880
        // 3.0,,load15,warning,1783430880
        try {
            while (($csv = fgetcsv($this->resource, escape: "\\")) !== false) {
                if (!isset($csv) || (count($csv) === 1 && $csv[0] === null)) {
                    continue;
                }

                // Skip the header
                if ($csv[0] === 'avg_threshold') {
                    continue;
                }

                $result = $this->parseLine($csv);

                if ($result instanceof EsqlRecord) {
                    yield $result;
                }
            }
        } finally {
            $this->closeConnection();
        }
    }

    private function parseLine(array $csv): EsqlRecord
    {
        // 0              1             2                          3                          4     5
        // avg_threshold, avg_perfdata, attributes.perfdata_label, attributes.threshold_type, unit, bucket_epoch_s
        // 0.0,,load1,min,1783430880
        // ,0.09,load1,,1783430880
        // 3.0,,load15,warning,1783430880
        $label = $csv[2] ?? '';
        $timestamp = $csv[5] ?? 0;
        $value = $csv[1] === '' ? null: floatval($csv[1]);
        $recordType = $csv[3] === '' ? 'value': $csv[3];
        $unit = $csv[4] === '' ? '': $csv[4];

        $warn = null;
        $crit = null;

        if ($recordType === 'warning') {
            $warn = $csv[0] === '' ? null: floatval($csv[0]);
        }

        if ($recordType === 'critical') {
            $crit = $csv[0] === '' ? null: floatval($csv[0]);
        }

        $record = new EsqlRecord($recordType, $label, $timestamp, $value, $warn, $crit, $unit);

        return $record;
    }

    private function closeConnection(): void
    {
        # Close CSV Parser
        $this->closed = true;
        if (isset($this->response)) {
            $this->response->close();
        }
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }

        unset($this->response);
        unset($this->resource);
    }
}
