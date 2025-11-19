<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;

/**
 * ESInterface
 */
interface ESInterface
{
    public function status(): array;

    public function fetchMetrics(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics,
    ): PerfdataResponse;
}
