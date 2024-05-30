![Bot Logger](bot-logger.webp)

Bot Logger is a WordPress plugin designed to log and validate incoming requests from search engine bots. It logs Googlebot and Bingbot by default, and allows up to 3 custom user agents. Bot Logger was designed to make log file analysis more convenient and accessible, allowing users to view details usually only shown in access logs such as user agent, request date, resource, status code, and IP address directly in the WordPress admin panel.

## Features

- Logs requests from Googlebot and Bingbot by default
  - Handles a variety of Google's alternate user agents
- Ability to import existing access.log data from the server (assuming www-data user has read access)
  - Bot Logger automatically looks in standard access.log paths (eg. /var/log/nginx/) for Nginx, Apache, and Litespeed. Node.js is not supported at this time. 
  -Users can set a custom path in the import page settings.
- Automatic and manual IP validation against Google's and Bing's IP ranges
- Ability to track custom user agents (eg. additional search engines, social media apps, scrapers etc)
- Tabbed interface for viewing logs from different bots
- Manage retention period and validation frequency in the settings page, as well as manual clearing of log data
- Cloudflare integration automatically creates a cache-rule for custom user agents to ensure they hit the origin server and are logged

## Support

If you encounter any issues or have questions about the plugin, please open an issue on the [Issues Page](https://github.com/mikeciffone/Bot-Logger/issues). You can also submit issues using the form on the [Bot Logger web page](https://ciffonedigital.com/bot-logger/).

## License

This plugin is licensed under the [GNU General Public License v3.0](LICENSE).

