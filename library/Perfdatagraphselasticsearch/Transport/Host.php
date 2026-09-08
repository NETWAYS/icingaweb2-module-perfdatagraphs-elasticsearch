<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Transport;

use GuzzleHttp\Psr7\Uri;

/**
 * Host represents a single cluster node via its URL
 */
class Host
{
    // The URL of this host
    protected Uri $url;
    // Is this host reachable
    protected bool $reachable = true;

    public function __construct(string $url)
    {
        $this->url = new Uri($url);
    }

    public function getURL(): Uri
    {
        return $this->url;
    }

    public function isReachable(): bool
    {
        return $this->reachable;
    }

    public function setReachable(bool $reachable): void
    {
        $this->reachable = $reachable;
    }
}
