# GitHub Copilot Instructions

## Primary Reference

**Always refer to `/Agents.md` first** when working on this project. It contains:
- Complete project architecture and design patterns
- Development guidelines and code standards
- Testing strategy and examples
- Common implementation patterns
- Troubleshooting guides

## Project Context

This is a **Silverstripe CMS 6 module** for managing HTTP Cache-Control headers. Key points:

- **Target** (`main` branch): Silverstripe CMS 6.0+, PHP 8.3+, PHPUnit 11
- **Legacy** (`cms5` branch): Silverstripe CMS 5, PHP 8.1+, PHPUnit 9.5
- **Architecture**: `SilverStripe\Core\Extension` subclasses on SiteConfig, SiteTree and ContentController, applying settings to the framework's `HTTPCacheControlMiddleware` singleton (via `nswdpc/silverstripe-cache-headers`)
- **Testing**: TDD approach, integration tests in `tests/Extensions/` must pass
- **Code Style**: Follow Silverstripe conventions and PSR-4

## Code Standards

### Follow These Patterns

1. **`Extension`** for adding functionality to existing classes (`DataExtension` no longer exists in CMS 6)
2. **Middleware** for HTTP request/response interception (`HTTPCacheControlMiddleware::singleton()`)
3. **DisplayLogic** for conditional CMS field visibility
4. **Two header paths kept in step**: the controller extension emits the real header; `getCacheControlHeader()` on SiteConfig and `getPageCacheControlHeader()` on SiteTree build the preview shown to editors. Any directive change touches both.

### Naming Conventions

- Extensions: `*Extension.php` (e.g. `CacheControlPageExtension`)
- Tests: `*Test.php` alongside a `*Test.yml` fixture where needed (e.g. `CacheControlPageExtensionTest`)
- Namespace: `Edwilde\CacheControl\*` (tests: `Edwilde\CacheControl\Tests\*`)

### CMS Fields

When adding CMS fields:
- Always include clear descriptions for non-technical editors
- Use DisplayLogic for conditional visibility; wrap `OptionsetField` in `Wrapper::create()` or the logic will not fire
- Remove the scaffolded field in `removeByName()` before adding a custom one; CMS 6 rejects duplicate field names
- On the page extension, add the field to the explicit `setValue()` prefill block so editors see the effective inherited value before overriding
- Group related fields logically
- Provide helpful placeholder text
- Explain technical terms in plain language

Example:
```php
CheckboxField::create('EnableMaxAge', 'Enable Max Age')
    ->setDescription('Set how long (in seconds) browsers can cache this page before checking for updates.')
    ->displayIf('EnableCacheControl')->isChecked()->end()
```

### Testing Requirements

- **Write tests first** (TDD approach)
- All tests are `SapphireTest` integration tests in `tests/Extensions/`; there is no separate unit suite
- Controller tests reset `HTTPCacheControlMiddleware` to production-like defaults in `setUp()` (see `CacheControlContentControllerExtensionTest`)
- All tests must pass before committing: `vendor/bin/phpunit tests/Extensions/ --testdox`
- Aim for clear, descriptive test method names

### Performance Considerations

- Minimize database queries (check for N+1 issues); cache inheritance is opt-in for this reason
- Use early returns to avoid unnecessary processing
- Respect existing headers (don't override)

## Git Commit Messages

Follow Conventional Commits format:

- `feat:` - New features
- `fix:` - Bug fixes
- `test:` - Adding/updating tests
- `docs:` - Documentation changes
- `refactor:` - Code refactoring
- `chore:` - Maintenance tasks
- `ci:` - CI/CD changes

Example: `feat: add s-maxage support for CDN caching`

## Common Tasks

### Adding a New Cache Directive

1. If the directive is not in `HTTPCacheControlMiddleware::$allowed_directives`, append it via YAML in `_config/config.yml` first; `setStateDirective()` throws otherwise
2. Add DB field(s) and defaults to both `CacheControlSiteConfigExtension` and `CacheControlPageExtension`
3. Add CMS field with DisplayLogic in both `updateCMSFields()` methods (and the page prefill block)
4. Emit it in `CacheControlContentControllerExtension` from both `applyPageSettings()` and `applySiteSettings()`
5. Update the preview builders `getCacheControlHeader()` (SiteConfig) and `getPageCacheControlHeader()` (SiteTree)
6. Add integration tests in `tests/Extensions/`
7. Update `README.md`, and `Agents.md` if the architecture changes

### Debugging

- Check response headers with browser DevTools or `curl -sI`
- Set `CACHE_HEADERS_IN_DEV="true"` in `.env` to get cache headers in dev mode
- Verify extensions are registered in `_config/config.yml`
- Run `dev/build?flush=1` after code changes

## File Creation Guidelines

**NEVER use `cat` to create files.** Always use the `create` tool or appropriate file creation methods.

Examples of what NOT to do:
```bash
cat > file.php << 'EOF'  # ❌ WRONG
echo "content" > file.php  # ❌ WRONG
```

Correct approach:
- Use the `create` tool provided by the environment
- Use proper file creation APIs

## Module-Specific Guidelines

### Cache Header Priority
1. Page has `OverrideCacheControl` enabled → use page settings
2. Otherwise, if `enable_cache_inheritance` is on and an ancestor has `ApplyCacheToChildren` → use that ancestor's settings
3. Otherwise → use SiteConfig settings
4. After resolution, `applyDraftCacheReduction()` lowers `max-age` for pages with unpublished changes when `EnableDraftCacheReduction` is on
5. Never override existing Cache-Control headers

### Field Visibility Logic
- Fields only visible when relevant
- Use DisplayLogic chaining: `.displayIf().andIf().end()`
- Hide max-age fields when no-store is enabled
- Show override fields only when override checkbox is checked

### Defaults
- Cache control disabled by default
- Max-age default: 120 seconds
- Cache type default: "public"
- `EnableMustRevalidate`, `VaryAcceptEncoding` and `EnableDraftCacheReduction` default on; every other toggle defaults off

## Documentation Updates

When making changes:
- Update `README.md` for user-facing features
- Update `Agents.md` for architectural changes
- Keep code comments minimal but clear
- Document complex logic inline
- Update version number following semver

## Resources

Refer to these when needed:
- Silverstripe 6 Docs: https://docs.silverstripe.org/en/6/
- Cache-Control Spec: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
- DisplayLogic: https://github.com/unclecheese/silverstripe-display-logic

---

**Remember**: Check `/Agents.md` for detailed architecture, patterns, and implementation examples before starting any task.
