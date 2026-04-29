**Note:** This is an early release that is still in development and prone to change

# Icinga Web Performance Data Graphs Elasticsearch Backend

An Elasticsearch backend for the Icinga Web Performance Data Graphs Module.

This module requires the frontend module:

- https://github.com/NETWAYS/icingaweb2-module-perfdatagraphs

## Known Issues

> too_many_buckets_exception

Lower the `max_data_points` so that less buckets are calculated or reduce the number of metrics being fetched via the `metrics_include/exclude` settings.

## Installation Requirements

* PHP version ≥ 8.0
* Icinga2 ElasticsearchWriter or OTLPMetricsWriter
* IcingaDB or IDO Database
* Elasticsearch
