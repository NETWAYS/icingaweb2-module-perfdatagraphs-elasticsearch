<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Transport;

use Psr\Http\Message\RequestInterface;

interface HostPoolInterface
{
    /**
     * next returns the next host in the pool.
     */
    public function next(RequestInterface $request): Host;

    /**
     * setHosts set the list of hosts of this pool
     *
     * @param array $hosts list of hosts for this HostPool
     */
    public function setHosts(array $hosts): self;
}
