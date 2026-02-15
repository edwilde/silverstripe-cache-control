# SilverStripe Cache Control

A SilverStripe CMS 5/6 module that gives content editors control over HTTP Cache-Control headers at both site-wide and page-specific levels.

## Features

- **Site-wide cache control settings** via SiteConfig
- **Page-level cache control overrides** for granular control
- **User-friendly CMS interface** with clear explanations for non-developers
- **Conditional field visibility** using DisplayLogic
- **Performance optimized** - minimal database queries
- **Sensible defaults** - 120 seconds cache time

## Version Compatibility

| Branch | CMS Version | PHP Version |
|--------|------------|-------------|
| `main` | CMS 6 | PHP 8.3+ |
| `cms5` | CMS 5 | PHP 8.1+ |

## Requirements

- SilverStripe CMS 6.0+
- PHP 8.3+
- unclecheese/display-logic ^4.0
- nswdpc/silverstripe-cache-headers (CMS 6: `dev-ss6` branch — no tagged release yet)

## Installation

```bash
composer require edwilde/silverstripe-cache-control:dev-main
```

> **Note:** The `nswdpc/silverstripe-cache-headers` dependency currently requires its `dev-ss6` branch for CMS 6 (no tagged release yet). Your project will need `"minimum-stability": "dev"` and `"prefer-stable": true` in its `composer.json`.

After installation, run:

```bash
vendor/bin/sake dev/build flush=1
```

## Usage

### Site-wide Settings

Navigate to **Settings > Cache Control** in the CMS to configure default cache headers:

- **Enable Cache Control**: Master switch for the entire site
- **Cache Type**: Choose between Public (CDN + browser) or Private (browser only)
- **Cache Duration**: Choose between Max Age (time-based caching) or No Store (no caching)
- **Max Age Duration**: Select from common preset durations (2 min, 5 min, 10 min, 1 hour, 1 day) or choose Custom
- **Custom Max Age**: When "Custom" is selected, enter your own cache duration in seconds
- **Enable Must Revalidate**: Force validation when cache expires (recommended)

### Page-specific Overrides

Each page has a **Cache Control** tab where you can:

1. View the current effective cache control header
2. Enable override to set page-specific settings
3. Configure the same options as site-wide settings

The page will show whether settings are inherited from site config or overridden at the page level.

## Cache Control Options Explained

### Public vs Private
- **Public**: Content can be cached by browsers, CDNs, and proxy servers. Best for pages that are the same for all users.
- **Private**: Content can only be cached by the user's browser. Use for personalized content.

### Max Age
Specifies how long (in seconds) the content can be cached before it must be revalidated. The module provides a dropdown with common preset values for ease of use:
- **2 minutes** (120 seconds) - Default, good for frequently updated content
- **5 minutes** (300 seconds) - Balance between freshness and performance
- **10 minutes** (600 seconds) - For moderately static content
- **1 hour** (3600 seconds) - For content that changes infrequently
- **1 day** (86400 seconds) - For highly static content
- **Custom** - Enter your own value in seconds for specific requirements

### Must Revalidate (Recommended)
Forces browsers to check with the server when the cache expires, rather than serving potentially stale content. **This is enabled by default and recommended for most scenarios** to ensure users receive fresh content when the cache expires.

### Cache Duration: No Store
Completely prevents caching. Use for sensitive or rapidly changing content. When "No Store" is selected, all other caching options (max-age, must-revalidate) are ignored and the Cache-Control header will only contain "no-store".

## Technical Details

### Architecture

The module consists of three main components:

1. **CacheControlSiteConfigExtension**: Adds cache control fields to SiteConfig
2. **CacheControlPageExtension**: Adds page-level override functionality
3. **CacheControlContentControllerExtension**: Applies the appropriate cache control headers to responses

### HTTP Headers

The module sets the following HTTP headers:

- **Cache-Control**: The primary caching directive (e.g., `public, max-age=300`)
- **Expires**: Automatically set to match the Cache-Control max-age for HTTP/1.0 compatibility

When max-age is specified, the Expires header is calculated as the current time plus the max-age value in GMT format. This ensures compatibility with older HTTP/1.0 caches and proxies while maintaining full HTTP/1.1 Cache-Control support.

### Performance Considerations

- The middleware only applies headers when no Cache-Control header already exists
- Page overrides are checked first to avoid unnecessary SiteConfig lookups
- All cache settings are stored as database fields for optimal performance
- No additional queries are made if cache control is disabled

### Middleware Priority

The middleware runs after request processors to ensure it can detect the current page context. It will not override any Cache-Control headers already set by controllers or other middleware.

## Development

### Running Tests

```bash
vendor/bin/phpunit
```

### Test Coverage

The module includes comprehensive PHPUnit tests covering:
- SiteConfig extension functionality
- Page extension functionality
- Cache header generation logic
- Override and fallback mechanisms

### Manual Testing of Cache Headers

Cache headers are only applied in `test` or `live` environment modes. To verify headers are being set correctly:

```bash
# Test site-level settings (page without override)
curl -s -D - -k "https://yoursite.local/page-without-override" | grep -i "^cache-control\|^expires\|^vary"

# Test page-level override
curl -s -D - -k "https://yoursite.local/page-with-override" | grep -i "^cache-control\|^expires\|^vary"
```

Expected output when cache control is enabled with max-age=300:
```
cache-control: public, must-revalidate, max-age=300
expires: Thu, 18 Dec 2025 05:00:00 GMT
vary: Accept-Encoding
```

**Note:** Headers will not appear in `dev` mode by default. You have two options:

1. **Switch to test/live mode** (recommended for production-like testing):
   ```
   # In your .env file
   SS_ENVIRONMENT_TYPE="test"
   ```

2. **Enable dev mode bypass** (for rapid development/testing):
   ```
   # In your .env file
   CACHE_HEADERS_IN_DEV="true"
   ```

   When `CACHE_HEADERS_IN_DEV` is enabled:
   - Cache headers will be applied in dev mode
   - All the same rules for restricted pages apply
   - Pages with forms or restricted access won't be cached
   - This is useful for testing cache behavior without switching environment modes

## License

BSD-3-Clause

## Maintainer

Ed Wilde <edwilde@example.com>

## Contributing

Contributions are welcome! Please submit pull requests with tests for any new features.

## Development with Symlinks

If you're developing this module and using a symlink in a SilverStripe project:

1. The module's `vendor/` directory should be excluded from SilverStripe's class manifest
2. Either remove the vendor directory from the module when symlinking:
   ```bash
   cd ~/Sites/modules/silverstripe-cache-control
   rm -rf vendor/
   ```

3. Or configure your project to exclude the symlinked vendor directory

This prevents class conflicts when SilverStripe scans for classes.
