# Icinga Web Performance Data Graphs Elasticsearch Backend

An Elasticsearch backend for the Icinga Web Performance Data Graphs Module.

This module requires the frontend module:

- https://github.com/NETWAYS/icingaweb2-module-perfdatagraphs

Other Icinga and Elasticsearch integrations we provide:

- https://github.com/NETWAYS/check_elasticsearch

## Installation Requirements

* PHP version ≥ 8.0
* Icinga2 ElasticsearchWriter or OTLPMetricsWriter
* IcingaDB or IDO Database
* Elasticsearch (OTLP requires at least Elasticsearch 9.2)

The OTLP/HTTP endpoint that Icinga2 can use requires Elasticsearch 9.2. This module uses the ESQL TS command to query the data, this feature was in Preview since Elasticsearch 9.2 and is GA since Elasticsearch 9.4.

Note that, the Icinga2 ElasticsearchWriter is deprecated. This module will focus on the OTLPMetricsWriter.
