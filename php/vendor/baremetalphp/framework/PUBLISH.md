# Publishing to Packagist

This directory contains the framework library ready to be published to Packagist.

## Structure

```
framework-library/
├── src/              # Framework source code
├── tests/            # Test suite
├── mini              # CLI binary
├── composer.json     # Composer configuration
├── phpunit.xml       # PHPUnit configuration
├── LICENSE           # MIT License
├── README.md         # Framework documentation
├── .gitignore        # Git ignore rules
└── .gitattributes    # Git attributes
```

## Publishing Steps

1. **Create a new Git repository** for the framework library:

```bash
cd framework-library
git init
git add .
git commit -m "Initial commit"
```

2. **Push to GitHub/GitLab**:

```bash
git remote add origin https://github.com/elliotanderson/phpframework.git
git branch -M main
git push -u origin main
```

3. **Register on Packagist**:

   - Go to https://packagist.org/packages/submit
   - Submit your repository URL: `https://github.com/elliotanderson/phpframework`
   - Packagist will automatically detect the `composer.json` and create the package

4. **Update the skeleton package**:

   Make sure `baremetal-skeleton/composer.json` requires the correct version:

```json
{
    "require": {
        "elliotanderson/phpframework": "^1.0"
    }
}
```

5. **Auto-update on Packagist** (optional):

   - Install a Packagist webhook in your GitHub repository settings
   - This will automatically update the package when you push changes

## Versioning

Use semantic versioning:

- **Major** (1.0.0): Breaking changes
- **Minor** (1.1.0): New features, backward compatible
- **Patch** (1.0.1): Bug fixes, backward compatible

Tag releases in Git:

```bash
git tag -a v1.0.0 -m "Release version 1.0.0"
git push origin v1.0.0
```

## Local Testing

Before publishing, test that the package works correctly:

```bash
# In a test directory
composer init
composer require elliotanderson/phpframework:dev-main --repository='{"type":"path","url":"/path/to/framework/framework-library"}'
```

## Notes

- The `composer.json` in this directory is configured as a **library** (not a project)
- It does NOT include application-specific files (app/, routes/, config/, etc.)
- The skeleton package (`baremetal-skeleton`) is a separate package that depends on this one
- Users will typically install the skeleton package, not this library directly

