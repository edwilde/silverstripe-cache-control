# Agents.md

## Project Overview

**SilverStripe Cache Control** is a SilverStripe CMS 5/6 module that provides content editors with control over HTTP Cache-Control headers. It enables cache management at both site-wide and page-specific levels through an intuitive CMS interface.

### Branch Workflow
- **`main`** branch targets **CMS 6** (PHP 8.3+, PHPUnit 11)
- **`cms5`** branch targets **CMS 5** (PHP 8.1+, PHPUnit 9.5)

### Key Features
- Site-wide default cache control settings via SiteConfig
- Page-level cache control overrides
- User-friendly CMS interface with clear explanations for non-technical editors
- Automatic HTTP header application via middleware
- Public/Private cache type selection (mutually exclusive)
- Max-age control with sensible 120-second default
- Must-revalidate and no-store directives
- Optional cache inheritance: a page's settings can apply to all descendants (`enable_cache_inheritance`, off by default)
- Draft cache reduction: pages with unpublished changes get a short max-age until published
- Conditional field visibility using DisplayLogic
- Performance-optimised with minimal database queries

### Technology Stack
- **PHP**: 8.3+
- **SilverStripe Framework**: 6.0+
- **SilverStripe CMS**: 6.0+
- **Dependencies**:
  - unclecheese/display-logic ^4.0 (conditional CMS field visibility)
  - nswdpc/silverstripe-cache-headers ^2.0.0 (robust cache header middleware, CMS 6 compatible)
- **Testing**: PHPUnit 11.3+

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

**Extension Pattern**: All extensions (`CacheControlSiteConfigExtension`, `CacheControlPageExtension`, `CacheControlContentControllerExtension`, and `DevCacheBypassExtension`) use `SilverStripe\Core\Extension`. In SilverStripe 6, `DataExtension` has been removed — `Extension` now handles both data-bearing and non-data extensions.

**Middleware Integration**: Uses `nswdpc/silverstripe-cache-headers` module for robust HTTP cache header management, including form detection, error page handling, and respecting existing headers.

**Strategy Pattern**: The module checks if a page has an override enabled, then decides whether to use page-specific settings or fall back to site-wide defaults.

### Data Flow

1. **Request comes in** → nswdpc middleware is registered to intercept
2. **ContentController initializes** → `CacheControlContentControllerExtension::onAfterInit()` is triggered
3. **Resolve the effective settings source**:
   - If `OverrideCacheControl` is enabled → Use page settings (via `applyPageSettings()`)
   - Else if `enable_cache_inheritance` is on and `findInheritedCacheSource()` returns an ancestor with `ApplyCacheToChildren` → Use that ancestor's settings (via `applyPageSettings($ancestor)`)
   - Otherwise → Use site-wide SiteConfig settings (via `applySiteSettings()`)
4. **Apply settings** to `HTTPCacheControlMiddleware` singleton:
   - Set cache state (public/private or disabled)
   - Set cache duration (max-age or no-store)
   - Add Expires header to match max-age
   - Apply Vary headers based on CMS configuration (always read from SiteConfig)
5. **Draft cache reduction** (`applyDraftCacheReduction()`): if `EnableDraftCacheReduction` is on and the page's `HasPendingDraftChanges` flag is set, `max-age` and `Expires` drop to `draft_cache_max_age` (default 10s). The flag is written on save and cleared on publish, so this costs no extra query.
6. **Middleware processes response** → nswdpc module applies configured headers, respecting:
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
'EnableMustRevalidate' => 'Boolean'                    // Force revalidation (default: true; dropped when a grace period is set)
'StaleWhileRevalidatePreset' => 'Enum(...,"0")'        // Refresh grace period: 0 (off), 300, 3600, 21600, 86400, 604800, custom
'StaleWhileRevalidate' => 'Int'                        // Custom refresh grace period in seconds (default: 0)
'StaleIfErrorPreset' => 'Enum(...,"0")'                // Error grace period: 0 (off), 3600, 86400, 604800, 2592000, custom
'StaleIfError' => 'Int'                                // Custom error grace period in seconds (default: 0)
'VaryAcceptEncoding' => 'Boolean'                      // Vary: Accept-Encoding (default: true)
'VaryXForwardedProtocol' => 'Boolean'                  // Vary: X-Forwarded-Protocol (default: false)
'VaryCookie' => 'Boolean'                              // Vary: Cookie (default: false)
'VaryAuthorization' => 'Boolean'                       // Vary: Authorization (default: false)
'EnableDraftCacheReduction' => 'Boolean'               // Shorten max-age on pages with unpublished changes (default: true)
```

**SiteTree Table Extensions** (cache type/duration/max-age/must-revalidate/grace-period fields as SiteConfig, plus):
```php
'OverrideCacheControl' => 'Boolean'                    // Enable page-specific override (default: false)
'ApplyCacheToChildren' => 'Boolean'                    // Descendants inherit this page's settings (default: false; needs enable_cache_inheritance)
'HasPendingDraftChanges' => 'Boolean'                  // Set on save, cleared on publish; drives draft cache reduction
```

Note: Page-level extensions don't include Vary headers - those are site-wide only.

**YAML config** (on `SilverStripe\CMS\Model\SiteTree`): `enable_cache_inheritance` (default `false`) and `draft_cache_max_age` (default `10`).

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

**Directive rules worth knowing**:
- `HTTPCacheControlMiddleware::$allowed_directives` is a `@config` list. `setStateDirective()` throws for any name not in it, so a new directive (e.g. `stale-while-revalidate`, `stale-if-error`) must first be appended to that list in `_config/config.yml`. The framework's own docblock names this as the extension point.
- `must-revalidate` and the RFC 5861 stale directives are mutually exclusive in effect: `must-revalidate` forbids reusing a stale response without revalidation, so a header carrying both has no grace period. Whenever a stale directive is emitted, `must-revalidate` must be omitted, in both the controller emission and the CMS header preview.
- The middleware's built-in `stateDirectives` table sets `must-revalidate => true` on every cacheable state, so omitting it means calling `setMustRevalidate(false)`, not just declining to call `setMustRevalidate(true)`.
- `setStateDirective()` removes a directive only for the value `false`. An integer `0` is stored and rendered as `name=0`, so a grace period of zero must be passed as `false`.
- `Edwilde\CacheControl\StaleDirectives` resolves the preset/custom pairs for both grace directives and is the single source used by the SiteConfig preview, the Page preview and the controller. It also owns the custom-value range check (`validate()`, 1 second to `MAX_SECONDS`) the editor explainer (`infoField()`) and the private-cache notice (`privateNoticeField()`, shown only while the cache type is private) that both extensions share.

### HTTP Headers Applied

The module applies the following HTTP headers:

1. **Cache-Control**: The primary header controlling cache behaviour (HTTP/1.1+)
2. **Expires**: Set to match Cache-Control max-age for HTTP/1.0 compatibility
3. **Vary**: Tells caches which request headers affect the response, so separate entries are stored for different variations

**Expires Header**: When max-age is set, the Expires header is automatically calculated as `current_time + max-age` in GMT format. This ensures compatibility with older HTTP/1.0 caches and proxies. Per the HTTP specification, Cache-Control takes precedence over Expires in HTTP/1.1 clients, but both should be present for maximum compatibility.

**Vary Header**: Configured site-wide only (not per-page). Available options: `Accept-Encoding` (default: enabled), `X-Forwarded-Protocol`, `Cookie`, `Authorization`. Even when a page uses page-level cache control overrides, Vary headers are always read from SiteConfig. This ensures consistent cache variation behaviour across the entire site.

## Development Guide

### Project Setup

```bash
# Clone repository
git clone git@github.com:edwilde/silverstripe-cache-control.git
cd silverstripe-cache-control

# Install dependencies
composer install

# Run tests
vendor/bin/phpunit tests/ --testdox
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

**Integration Tests**:
- Located in `tests/Extensions/`
- Test full SilverStripe integration
- Require database and SilverStripe environment
- Test field addition, override logic, cache header generation, and middleware behaviour
- Cover all cache directive combinations:
  - Site-wide: public + max-age, private + max-age, no-store
  - Page override: all above combinations
  - Page override with cache disabled
  - Fallback to site config when override disabled
  - Must-revalidate combinations

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

**CMS field registration rules**:
- Call `removeByName()` on every scaffolded field before adding the custom replacement; CMS 6 rejects duplicate names in a `FieldList`.
- In `CacheControlPageExtension::updateCMSFields()`, every cache field is `setValue()`d explicitly from the effective source (own override, inheriting ancestor, or SiteConfig) so editors see the value the page actually uses before ticking override. New fields must join that block.

**Two header paths, kept in step**: `CacheControlContentControllerExtension` emits the real header via the middleware; `CacheControlSiteConfigExtension::getCacheControlHeader()` and `CacheControlPageExtension::getPageCacheControlHeader()` build the preview shown in the CMS. A directive change that touches one and not the other shows editors a header the site never sends.

**Performance Optimisation**:
- Middleware checks page override flag first to avoid unnecessary SiteConfig lookups
- All settings stored as database fields (no complex queries)
- Respects existing Cache-Control headers (doesn't override)
- Gracefully handles non-page requests

**Extension Registration** (in `_config/config.yml`):
```yaml
---
Name: edwilde-cache-control
After:
  - '#nswdpc-cache-headers'  # Must load after nswdpc module
---
# Apply extensions for CMS configuration UI
SilverStripe\SiteConfig\SiteConfig:
  extensions:
    - Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension

SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Edwilde\CacheControl\Extensions\CacheControlPageExtension
  enable_cache_inheritance: false
  draft_cache_max_age: 10

# Apply controller extensions to set cache headers via nswdpc middleware
SilverStripe\CMS\Controllers\ContentController:
  extensions:
    - Edwilde\CacheControl\Extensions\CacheControlContentControllerExtension
    - Edwilde\CacheControl\Extensions\DevCacheBypassExtension

# Clear the framework default Vary so the CMS checkboxes are the only source of Vary values
SilverStripe\Control\Middleware\HTTPCacheControlMiddleware:
  defaultVary:
    'X-Forwarded-Protocol': false
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

3. Update `CacheControlContentControllerExtension` to apply the directive in both `applyPageSettings()` and `applySiteSettings()`:
   ```php
   if ($config->EnableNewDirective) {
       $middleware->setNewDirective(true);
   }
   ```
   If the directive is not in `HTTPCacheControlMiddleware::$allowed_directives`, append it via YAML first; `setStateDirective()` throws for unknown names.

4. Update the editor-facing preview builders `CacheControlSiteConfigExtension::getCacheControlHeader()` and `CacheControlPageExtension::getPageCacheControlHeader()` so the CMS shows what is actually emitted

5. Add integration tests in `tests/Extensions/` to verify behaviour

6. Run dev/build: `vendor/bin/sake dev/build flush=1`

**Debugging Cache Headers**:

Use browser DevTools Network tab to inspect response headers, or:
```bash
curl -I http://yoursite.com/page
```

### Code Style Guidelines

- Follow PSR-4 autoloading standards
- Use SilverStripe 6 conventions
- **Use `Extension` for all extensions** — `DataExtension` was deprecated in SilverStripe 5.2 and removed in SilverStripe 6. `Extension` now handles both data-bearing and non-data extensions.
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
composer require edwilde/silverstripe-cache-control

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

- **Test cache behaviour** in staging before production
- **Monitor CDN integration** if using public caching
- **Consider user-specific content** - use `private` for personalised pages
- **Coordinate with CDN configuration** - ensure settings align
- **Plan cache invalidation strategy** when content changes

### Versioning & Releases

Following Semantic Versioning (semver):
- **MAJOR**: Breaking changes
- **MINOR**: New features (backwards compatible)
- **PATCH**: Bug fixes

Current version: see `git tag --sort=-v:refname | head -1` (2.x on `main`, 1.x on `cms5`).

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
- All tests are `SapphireTest` integration tests and need a Silverstripe environment with a database
- Controller tests reset `HTTPCacheControlMiddleware` to production-like defaults in `setUp()`; dev-mode config otherwise disables caching and masks failures

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
- [SilverStripe 6 Documentation](https://docs.silverstripe.org/en/6/)
- [HTTP Cache-Control Header Spec](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control)
- [DisplayLogic Module](https://github.com/unclecheese/silverstripe-display-logic)

### Related SilverStripe Concepts
- [Extensions](https://docs.silverstripe.org/en/6/developer_guides/extending/extensions/)
- [HTTP Middleware](https://docs.silverstripe.org/en/6/developer_guides/controllers/middlewares/)
- [SiteConfig](https://docs.silverstripe.org/en/6/developer_guides/configuration/siteconfig/)

### Cache Control Guide
- **public**: Cacheable by browsers and CDNs
- **private**: Only cacheable by browser (not CDN)
- **max-age**: Seconds before cache expires
- **must-revalidate**: Force validation when expired
- **no-store**: Don't cache at all (sensitive data)

## License

BSD-3-Clause

## Maintainer

Ed Wilde (https://github.com/edwilde)

---

*This Agents.md file is designed to help AI agents and developers understand, maintain, and extend the SilverStripe Cache Control module.*
