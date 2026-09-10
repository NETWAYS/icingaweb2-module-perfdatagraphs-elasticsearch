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

    private ?EsqlRecord $currentRecord = null;
    public bool $closed;

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

                // Skip the header. The query defines this, so if you change the query, this also needs to change
                if ($csv[0] === 'epoch_seconds') {
                    continue;
                }

                // Since each row that is returned only contains parts of the entire record, we
                // need to iterate over the rows and assemble the entire record, this makes working
                // with it later much more simple. The query should return data sorted by timestamp AND label
                // Example response:
                // epoch_seconds,metrics.state_check.threshold,...
                // 1789630266,,1.8800000000000002E-4,rta,,seconds,2026-09-17T07:31:06.111Z
                // 1789630266,0.0,,rta,min,,2026-09-17T07:31:06.111Z
                // 1789630266,80.0,,pl,warning,,2026-09-17T07:31:06.111Z

                // Note, this order depends on the query:
                // 0, epoch_seconds
                // 1, metrics.state_check.threshold
                // 2, metrics.state_check.perfdata
                // 3, attributes.perfdata_label
                // 4, attributes.threshold_type
                // 5, attributes.unit
                $ts = $csv[0] ?? 0;
                $label = $csv[3] ?? 'no-label';
                $type = $csv[4] === '' ? 'value': $csv[4];

                // A unique key for each row via timestamp + label
                $recordKey = $ts . '|' . $label;

                // New key started, thus we yield the record
                if ($this->currentRecord !== null && $this->currentRecord->getId() !== $recordKey) {
                    $record = $this->currentRecord;
                    // Reset current record after yielding it
                    $this->currentRecord = null;
                    yield $record;
                }

                // If we don't have a record, we build a new one
                if ($this->currentRecord === null) {
                    $this->currentRecord = new EsqlRecord(
                        Id: $recordKey,
                        label: $label,
                        timestamp: $ts,
                    );
                }

                // We already have a record, we fill the fields
                if ($type === 'value') {
                    $value = $csv[2] === '' ? null: floatval($csv[2]);
                    $this->currentRecord->setValue($value);

                    $unit = $csv[5] === '' ? '': $csv[5];
                    $this->currentRecord->setUnit($unit);
                }

                if ($type === 'warning') {
                    $warn = $csv[1] === '' ? null: floatval($csv[1]);
                    $this->currentRecord->setWarning($warn);
                }

                if ($type === 'critical') {
                    $crit = $csv[1] === '' ? null: floatval($csv[1]);
                    $this->currentRecord->setCritical($crit);
                }
            }
        } finally {
            $this->closeConnection();
        }
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
