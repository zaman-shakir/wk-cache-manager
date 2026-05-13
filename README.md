# WK Cache Manager

Unified cache management plugin for WordPress.

## Features

### URL Crawler
- Automatically warm cache when products/posts are updated
- **Async crawling**: Uses non-blocking HTTP requests (independent of WordPress cron)
- **No site visits required**: Crawls happen automatically in background
- Crawl category pages
- Track cache status (HIT/MISS)
- Detailed logging with CloudFlare cache status tracking
- URL locking to prevent duplicate crawls
- 30-second delay after updates to allow cache propagation

### Cache Monitor
- Monitor all LiteSpeed cache purge events
- Monitor all CloudFlare cache purge events
- Categorize purges (Single Page/Multiple URLs/Full Site)
- Track purge sources (which plugin triggered)
- Visual log viewer with filtering
- Automatic weekly log cleanup

### LiteSpeed Manager (Smart Cache Optimization)
- **Stock-based cache management**: Only purge cache when product stock is low
- **Prevents unnecessary purges**: High-stock products stay cached for better performance
- **Configurable threshold**: Set your own stock level trigger (default: 20 units)
- **Dedicated logging**: Track all cache decisions for products
- **Works with**: LiteSpeed Cache + WooCommerce

## Installation

1. Upload to `/wp-content/plugins/wk-cache-manager/`
2. Activate through WordPress admin
3. Configure settings under "Cache Manager" menu

## Configuration

### URL Crawler Settings
Go to: **Cache Manager → URL Crawler Settings**

- **Crawler Endpoint**: URL of your crawler server (default: `https://cachecrawler.net/url_crawler.php`)
- **Auto Crawl on Publish/Update**: Enable/disable automatic crawling
- **Crawl Product Categories**: Enable/disable category page crawling

### Cache Monitor
Go to: **Cache Manager → Cache Monitor Logs**

No configuration needed - starts logging automatically when activated.

### LiteSpeed Manager
Go to: **Cache Manager → LiteSpeed Manager**

1. Set your **Stock Threshold** (e.g., 20 units)
2. Save settings
3. Monitor decisions in **Cache Manager → Stock Log**

**How it works**: When an order is completed, only products with stock ≤ threshold will have their cache purged. High-stock products stay cached for faster performance.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (optional, for product crawling)
- LiteSpeed Cache and/or WP Cloudflare Page Cache (optional, for monitoring)

## Menu Structure

```
Cache Manager (top-level menu)
├── Dashboard
├── URL Crawler Settings
├── URL Crawler Logs
├── LiteSpeed Manager
├── Stock Log
└── Cache Monitor Logs
```

## Logs

All logs are stored in: `wp-content/plugins/wk-cache-manager/logs/`

- `url-crawler-*.log` - URL crawler activity
- `cache-monitor.log` - Cache purge events
- `litespeed-stock.log` - LiteSpeed stock management decisions

## Changelog

### 1.0.0 (2025-01-22)
- Initial release
- Merged WK URL Crawler and WK Cache Monitor
- Unified admin interface with dashboard
- Improved logging system

## Credits

Developed by:
- Shakir
- Md Rashedul Islam
- Webkonsulenter team

## Support

For support, please contact:
- Website: https://webkonsulenter.dk
- Email: support@webkonsulenter.dk

## License

GPL v2 or later
