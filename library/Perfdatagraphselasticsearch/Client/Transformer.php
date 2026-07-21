<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use GuzzleHttp\Psr7\Response;

/**
 * Transformer is a helper to transform the CSV into a PerfdataResponse
 */
class Transformer
{

    /**
     * isIncluded checks if the given metric should be included in the response
     *
     * @param string $metricname
     * @param array $includeMetrics
     * @return bool
     */
    public static function isIncluded($metricname, array $includeMetrics = []): bool
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

    /**
     * isExcluded checks if the given metric should be excluded from the response
     *
     * @param string $metricname
     * @param array $excludeMetrics
     * @return bool
     */
    public static function isExcluded($metricname, array $excludeMetrics = []): bool
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
     * transform takes the response and transforms it into the
     * output format we need.
     *
     * @param GuzzleHttp\Psr7\Response $response the data to transform
     * @param array $includeMetrics metrics to include in the response
     * @param array $excludeMetrics metrics to exlude from the response
     * @return PerfdataResponse
     */
    public static function transform(
        Response $response,
        array $includeMetrics = [],
        array $excludeMetrics = [],
    ): PerfdataResponse {
        $pfr = new PerfdataResponse();

        $stream = new EsqlCsvParser($response->getBody());

        $timestamps = [];

        $lastTS = 0;
        foreach ($stream->each() as $record) {
            $label = $record->getLabel();

            if (!self::isIncluded($label, $includeMetrics)) {
                continue;
            }
            if (self::isExcluded($label, $excludeMetrics)) {
                continue;
            }
            // The timestamp for all labels is the same, so we only add a new ts if it increases.
            // There might be a better way to do this via the query?
            $ts = $record->getTimestamp();
            if ($ts > $lastTS) {
                $timestamps[] = $ts;
                $lastTS = $ts;
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

            $unit = $record->getUnit();
            if ($unit !== '') {
                $dataset->setUnit($record->getUnit());
            }

            $type = $record->getRecordType();

            if ($type === 'value') {
                $values->addValue($record->getValue());
            }

            if ($type === 'warning') {
                $warns->addValue($record->getWarning());
            }

            if ($type === 'critical') {
                $crits->addValue($record->getCritical());
            }
        }

        // Remove the empty series from the datasets
        $ds = $pfr->getDatasets();
        foreach ($ds as $dataset) {
            $dataset->setTimestamps($timestamps);
            $series = $dataset->getSeries();
            foreach ($series as $s) {
                if ($s->isEmpty()) {
                    $dataset->removeSeries($s->getName());
                }
            }
        }

        return $pfr;
    }
}
