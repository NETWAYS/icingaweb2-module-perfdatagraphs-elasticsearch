# Installation

## Packages

NETWAYS provides this module via [https://packages.netways.de](https://packages.netways.de/).

To install this module, follow the setup instructions for the **extras** repository.

**RHEL or compatible:**

`dnf install icingaweb2-module-perfdatagraphs-elasticsearch`

**Ubuntu/Debian:**

`apt install icingaweb2-module-perfdatagraphs-elasticsearch`

## From source

1. Clone the Icinga Web Performance Data Graphs Backend repository into `/usr/share/icingaweb2/modules/perfdatagraphselasticsearch/`

2. Enable the module using the `Configuration → Modules` menu or the `icingacli`

3. Configure the Elasticsearch URL and authentication using the `Configuration → Modules` menu

# Configuration

`config.ini` - section `elasticsearch`

| Option  | Description | Default value  |
|---------|-------------|----------------|
| icinga_writer  | Which Icinga2 Elasticsearch Writer is used to write data (OTLPMetricsWriter, ElasticsearchWriter)        |  |
| api_url  | Comma-separated URLs for Elasticsearch including the scheme. Example: `https://node2:9200,https://node2:9200`  |  |
| api_index      | The index that Icinag2 used for the performance data                                                     |  |
| api_timeout       | HTTP timeout for the API in seconds. Should be higher than 0                                          | `10` (seconds)  |
| api_tls_insecure  | Skip the TLS verification                                                                             | false |
| api_timeseries_aggregation  | Use ESQL timeseries aggregation. Only used in the OTLPMetricsWriter.                        | true  |
| api_max_data_points  | The maximum numbers of datapoints each series returns. Only used in the OTLPMetricsWriter.         | 10000  |
| api_auth_method     | Authentication method to use for the API                                                            | none (none,basic,token) |
| api_auth_username    | HTTP basic auth username                                                                           |   |
| api_auth_password    | HTTP basic auth password                                                                           |   |
| api_auth_tokentype   | Token type for the Authorization header                                                            | Bearer |
| api_auth_tokenvalue  | Token for the Authorization header                                                                 |   |
| api_auth_mtls     | Use client certificate (mTLS) for the connection                                                      | false |
| api_auth_mtls_cert  | Path to the client certificate file                                                                 |  |
| api_auth_mtls_key   | Path to the client key file                                                                         |  |
| api_auth_mtls_ca    | Path to the client CA file                                                                          |  |


This module uses the ESQL TS command to query the data.

The settings `api_timeseries_aggregation` and `api_max_data_points` are used for downsampling data.
When `api_timeseries_aggregation` is active the module will use the ESQL TBUCKET function to downsample the data into buckets.

The size of these buckets is determined by the `api_max_data_points`. The value of `api_max_data_points` is used to calculate the bucket size for the TBUCKET function.
That means, a higher value means a more buckets (more granularity) and a lower values means less buckets (less granularity).

Example query with `api_timeseries_aggregation` active:

> TS .ds-metrics-generic.otel* | WHERE resource.attributes.icinga2.host.name == "MyHost" AND resource.attributes.icinga2.command.name == "hostalive" AND @timestamp >= TO_DATETIME("2026-09-29T22:32:52") AND @timestamp <= NOW() | STATS metrics.state_check.threshold_avg = AVG(AVG_OVER_TIME(metrics.state_check.threshold)),metrics.state_check.perfdata_avg = AVG(AVG_OVER_TIME(metrics.state_check.perfdata)) BY attributes.perfdata_label, attributes.threshold_type, attributes.unit, bucket = TBUCKET(60 seconds) | LIMIT 100000 | EVAL epoch_seconds = TO_LONG(bucket) / 1000 | KEEP epoch_seconds, metrics.state_check.threshold_avg, metrics.state_check.perfdata_avg, attributes.perfdata_label, attributes.threshold_type, attributes.unit | SORT epoch_seconds ASC, attributes.perfdata_label, attributes.unit DESC

When `api_timeseries_aggregation` is disabled the module will use a simple ESQL query without any aggregation. This can be used when you are using internal downsampling mechanisms in Elasticsearch.

Example query with `api_timeseries_aggregation` disabled:

> TS .ds-metrics-generic.otel* | WHERE resource.attributes.icinga2.host.name == "MyHost" AND resource.attributes.icinga2.command.name == "hostalive" AND @timestamp >= TO_DATETIME("2026-09-29T22:32:17") AND @timestamp <= NOW() | LIMIT 1000000 | EVAL epoch_seconds = TO_LONG(@timestamp) / 1000 | KEEP epoch_seconds, metrics.state_check.threshold, metrics.state_check.perfdata, attributes.perfdata_label, attributes.threshold_type, attributes.unit, @timestamp  | SORT @timestamp ASC, attributes.perfdata_label, attributes.unit DESC

Note that, ESQL currently has fixed 10,000 row limit:

- https://www.elastic.co/docs/reference/query-languages/esql/limitations
- https://github.com/elastic/elasticsearch/issues/100000
