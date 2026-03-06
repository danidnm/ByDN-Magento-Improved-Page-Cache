# Magento 2 Improved Page Cache Extension

This Magento 2 extension provides an advanced mechanism for warming and managing the page cache (Varnish or Built-in cache). It allows for efficient, priority-based cache warming across multiple store views, ensuring that your customers always experience fast page load times.

With features like parallel processing, detailed monitoring in the admin panel, and flexible enqueueing options via console commands, this extension is designed to handle high-traffic stores and complex cache management needs.

## Features

- **Smart Cache Refresh Strategy**: Intercepts massive cache purges and enqueues them for background warming, preventing performance drops during product or category updates.
- **Manual Enqueueing**: Warm cache for home page, categories, products, CMS pages, or specific URLs.
- **Priority Management**: Assign priority levels (1-5) to warming tasks to ensure critical pages are cached first.
- **Parallel Processing**: Configurable concurrency levels to speed up the warming process.
- **Automated Cron Jobs**: Scheduled tasks for regular cache warming, cleanup of old records, and statistics generation.
- **Admin Monitoring**: A dedicated grid in the backoffice to track the status, processing time, and results of each warming item.
- **Store-specific Warming**: Full support for multi-store environments, ensuring correct URL generation and warming per store.

# Installation

Run:
```bash
composer require bydn/improved-page-cache
./bin/magento module:enable Bydn_ImprovedPageCache
./bin/magento setup:upgrade
```

# Configuration

Access the configuration by going to:

    Stores => Configuration => AI Extensions (by DN) => Improved Page Cache.

## General Configuration

- Enabled. This option allows you to completely enable or disable the extension.
- Concurrency Level. Set the number of simultaneous parallel requests during the warming process (default: 5).
- Cleanup Old Records (Days). Automatically delete warm records older than the specified number of days (default: 7).

# Smart Cache Refresh

One of the core advantages of this module is its **Optimized Refresh Strategy**. 

Standard Magento behavior often triggers massive, resource-intensive cache purges—for example, clearing every single category page associated with a product when that product is updated. This can lead to significant performance spikes and a degraded user experience.

This module intercepts these massive purge requests. Instead of performing a destructive multi-page deletion, it adds the affected URLs to the **Warming Queue**. Supported by our intelligent priority system, the refresh happens almost instantly in the background. Your customers will always see fresh content without ever experiencing a slow or uncached website.

## Varnish Configuration

To take full advantage of this feature, you must update your Varnish VCL configuration. Add the following logic at the beginning of the `vcl_recv` function:

```vcl
if (req.http.X-Magento-Cache-Refresh) {
    set req.hash_always_miss = true;
}
```

This tells Varnish to fetch a fresh version and replace the cached one whenever the module triggers a refresh request.

# Usage

## Console Commands

The extension provides two main console commands for managing the cache warming queue manually.

### Enqueueing Items

Use `bydn:cache:enqueue` to add items to the warming queue.

**Examples:**
- Warm home page for store 1:
  ```bash
  php bin/magento bydn:cache:enqueue --stores 1 --type home
  ```
- Warm all products for store 1 with high priority:
  ```bash
  php bin/magento bydn:cache:enqueue --stores 1 --type products --ids all --priority 5
  ```
- Warm specific categories:
  ```bash
  php bin/magento bydn:cache:enqueue --stores 1 --type categories --ids 10,11,12
  ```
- Warm a specific URL:
  ```bash
  php bin/magento bydn:cache:enqueue --stores 1 --type url --url "https://example.com/custom-page"
  ```

### Processing the Queue

While cron jobs typically handle the queue, you can manually trigger the warming process:
```bash
php bin/magento bydn:cache:warm
```
You can also filter by minimum priority:
```bash
php bin/magento bydn:cache:warm --priority 3
```

## Admin Monitoring

You can track the progress and results of the cache warming tasks in the admin panel.

Go to:

    System => Tools => Cache Warming.

<img alt="Add gift card product" width="100%" src="[https://github.com/danidnm/ByDN-Magento-Improved-Page-Cache/blob/master/docs/images/stats.png"/>

On this screen, you can see:
- **URL**: The specific URL being warmed.
- **Status**: Current status (Pending, Processing, Done, Error).
- **Processing Time**: How long each request took.
- **HTTP Code**: The result of the warming request (e.g., 200 OK).
- **Priority**: The priority level of the item.

## Automated Tasks (Cron)

The extension includes a dedicated cron group `cachewarm` with several jobs:
- `bydn_improvedpagecache_warm`: Processes the standard queue every minute.
- `bydn_improvedpagecache_warm_priority`: Processes priority items every minute.
- `bydn_improvedpagecache_cleanup`: Cleans up old records daily.
- `bydn_improvedpagecache_stats`: Updates warming statistics.

To run the cache warming cron separately, you can use:
```bash
./bin/magento cron:run --group cachewarm
```

# Having Problems?

Contact me at soy at solodani.com

# License

This Magento 2 extension was created and is maintained by Daniel Navarro (https://github.com/danidnm).

If you fork, modify, or redistribute this extension, please:

- Keep the code free and open source under the same GPL-3.0 license.
- Mention the original author in your README or composer.json.
