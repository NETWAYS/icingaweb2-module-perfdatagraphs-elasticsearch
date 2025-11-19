<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\ProvidedHook\PerfdataGraphs;

use Icinga\Module\Perfdatagraphselasticsearch\Client\ESInterface;
use Icinga\Module\Perfdatagraphselasticsearch\Client\ElasticsearchClient;
use Icinga\Module\Perfdatagraphselasticsearch\Client\ElasticsearchDatastreamClient;

use Icinga\Module\Perfdatagraphs\Hook\PerfdataSourceHook;
use Icinga\Module\Perfdatagraphs\Model\PerfdataRequest;
use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;

use Icinga\Application\Config;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateTime;
use RuntimeException;
use Exception;

class PerfdataSource extends PerfdataSourceHook
{
    protected function getClient(): ESInterface
    {
        $moduleConfig = Config::module('perfdatagraphselasticsearch');

        $writer = $moduleConfig->get('elasticsearch', 'icinga_writer', '');

        if ($writer === 'ElasticsearchWriter') {
            $client = ElasticsearchClient::fromConfig();
            return $client;
        }

        if ($writer === 'ElasticsearchDatastreamWriter') {
            $client = ElasticsearchDatastreamClient::fromConfig();
            return $client;
        }

        throw new RuntimeException('No valid Icinga2 Writer configured');
    }

    public function getName(): string
    {
        return 'Elasticsearch';
    }

    public function fetchData(PerfdataRequest $req): PerfdataResponse
    {
        $perfdataresponse = new PerfdataResponse();

        $client = null;
        try {
            $client = $this->getClient();
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
            return $perfdataresponse;
        }

        $now = new DateTime();
        $from = $client->parseDuration($now, $req->getDuration());

        // Let's fetch the data from the Elasticsearch API
        try {
            $perfdataresponse = $client->fetchMetrics(
                $req->getHostname(),
                $req->getServicename(),
                $req->getCheckcommand(),
                $from,
                $req->isHostCheck(),
                $req->getIncludeMetrics(),
                $req->getExcludeMetrics(),
            );
        } catch (ConnectException $e) {
            $perfdataresponse->addError($e->getMessage());
        } catch (RequestException $e) {
            $perfdataresponse->addError($e->getMessage());
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
        }

        return $perfdataresponse;
    }
}
