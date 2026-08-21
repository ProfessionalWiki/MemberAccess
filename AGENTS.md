@README.md

## CI checks

Before pushing, run `composer preflight` and fix any failures. It needs MediaWiki's autoloader, so run
it from a clone inside a MediaWiki installation's `extensions/` directory — see
[Development](README.md#development).

## Schema changes

The schema has never shipped, so change a table definition in place rather than through a patch file.
`addExtensionTable` leaves installs that already have the table untouched, so after regenerating the SQL:

* drop the extension's tables and re-run `update.php` on every wiki that has them;
* bump the MediaWiki cache key in `.github/workflows/ci.yml`, which otherwise restores an install
  carrying the old table.
