# Installation

## Packages

NETWAYS provides this module via [https://packages.netways.de](https://packages.netways.de/).

To install this module, follow the setup instructions for the **extras** repository.

**RHEL or compatible:**

`dnf install icingaweb2-module-perfdatagraphs-elasticsearch`

**Ubuntu/Debian:**

`apt install icingaweb2-module-perfdatagraphs-elasticsearch`

## From source

1. Clone a Icinga Web Performance Data Graphs Backend repository into `/usr/share/icingaweb2/modules/perfdatagraphselasticsearch/`

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
| api_tls_insecure  | Skip the TLS verification                                                                             | `false` (unchecked)  |
| api_auth_method     | Authentication method to use for the API                                                            | none (none,basic,token) |
| api_auth_username    | HTTP basic auth username                                                                           |   |
| api_auth_password    | HTTP basic auth password                                                                           |   |
| api_auth_tokentype   | Token type for the Authorization header                                                            | `Bearer` |
| api_auth_tokenvalue  | Token for the Authorization header                                                                 |   |
