@README.md

## CI checks

Before pushing, run `composer preflight` and fix any failures. It needs MediaWiki's autoloader, so run
it from a clone inside a MediaWiki installation's `extensions/` directory — see
[Development](README.md#development).

## Schema changes

The schema is running on installs, so every table change needs both parts:

* Fresh installs: the regenerated table definition in `sql/`.
* Existing installs: an abstract schema change registered in `SchemaChangesHandler`, since
  `addExtensionTable` skips them.

Both are described under [Development](README.md#development). Prove the second part by running
`update.php` on an install that already has the table.
