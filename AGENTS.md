@README.md

## CI checks

Before pushing, run all checks and fix any failures.

They need the MediaWiki autoloader, so run them in the MediaWiki container, from the prowiki-docker root:

```sh
docker compose exec -T mediawiki bash -c 'cd mw43/extensions/MemberAccess && PRO_DOMAIN=premium.wiki.localhost composer preflight'
```

The extension is loaded on `premium.wiki.localhost` and `all.wiki.localhost` only.

## Schema changes

The schema has never shipped, so change a table definition in place rather than through a patch file.
`addExtensionTable` leaves installs that already have the table untouched, so after regenerating the SQL:

* drop the extension's tables and re-run `update.php` on every wiki that has them;
* bump the MediaWiki cache key in `.github/workflows/ci.yml`, which otherwise restores an install
  carrying the old table.
