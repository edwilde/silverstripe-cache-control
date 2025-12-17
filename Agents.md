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
- **Dependencies**: unclecheese/display-logic ^3.0
- **Testing**: PHPUnit 9.5+

## Architecture

### Component Structure

```
src/
├── Extensions/
│   ├── CacheControlSiteConfigExtension.php  # Site-wide settings
│   └── CacheControlPageExtension.php        # Page-level overrides
├── Middleware/
│   └── CacheControlMiddleware.php           # Applies headers to responses
└── Traits/
    └── CacheControlTrait.php                # Shared header generation logic
```

### Design Patterns

**DataExtension Pattern**: Used for `CacheControlSiteConfigExtension` and `CacheControlPageExtension` to add cache control functionality to existing SilverStripe models (SiteConfig and SiteTree).

**Middleware Pattern**: `CacheControlMiddleware` intercepts HTTP requests/responses to apply appropriate Cache-Control headers automatically.

**Trait Pattern**: `CacheControlTrait` contains the core business logic for building cache control headers, promoting DRY (Don't Repeat Yourself) principles.

**Strategy Pattern**: The module checks if a page has an override enabled, then decides whether to use page-specific settings or fall back to site-wide defaults.

### Data Flow

1. **Request comes in** → Middleware intercepts
2. **Middleware gets current page** from ContentController
3. **Check for page override**:
   - If `OverrideCacheControl` is enabled → Use page settings
   - Otherwise → Use site-wide SiteConfig settings
4. **Generate header** via `CacheControlTrait::buildCacheControlHeader()`
5. **Apply header** to HTTP response (unless already set)

### Database Schema

**SiteConfig Table Extensions**:
```php
'EnableCacheControl' => 'Boolean'         // Master switch (default: false)
'CacheType' => 'Enum("public,private")'   // Cache visibility (default: public)
'EnableMaxAge' => 'Boolean'               // Max-age toggle (default: false)
'MaxAge' => 'Int'                         // Cache duration in seconds (default: 120)
'EnableMustRevalidate' => 'Boolean'       // Force revalidation (default: false)
'EnableNoStore' => 'Boolean'              // Prevent caching (default: false)
```

**SiteTree Table Extensions** (same fields as above plus):
```php
'OverrideCacheControl' => 'Boolean'       // Enable page-specific override (default: false)
```

### Cache Header Generation Logic

Located in `CacheControlTrait::buildCacheControlHeader()`:

1. **Early return** if `EnableCacheControl` is false → returns `null`
2. **Build directives array**:
   - Add `no-store` if enabled
   - Add cache type (`public` or `private`)
   - Add `max-age={value}` if enabled AND `no-store` is not set
   - Add `must-revalidate` if enabled
3. **Join directives** with `, ` and return as string

**Example outputs**:
- `"public, max-age=120"`
- `"private, max-age=3600, must-revalidate"`
- `"no-store"` (ignores max-age)

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

### Testing Strategy

**Unit Tests** (8 tests, all passing):
- Located in `tests/Unit/CacheControlTraitTest.php`
- Test core header generation logic
- No database required
- Fast and reliable
- Use anonymous classes to mock objects with the trait

**Integration Tests**:
- Located in `tests/Extensions/` and `tests/Middleware/`
- Test full SilverStripe integration
- Require database and SilverStripe environment
- Test field addition, override logic, and middleware behavior

**Running Tests**:
```bash
# Unit tests only (no database needed)
vendor/bin/phpunit tests/Unit/ --testdox

# All tests (requires full SilverStripe environment)
vendor/bin/phpunit --testdox
```

### Key Implementation Details

**Conditional Field Visibility**: Uses `unclecheese/display-logic` module:
```php
CheckboxField::create('EnableMaxAge', 'Enable Max Age')
    ->displayIf('EnableCacheControl')->isChecked()
        ->andIf('EnableNoStore')->isNotChecked()
    ->end()
```

**Performance Optimization**:
- Middleware checks page override flag first to avoid unnecessary SiteConfig lookups
- All settings stored as database fields (no complex queries)
- Respects existing Cache-Control headers (doesn't override)
- Gracefully handles non-page requests

**Middleware Registration** (in `_config/config.yml`):
```yaml
SilverStripe\Core\Injector\Injector:
  SilverStripe\Control\Director:
    properties:
      Middlewares:
        CacheControlMiddleware: '%$Edwilde\CacheControls\Middleware\CacheControlMiddleware'
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

3. Update `CacheControlTrait::buildCacheControlHeader()`:
   ```php
   if ($this->EnableNewDirective) {
       $directives[] = 'new-directive';
   }
   ```

4. Add unit tests in `tests/Unit/CacheControlTraitTest.php`

5. Run dev/build: `vendor/bin/sake dev/build flush=1`

**Debugging Cache Headers**:

Use browser DevTools Network tab to inspect response headers, or:
```bash
curl -I http://yoursite.com/page
```

### Code Style Guidelines

- Follow PSR-4 autoloading standards
- Use SilverStripe 5 conventions
- Add clear descriptions for all CMS fields (for non-technical users)
- Keep methods focused (single responsibility)
- Use early returns to reduce nesting
- Prefer composition over inheritance

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
3. Configure site-wide defaults
4. Override per-page in **Page > Cache Control** tab

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
