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
            // Note, we could optimize this by "caching" already matched metric names
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
        // None are exclucded if not set
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
     * @param array $excludeMetrics metrics to exclude from the response
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

        foreach ($stream->each() as $record) {
            $label = $record->getLabel();

            if (!self::isIncluded($label, $includeMetrics)) {
                continue;
            }
            if (self::isExcluded($label, $excludeMetrics)) {
                continue;
            }

            $dataset = $pfr->getDataset($label);
            // No, then create a new one
            if ($dataset === null) {
                $dataset = new PerfdataSet($label, $record->getUnit());
                $pfr->addDataset($dataset);
            }

            $dataset->addTimestamp($record->getTimestamp());

            $series = $dataset->getSeries();

            // Add series to the dataset if it exists
            foreach (['value', 'warning', 'critical'] as $key) {
                if (!isset($series[$key])) {
                    $series[$key] = new PerfdataSeries($key);
                    $dataset->addSeries($series[$key]);
                }
            }

            [$values, $warns, $crits] = [$series['value'], $series['warning'], $series['critical']];

            $values->addValue($record->getValue());
            $warns->addValue($record->getWarning());
            $crits->addValue($record->getCritical());
        }

        // Remove the empty series from the datasets
        $ds = $pfr->getDatasets();
        foreach ($ds as $dataset) {
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
