# Installation Guide

## Standard Installation (Recommended)

Install via Composer:

```bash
composer require edwilde/silverstripe-cache-controls
```

Then run:

```bash
vendor/bin/sake dev/build flush=1
```

## Development Installation (with local changes)

If you're developing the module and want to use a local copy:

### Composer Path Repository (Recommended)

1. Add the path repository to your project's `composer.json`:

```bash
cd ~/Sites/nzta-ap
composer config repositories.silverstripe-cache-controls path ~/Sites/modules/silverstripe-cache-controls
```

2. Require the module:

```bash
composer require edwilde/silverstripe-cache-controls:@dev
```

3. Run dev/build:

```bash
vendor/bin/sake dev/build flush=1
```

This will:
- Create a symlink in `vendor/edwilde/silverstripe-cache-controls`
- Automatically install dependencies (like `unclecheese/display-logic`)
- Keep your local changes

### What NOT to Do

❌ **Don't create a direct symlink in project root**
- Dependencies won't be installed automatically  
- Can cause class conflicts
- Module won't work without `unclecheese/display-logic`

## Troubleshooting

### "displayIf() method does not exist" Error

This means `unclecheese/display-logic` isn't installed.

**Solution**: Use the Composer path repository method above, which automatically installs dependencies.

### Duplicate Class Errors

If you see errors about duplicate classes, you likely have both:
- A symlink to the module
- The module installed via Composer

**Solution**: Remove the symlink and use only Composer to manage the module:

```bash
cd ~/Sites/nzta-ap
rm silverstripe-cache-controls  # Remove symlink
composer update edwilde/silverstripe-cache-controls
```

### Module Not Showing in CMS

After installation, always run:

```bash
vendor/bin/sake dev/build flush=1
```

This creates the database fields and flushes caches.
