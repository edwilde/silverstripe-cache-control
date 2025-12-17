# GitHub Copilot Instructions

## Primary Reference

**Always refer to `/Agents.md` first** when working on this project. It contains:
- Complete project architecture and design patterns
- Development guidelines and code standards
- Testing strategy and examples
- Common implementation patterns
- Troubleshooting guides

## Project Context

This is a **SilverStripe CMS 5 module** for managing HTTP Cache-Control headers. Key points:

- **Target**: SilverStripe 5.0+, PHP 8.1+
- **Architecture**: DataExtensions + Middleware + Shared Trait
- **Testing**: TDD approach, unit tests must pass
- **Code Style**: Follow SilverStripe conventions and PSR-4

## Code Standards

### Follow These Patterns

1. **DataExtensions** for adding functionality to existing classes
2. **Traits** for shared business logic (DRY principle)
3. **Middleware** for HTTP request/response interception
4. **DisplayLogic** for conditional CMS field visibility

### Naming Conventions

- Extensions: `*Extension.php` (e.g., `CacheControlPageExtension`)
- Traits: `*Trait.php` (e.g., `CacheControlTrait`)
- Tests: `*Test.php` (e.g., `CacheControlTraitTest`)
- Namespace: `Edwilde\CacheControls\*`

### CMS Fields

When adding CMS fields:
- Always include clear descriptions for non-technical editors
- Use DisplayLogic for conditional visibility
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
- Unit tests go in `tests/Unit/`
- Integration tests go in `tests/Extensions/` or `tests/Middleware/`
- All tests must pass before committing
- Aim for clear, descriptive test method names

### Performance Considerations

- Minimize database queries (check for N+1 issues)
- Use early returns to avoid unnecessary processing
- Cache expensive operations where appropriate
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

1. Add DB field to both extensions (`CacheControlSiteConfigExtension` and `CacheControlPageExtension`)
2. Add CMS field with DisplayLogic in `updateCMSFields()`
3. Update `CacheControlTrait::buildCacheControlHeader()` logic
4. Add unit tests in `tests/Unit/CacheControlTraitTest.php`
5. Update `Agents.md` if architecture changes

### Debugging

- Check response headers with browser DevTools
- Verify middleware is registered in `_config/config.yml`
- Run `dev/build?flush=1` after code changes
- Check that extensions are properly applied

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
1. Check if page has `OverrideCacheControl` enabled
2. If yes, use page settings
3. If no, use SiteConfig settings
4. Never override existing Cache-Control headers

### Field Visibility Logic
- Fields only visible when relevant
- Use DisplayLogic chaining: `.displayIf().andIf().end()`
- Hide max-age fields when no-store is enabled
- Show override fields only when override checkbox is checked

### Defaults
- Cache control disabled by default
- Max-age default: 120 seconds
- Cache type default: "public"
- All toggles default: false/unchecked

## Documentation Updates

When making changes:
- Update `README.md` for user-facing features
- Update `Agents.md` for architectural changes
- Keep code comments minimal but clear
- Document complex logic inline
- Update version number following semver

## Resources

Refer to these when needed:
- SilverStripe 5 Docs: https://docs.silverstripe.org/en/5/
- Cache-Control Spec: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
- DisplayLogic: https://github.com/unclecheese/silverstripe-display-logic

---

**Remember**: Check `/Agents.md` for detailed architecture, patterns, and implementation examples before starting any task.
