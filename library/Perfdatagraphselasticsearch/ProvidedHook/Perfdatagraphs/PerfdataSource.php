<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\ProvidedHook\PerfdataGraphs;

use Icinga\Module\Perfdatagraphselasticsearch\Client\Elasticsearch;

use Icinga\Module\Perfdatagraphs\Hook\PerfdataSourceHook;
use Icinga\Module\Perfdatagraphs\Model\PerfdataRequest;
use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateTime;
use Exception;

class PerfdataSource extends PerfdataSourceHook
{
    public function getName(): string
    {
        return 'Elasticsearch';
    }

    public function fetchData(PerfdataRequest $req): PerfdataResponse
    {
        // Parse the duration
        $now = new DateTime();
        $from = Elasticsearch::parseDuration($now, $req->getDuration());

        $perfdataresponse = new PerfdataResponse();

        // Create a client and get the data from the API
        try {
            $client = Elasticsearch::fromConfig();
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
            return $perfdataresponse;
        }

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

        // Why even bother when we have errors here
        if ($perfdataresponse->hasErrors()) {
            return $perfdataresponse;
        }

        return $perfdataresponse;
    }
}
