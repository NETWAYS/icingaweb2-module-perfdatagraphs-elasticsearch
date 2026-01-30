# Installation

## From source

1. Clone a Icinga Web Performance Data Graphs Backend repository into `/usr/share/icingaweb2/modules/perfdatagraphselasticsearch/`

2. Enable the module using the `Configuration → Modules` menu or the `icingacli`

3. Configure the Elasticsearch URL and authentication using the `Configuration → Modules` menu

# Configuration

`config.ini` - section `elasticsearch`

| Option  | Description | Default value  |
|---------|-------------|----------------|
| api_url  | Comma-separated URLs for Elasticsearch including the scheme. Example: `https://node2:9200,https://node2:9200`  |  |
| api_username      | The user for HTTP basic auth. Not used if empty          |  |
| api_password      | The password for HTTP basic auth. Not used if empty      |  |
| api_index      | The index that Icinag2 used for the performance data. Only used with the ElasticsearchWriter |  |
| api_timeout       | HTTP timeout for the API in seconds. Should be higher than 0  | `10` (seconds)  |
| api_tls_insecure  | Skip the TLS verification  | `false` (unchecked)  |
