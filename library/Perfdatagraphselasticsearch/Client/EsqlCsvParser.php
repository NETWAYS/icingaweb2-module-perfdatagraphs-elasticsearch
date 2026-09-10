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
        try {
            while (($csv = fgetcsv($this->resource, escape: "\\")) !== false) {
                if (!isset($csv) || (count($csv) === 1 && $csv[0] === null)) {
                    continue;
                }

                // Skip the header. Note, avg_threshold is defined in the query. Ensure to change this if the query changes
                if ($csv[0] === 'epoch_seconds') {
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
        // 0           1                              2                             3                          4                          5
        // epoch_seconds,metrics.state_check.threshold,metrics.state_check.perfdata,attributes.perfdata_label,attributes.threshold_type,attributes.unit,@timestamp
        // 1789041007,,0.12,load15,,,2026-09-10T11:50:07.553Z
        // 1789041007,0.0,,load5,min,,2026-09-10T11:50:07.553Z
        // 1789041007,3.0,,load15,warning,,2026-09-10T11:50:07.553Z
        $label = $csv[3] ?? '';
        $timestamp = $csv[0] ?? 0;
        $value = $csv[2] === '' ? null: floatval($csv[2]);
        $recordType = $csv[4] === '' ? 'value': $csv[4];
        $unit = $csv[5] === '' ? '': $csv[5];

        $warn = null;
        $crit = null;

        if ($recordType === 'warning') {
            $warn = $csv[1] === '' ? null: floatval($csv[1]);
        }

        if ($recordType === 'critical') {
            $crit = $csv[1] === '' ? null: floatval($csv[1]);
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
