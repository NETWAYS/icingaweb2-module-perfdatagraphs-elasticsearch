# Icinga Web Performance Data Graphs Elasticsearch Backend

An Elasticsearch backend for the Icinga Web Performance Data Graphs Module.

It is meant to be used with the Icinga2 ElasticsearchWriter or OTLPMetricsWriter.

The OTLPMetricsWriter requires at least Elasticsearch 9.2.

The OTLP/HTTP endpoint that Icinga2 can use requires Elasticsearch 9.2. This module uses the ESQL TS command to query the data, this feature was in Preview since Elasticsearch 9.2 and is GA since Elasticsearch 9.4.

Note that, the Icinga2 ElasticsearchWriter is deprecated. This module will focus on the OTLPMetricsWriter.
