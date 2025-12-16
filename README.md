# SilverStripe Cache Controls

A SilverStripe CMS 5 module that gives content editors control over HTTP Cache-Control headers at both site-wide and page-specific levels.

## Features

- **Site-wide cache control settings** via SiteConfig
- **Page-level cache control overrides** for granular control
- **User-friendly CMS interface** with clear explanations for non-developers
- **Conditional field visibility** using DisplayLogic
- **Performance optimized** - minimal database queries
- **Sensible defaults** - 120 seconds cache time

## Requirements

- SilverStripe CMS 5.0+
- PHP 8.1+
- unclecheese/display-logic ^3.0

## Installation

```bash
composer require edwilde/silverstripe-cache-controls
```

After installation, run:

```bash
vendor/bin/sake dev/build flush=1
```

## Usage

### Site-wide Settings

Navigate to **Settings > Cache Control** in the CMS to configure default cache headers:

- **Enable Cache Control**: Master switch for the entire site
- **Cache Type**: Choose between Public (CDN + browser) or Private (browser only)
- **Enable Max Age**: Control how long content is cached
- **Max Age**: Set the cache duration in seconds (default: 120)
- **Enable Must Revalidate**: Force validation when cache expires
- **Enable No Store**: Prevent all caching (overrides other settings)

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
Specifies how long (in seconds) the content can be cached before it must be revalidated. Common values:
- 60 = 1 minute
- 300 = 5 minutes
- 3600 = 1 hour
- 86400 = 1 day

### Must Revalidate
Forces browsers to check with the server when the cache expires, rather than serving potentially stale content.

### No Store
Completely prevents caching. Use for sensitive or rapidly changing content. This overrides all other caching directives.

## Technical Details

### Architecture

The module consists of three main components:

1. **CacheControlSiteConfigExtension**: Adds cache control fields to SiteConfig
2. **CacheControlPageExtension**: Adds page-level override functionality
3. **CacheControlMiddleware**: Applies the appropriate Cache-Control header to responses

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
- Middleware behavior
- Cache header generation logic
- Override and fallback mechanisms

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
   cd ~/Sites/modules/silverstripe-cache-controls
   rm -rf vendor/
   ```

3. Or configure your project to exclude the symlinked vendor directory

This prevents class conflicts when SilverStripe scans for classes.
