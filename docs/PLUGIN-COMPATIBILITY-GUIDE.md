# Alynt Plugin Updater - Plugin Compatibility Guide

This guide explains how to make any WordPress plugin compatible with the Alynt Plugin Updater for automatic updates via GitHub releases.

---

## Quick Checklist

- [ ] Add `GitHub Plugin URI` header to main plugin file
- [ ] Create `.github/workflows/build-release.yml` workflow
- [ ] Ensure version is updated in plugin header before each release
- [ ] Create GitHub release with proper tag (e.g., `v1.0.0`)

---

## Step 1: Add GitHub Plugin URI Header

Add this line to your main plugin file's header comment block:

```php
<?php
/**
 * Plugin Name: Your Plugin Name
 * Description: Your plugin description.
 * Version: 1.0.0
 * Author: Your Name
 * GitHub Plugin URI: YourGitHubUsername/your-plugin-repo
 */
```

**Important:** The `GitHub Plugin URI` value must match your GitHub repository path exactly (case-sensitive).

---

## Step 2: Create GitHub Actions Workflow

Create the file `.github/workflows/build-release.yml` in your plugin repository:

```yaml
name: Build Release

on:
  release:
    types: [published]

permissions:
  contents: write

jobs:
  build:
    runs-on: ubuntu-latest
    
    steps:
      - name: Checkout code
        uses: actions/checkout@v5

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          tools: composer:v2

      - name: Setup Node.js
        uses: actions/setup-node@v5
        with:
          node-version: '20'

      - name: Install Composer dependencies (production)
        run: composer install --no-dev --optimize-autoloader --no-interaction

      - name: Check for package.json and install npm dependencies
        run: |
          if [ -f "package.json" ]; then
            npm install
            npm run build || echo "No build script, skipping"
          else
            echo "No package.json found, skipping npm steps"
          fi

      - name: Create plugin zip
        id: create_zip
        run: |
          # CHANGE THIS to your plugin's folder name
          PLUGIN_SLUG="your-plugin-slug"
          VERSION="${{ github.event.release.tag_name }}"
          
          # Create build directory
          mkdir -p build/${PLUGIN_SLUG}
          
          # Copy plugin files (exclude dev files)
          rsync -av --exclude='.git' \
                    --exclude='.github' \
                    --exclude='node_modules' \
                    --exclude='tests' \
                    --exclude='scripts' \
                    --exclude='.gitignore' \
                    --exclude='.gitattributes' \
                    --exclude='.phpcs.xml' \
                    --exclude='.eslintrc.json' \
                    --exclude='phpunit.xml' \
                    --exclude='phpunit.xml.dist' \
                    --exclude='composer.json' \
                    --exclude='composer.lock' \
                    --exclude='package.json' \
                    --exclude='package-lock.json' \
                    --exclude='*.map' \
                    --exclude='RELEASE-*.md' \
                    ./ build/${PLUGIN_SLUG}/
          
          # Create zip
          cd build
          zip -r ${PLUGIN_SLUG}-${VERSION}.zip ${PLUGIN_SLUG}
          
          echo "zip_file=${PLUGIN_SLUG}-${VERSION}.zip" >> $GITHUB_OUTPUT

      - name: Upload release asset
        uses: softprops/action-gh-release@v2
        with:
          files: build/${{ steps.create_zip.outputs.zip_file }}
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

**⚠️ Important:** Change `PLUGIN_SLUG="your-plugin-slug"` to match your plugin's folder name exactly.

---

## Step 3: Version Management

**Before creating each release:**

1. Update the `Version:` in your main plugin file header
2. Update any version constants (e.g., `define('MY_PLUGIN_VERSION', '1.0.1');`)
3. Commit and push these changes
4. Create the release with a matching tag

**Example version bump:**
```php
// Before
 * Version: 1.0.0
define( 'MY_PLUGIN_VERSION', '1.0.0' );

// After
 * Version: 1.0.1
define( 'MY_PLUGIN_VERSION', '1.0.1' );
```

---

## Step 4: Creating a Release

### Via GitHub Web Interface:

1. Go to your repository → **Releases** → **Create a new release**
2. Click **Choose a tag** → type `v1.0.1` → **Create new tag**
3. Set **Release title** (e.g., `v1.0.1 - Bug Fixes`)
4. Add release notes
5. Click **Publish release**

### Via Command Line:

```bash
# After committing version changes
git tag v1.0.1
git push origin v1.0.1
# Then create the release on GitHub web interface
```

### Via GitHub CLI (Fully Automated):

If the GitHub CLI (`gh`) is installed and authenticated, you can create and publish the release directly from the command line without using the web interface:

```bash
# After committing version changes
git tag -a v1.0.1 -m "Release v1.0.1"
git push origin v1.0.1

# Create and publish the release in one step
gh release create v1.0.1 \
  --title "v1.0.1 - Brief title here" \
  --notes "Release notes here."
```

This triggers the `Build Release` workflow immediately (since it fires on `release: published`), which builds and attaches the plugin `.zip` asset automatically.

Install the GitHub CLI: https://cli.github.com

---

## How It Works

1. **Plugin Detection:** The Alynt Plugin Updater scans all installed plugins for the `GitHub Plugin URI` header
2. **Version Check:** It queries the GitHub API for the latest release
3. **Download:** When updating, it downloads the `.zip` asset attached to the release (built by GitHub Actions)
4. **Install:** WordPress extracts and installs the update, preserving the correct folder name

---

## Troubleshooting

### Update shows available but version doesn't change
- **Cause:** Version wasn't updated in source code before creating release
- **Fix:** Update version in plugin file, commit, push, delete old release/tag, create new release

### "Could not move old version to temp-backup directory"
- **Cause:** File locking on Windows/LocalWP
- **Fix:** Deactivate the plugin before updating, or restart LocalWP

### Plugin folder gets renamed after update
- **Cause:** Using zipball instead of release asset
- **Fix:** Ensure GitHub Actions workflow is creating and attaching `.zip` asset

### GitHub API rate limit
- **Cause:** Too many requests (60/hour for unauthenticated)
- **Fix:** Wait for reset, or the plugin will use cached data

---

## Workflow Customization

### If your plugin doesn't use Composer:
Remove or comment out the Composer step:
```yaml
# - name: Install Composer dependencies (production)
#   run: composer install --no-dev --optimize-autoloader --no-interaction
```

### If your plugin doesn't use npm:
The workflow already handles this - it checks for `package.json` before running npm commands.

### Additional files to exclude:
Add more `--exclude` patterns to the rsync command as needed:
```yaml
--exclude='docs' \
--exclude='*.md' \
--exclude='screenshots' \
```

### Files to always include:
- `readme.txt` (for WordPress.org compatibility)
- `README.md` (optional, for GitHub)
- `uninstall.php` (cleanup on uninstall)
- `vendor/` (if using Composer - built by workflow)

---

## Example: Complete Implementation

For a plugin called `my-awesome-plugin` at `NichlasB/my-awesome-plugin`:

**1. Main plugin file (`my-awesome-plugin.php`):**
```php
<?php
/**
 * Plugin Name: My Awesome Plugin
 * Description: Does awesome things.
 * Version: 1.0.0
 * Author: Alynt
 * GitHub Plugin URI: NichlasB/my-awesome-plugin
 */

define( 'MY_AWESOME_PLUGIN_VERSION', '1.0.0' );
```

**2. Workflow file (`.github/workflows/build-release.yml`):**
- Copy the template above
- Change `PLUGIN_SLUG="my-awesome-plugin"`

**3. Create release:**
- Tag: `v1.0.0`
- Wait for workflow to complete
- Verify `.zip` asset is attached

---

## Quick Commands Reference

```bash
# Update version, commit, and create release
git add my-plugin.php
git commit -m "Bump version to 1.0.1"
git push
git tag v1.0.1
git push origin v1.0.1

# If you need to redo a release (delete and recreate)
git push origin --delete v1.0.1
git tag -d v1.0.1
git tag v1.0.1
git push origin v1.0.1
```

---

## Support

The Alynt Plugin Updater is located at:
- Repository: `NichlasB/alynt-plugin-updater`
- Settings: WordPress Admin → Settings → Plugin Updater
