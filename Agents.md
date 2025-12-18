# Agents.md

## Project Overview

**SilverStripe Cache Controls** is a SilverStripe CMS 5 module that provides content editors with control over HTTP Cache-Control headers. It enables cache management at both site-wide and page-specific levels through an intuitive CMS interface.

### Key Features
- Site-wide default cache control settings via SiteConfig
- Page-level cache control overrides
- User-friendly CMS interface with clear explanations for non-technical editors
- Automatic HTTP header application via middleware
- Public/Private cache type selection (mutually exclusive)
- Max-age control with sensible 120-second default
- Must-revalidate and no-store directives
- Conditional field visibility using DisplayLogic
- Performance-optimized with minimal database queries

### Technology Stack
- **PHP**: 8.1+
- **SilverStripe Framework**: 5.0+
- **SilverStripe CMS**: 5.0+
- **Dependencies**: 
  - unclecheese/display-logic ^3.0 (conditional CMS field visibility)
  - nswdpc/silverstripe-cache-headers ^1.0 (robust cache header middleware)
- **Testing**: PHPUnit 9.5+

## Architecture

### Component Structure

```
src/
└── Extensions/
    ├── CacheControlSiteConfigExtension.php       # Site-wide cache settings UI
    ├── CacheControlPageExtension.php             # Page-level override UI
    ├── CacheControlContentControllerExtension.php # Applies settings via nswdpc middleware
    └── DevCacheBypassExtension.php                # Optional: Enable cache headers in dev mode
```

### Design Patterns

**DataExtension Pattern**: Used for `CacheControlSiteConfigExtension` and `CacheControlPageExtension` to add cache control functionality to existing SilverStripe models (SiteConfig and SiteTree).

**Extension Pattern**: Used for `CacheControlContentControllerExtension` to hook into ContentController and apply cache settings without adding database fields.

**Middleware Integration**: Uses `nswdpc/silverstripe-cache-headers` module for robust HTTP cache header management, including form detection, error page handling, and respecting existing headers.

**Strategy Pattern**: The module checks if a page has an override enabled, then decides whether to use page-specific settings or fall back to site-wide defaults.

### Data Flow

1. **Request comes in** → nswdpc middleware is registered to intercept
2. **ContentController initializes** → `CacheControlContentControllerExtension::onAfterInit()` is triggered
3. **Check for page override**:
   - If `OverrideCacheControl` is enabled → Use page settings (via `applyPageSettings()`)
   - Otherwise → Use site-wide SiteConfig settings (via `applySiteSettings()`)
4. **Apply settings** to `HTTPCacheControlMiddleware` singleton:
   - Set cache state (public/private or disabled)
   - Set cache duration (max-age or no-store)
   - Add Expires header to match max-age
   - Apply Vary headers based on CMS configuration
5. **Middleware processes response** → nswdpc module applies configured headers, respecting:
   - Existing headers (doesn't override)
   - Forms on page (disables cache)
   - Error pages (configurable)
   - User login state

**Important**: When override is enabled at page level but `EnableCacheControl` is false, the middleware is set to `disableCache(true)` (private, no-store). This allows editors to explicitly disable caching on specific pages even when site-wide caching is enabled.

### Database Schema

**SiteConfig Table Extensions**:
```php
'EnableCacheControl' => 'Boolean'                      // Master switch (default: false)
'CacheType' => 'Enum("public,private","public")'      // Cache visibility (default: public)
'CacheDuration' => 'Enum("maxage,nostore","maxage")'  // Duration strategy (default: maxage)
'MaxAge' => 'Int'                                      // Custom cache duration in seconds (default: 120)
'MaxAgePreset' => 'Enum(...,"120")'                    // Preset durations: 120, 300, 600, 3600, 86400, custom
'EnableMustRevalidate' => 'Boolean'                    // Force revalidation (default: true, recommended)
'VaryAcceptEncoding' => 'Boolean'                      // Vary: Accept-Encoding (default: true)
'VaryXForwardedProtocol' => 'Boolean'                  // Vary: X-Forwarded-Protocol (default: false)
'VaryCookie' => 'Boolean'                              // Vary: Cookie (default: false)
'VaryAuthorization' => 'Boolean'                       // Vary: Authorization (default: false)
```

**SiteTree Table Extensions** (same fields as SiteConfig plus):
```php
'OverrideCacheControl' => 'Boolean'                    // Enable page-specific override (default: false)
```

Note: Page-level extensions don't include Vary headers - those are site-wide only.

### Cache Header Generation Logic

Located in both `CacheControlSiteConfigExtension::getCacheControlHeader()` and `CacheControlPageExtension::getPageCacheControlHeader()`:

1. **Early return** if `EnableCacheControl` is false → returns `null`
2. **Check cache duration**:
   - If `CacheDuration` is `'nostore'` → return `"no-store"` only (overrides everything else)
3. **Build directives array** (if using maxage):
   - Add cache type (`public` or `private`)
   - Add `max-age={value}`:
     - If `MaxAgePreset` is `'custom'`, use the `MaxAge` field value
     - Otherwise, use the preset value from `MaxAgePreset` (120, 300, 600, 3600, 86400)
     - Defaults to 120 if neither is set
   - Add `must-revalidate` if `EnableMustRevalidate` is true
4. **Join directives** with `, ` and return as string

**Example outputs**:
- `"public, max-age=120"`
- `"private, max-age=3600, must-revalidate"`
- `"no-store"` (ignores all other settings)

### HTTP Headers Applied

The module applies the following HTTP headers:

1. **Cache-Control**: The primary header controlling cache behavior (HTTP/1.1+)
2. **Expires**: Set to match Cache-Control max-age for HTTP/1.0 compatibility

**Expires Header**: When max-age is set, the Expires header is automatically calculated as `current_time + max-age` in GMT format. This ensures compatibility with older HTTP/1.0 caches and proxies. Per the HTTP specification, Cache-Control takes precedence over Expires in HTTP/1.1 clients, but both should be present for maximum compatibility.

## Development Guide

### Project Setup

```bash
# Clone repository
git clone git@github.com:edwilde/silverstripe-cache-controls.git
cd silverstripe-cache-controls

# Install dependencies
composer install

# Run unit tests
vendor/bin/phpunit tests/Unit/ --testdox
```

### Development Environment

**Cache Headers and Environment Modes**:

By default, the nswdpc module (which this module extends) only applies cache headers when the SilverStripe environment is in `test` or `live` mode. This can make development and testing difficult.

**Dev Mode Bypass Feature**:

To enable cache headers in `dev` mode for testing purposes, add to your `.env` file:

```
CACHE_HEADERS_IN_DEV="true"
```

When enabled:
- Cache headers will be applied even in `dev` mode
- All security rules still apply (forms, restricted pages, etc.)
- Pages that shouldn't be cached won't be cached
- Useful for rapid testing without switching environment modes

**Implementation**: The `DevCacheBypassExtension` checks for the environment variable and applies cache logic that would normally only run on the LIVE stage. It maintains the same security checks for restricted pages, forms, and login states.

### Testing Strategy

**Test-Driven Development (TDD)**: This module follows TDD principles - write tests first, then implement features.

**Unit Tests**: Not currently used as header logic is delegated to nswdpc middleware

**Integration Tests**:
- Located in `tests/Extensions/` and `tests/Middleware/`
- Test full SilverStripe integration
- Require database and SilverStripe environment
- Test field addition, override logic, and middleware behavior

**Functional Tests** (CRITICAL):
- Located in `tests/Functional/`
- **Must test ALL cache directive combinations**:
  - Site-wide: public + max-age, private + max-age, no-store
  - Page override: all above combinations
  - Page override with cache disabled
  - Fallback to site config when override disabled
  - Must-revalidate combinations
- Tests actual HTTP response headers
- Prevents regressions like "no-store shows public,max-age=0"
- Essential for validating that header generation AND application both work correctly

**Running Tests**:
```bash
# All tests (requires full SilverStripe environment)
vendor/bin/phpunit --testdox

# Specific test suites
vendor/bin/phpunit tests/Extensions/ --testdox
```

**Testing Checklist for New Features**:
1. Write unit tests for header generation logic
2. Write functional tests for all combinations
3. Verify tests fail without implementation (red)
4. Implement feature
5. Verify tests pass (green)
6. Refactor if needed while keeping tests green

### Key Implementation Details

**Conditional Field Visibility**: Uses `unclecheese/display-logic` module. **Important**: OptionsetFields must be wrapped with `Wrapper::create()` for display logic to work:
```php
// Checkbox - display logic directly on field
CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
    ->displayIf('CacheDuration')->isEqualTo('maxage')
        ->andIf('EnableCacheControl')->isChecked();

// Optionset - must use Wrapper
$cacheTypeField = OptionsetField::create('CacheType', 'Cache Type', [...]);
$wrapper = Wrapper::create($cacheTypeField);
$wrapper->displayIf('EnableCacheControl')->isChecked()->end();
```

**Performance Optimization**:
- Middleware checks page override flag first to avoid unnecessary SiteConfig lookups
- All settings stored as database fields (no complex queries)
- Respects existing Cache-Control headers (doesn't override)
- Gracefully handles non-page requests

**Extension Registration** (in `_config/config.yml`):
```yaml
---
Name: edwilde-cache-controls
After:
  - '#nswdpc-cache-headers'  # Must load after nswdpc module
---
# Apply extensions for CMS configuration UI
SilverStripe\SiteConfig\SiteConfig:
  extensions:
    - Edwilde\CacheControls\Extensions\CacheControlSiteConfigExtension

SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Edwilde\CacheControls\Extensions\CacheControlPageExtension

# Apply controller extension to set cache headers via nswdpc middleware
SilverStripe\CMS\Controllers\ContentController:
  extensions:
    - Edwilde\CacheControls\Extensions\CacheControlContentControllerExtension
```

### Common Development Tasks

**Adding a New Cache Directive**:

1. Add database field to both extensions:
   ```php
   'EnableNewDirective' => 'Boolean'
   ```

2. Add CMS field with DisplayLogic in `updateCMSFields()`:
   ```php
   CheckboxField::create('EnableNewDirective', 'Enable New Directive')
       ->setDescription('Description for editors')
       ->displayIf('EnableCacheControl')->isChecked()->end()
   ```

3. Update `CacheControlContentControllerExtension` to apply the directive via nswdpc middleware:
   ```php
   if ($config->EnableNewDirective) {
       $middleware->setNewDirective(true);
   }
   ```

4. Add integration tests in `tests/Extensions/` to verify behavior

5. Run dev/build: `vendor/bin/sake dev/build flush=1`

**Debugging Cache Headers**:

Use browser DevTools Network tab to inspect response headers, or:
```bash
curl -I http://yoursite.com/page
```

### Code Style Guidelines

- Follow PSR-4 autoloading standards
- Use SilverStripe 5 conventions
- **Use `DataExtension` for extensions that add database fields** to DataObjects (SiteTree, SiteConfig, etc.)
- Use `Extension` only for extensions that don't need database fields (e.g., ContentController extensions)
- **Note**: `DataExtension` is NOT deprecated (see [GitHub Issue #11050](https://github.com/silverstripe/silverstripe-framework/issues/11050))
- Add clear descriptions for all CMS fields (for non-technical users)
- Keep methods focused (single responsibility)
- Use early returns to reduce nesting
- Prefer composition over inheritance
- **CRITICAL: NEVER use `cat`, `echo >`, or shell redirection to create or edit files** - always use proper file creation tools/APIs/editors
- All file operations must use the `create` tool or proper programming language APIs

**Documentation Requirements** (IMPORTANT):
- **All classes must have a masthead** explaining purpose, features, and usage
- **All public and protected methods must have PHPDoc blocks** including:
  - Description of what the method does
  - `@param` tags for all parameters with types and descriptions
  - `@return` tag with type and description
  - `@throws` tags if applicable
- **Add inline comments** for complex logic or non-obvious code
- **Explain display logic patterns** when using conditional field visibility
- **Code must be readable by humans** - prioritize clarity over brevity
- Use descriptive variable names
- Break complex expressions into well-named intermediate variables

### Git Workflow

Commits follow Conventional Commits format:
- `feat:` - New features
- `fix:` - Bug fixes
- `test:` - Adding/updating tests
- `docs:` - Documentation changes
- `chore:` - Maintenance tasks
- `ci:` - CI/CD changes

Example:
```bash
git commit -m "feat: add s-maxage support for CDN caching"
```

## Deployment

### Installation in SilverStripe Project

```bash
# Via Composer
composer require edwilde/silverstripe-cache-controls

# Run dev/build
vendor/bin/sake dev/build flush=1
```

### Configuration

Access via CMS:
1. Navigate to **Settings > Cache Control**
2. Enable cache control
3. Choose cache type (public/private)
4. Select cache duration (max-age or no-store)
5. If using max-age, select a preset duration or choose custom
6. Configure must-revalidate option (recommended to keep enabled)
7. Override per-page in **Page > Cache Control** tab

### Production Considerations

- **Test cache behavior** in staging before production
- **Monitor CDN integration** if using public caching
- **Consider user-specific content** - use `private` for personalized pages
- **Coordinate with CDN configuration** - ensure settings align
- **Plan cache invalidation strategy** when content changes

### Versioning & Releases

Following Semantic Versioning (semver):
- **MAJOR**: Breaking changes
- **MINOR**: New features (backwards compatible)
- **PATCH**: Bug fixes

Current version: `1.0.0`

## CI/CD

### GitHub Actions

Workflow: `.github/workflows/ci.yml`
- Uses `silverstripe/gha-ci@v2`
- Runs on: push, pull requests, manual dispatch
- Tests across multiple PHP and SilverStripe versions
- Prevents duplicate runs on same-repo PRs

### Running CI Locally

```bash
# Run tests locally before pushing
composer validate
vendor/bin/phpunit --testdox
php -l src/**/*.php  # Check syntax
```

## Troubleshooting

### Common Issues

**Cache not being applied**:
- Check `EnableCacheControl` is enabled in Settings
- Verify middleware is registered (check `_config/config.yml`)
- Confirm no existing Cache-Control header is set elsewhere
- Use browser DevTools to inspect response headers

**Fields not appearing in CMS**:
- Run `dev/build?flush=1`
- Check extensions are registered in `_config/config.yml`
- Verify DisplayLogic module is installed

**Tests failing**:
- Ensure you're in a SilverStripe project for integration tests
- Unit tests should work standalone
- Check database configuration for integration tests

**Page override not working**:
- Verify `OverrideCacheControl` checkbox is enabled
- Page must be published for changes to take effect
- Check middleware is retrieving correct page instance

## Contributing

### Pull Request Process

1. Fork the repository
2. Create a feature branch: `git checkout -b feat/my-feature`
3. Write tests first (TDD approach)
4. Implement feature
5. Ensure all tests pass
6. Update documentation if needed
7. Submit PR with clear description

### Code Review Checklist

- [ ] Tests added/updated
- [ ] All tests passing
- [ ] Code follows style guidelines
- [ ] Documentation updated
- [ ] Commit messages follow conventions
- [ ] No breaking changes (or clearly documented)

## Resources

### Documentation
- [SilverStripe 5 Documentation](https://docs.silverstripe.org/en/5/)
- [HTTP Cache-Control Header Spec](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control)
- [DisplayLogic Module](https://github.com/unclecheese/silverstripe-display-logic)

### Related SilverStripe Concepts
- [DataExtensions](https://docs.silverstripe.org/en/5/developer_guides/extending/extensions/)
- [HTTP Middleware](https://docs.silverstripe.org/en/5/developer_guides/controllers/middlewares/)
- [SiteConfig](https://docs.silverstripe.org/en/5/developer_guides/configuration/siteconfig/)

### Cache Control Guide
- **public**: Cacheable by browsers and CDNs
- **private**: Only cacheable by browser (not CDN)
- **max-age**: Seconds before cache expires
- **must-revalidate**: Force validation when expired
- **no-store**: Don't cache at all (sensitive data)

## License

BSD-3-Clause

## Maintainer

Ed Wilde <ed@example.com>

---

*This Agents.md file is designed to help AI agents and developers understand, maintain, and extend the SilverStripe Cache Controls module.*
