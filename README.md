# MemberAccess

MediaWiki extension for members-only wikis: readers log in with an email one-time code,
admitted by an allowlist of addresses and domains organized into named groups.

Created by [Professional Wiki](https://professional.wiki) and released under the [GNU GPL v2 or later](LICENSE).

* A member never has a password, and a login is remembered for about a month.
* Members can read and nothing else: everything that would let them change the wiki or see behind
  the scenes is revoked.
* Accounts create themselves at first login. Removing an allowlist entry ends access at the next
  login; deactivating a member blocks them at once.
* Single sign-on logins through [PluggableAuth](https://www.mediawiki.org/wiki/Extension:PluggableAuth)
  are held to the same allowlist; staff accounts are exempt.
* Nothing gives the member list away: code and password-reset requests answer the same for every
  address, and account listings and the logs that record members are restricted.
* Groups, allowlist entries and the member roster are managed over a REST API.
* It does not make the wiki private: restricting who may read stays a wiki configuration decision.

## How it works

### Login codes

A visitor asks for a login code by entering their email address in the login form's username field.
Whether one is sent depends on the allowlist, where each entry belongs to exactly one group. A code
is eight digits, valid for ten minutes and usable once. The response to a code request is the same
either way, so it never reveals who is on the list.

Entering the right code logs the visitor in, and the first time also creates their account: the
username is their email address, they are placed in the reader group, and the address is recorded as
confirmed. The allowlist is consulted again at that point, so removing an entry ends access at the
next login. A code never opens an account that was created some other way.

### Usernames

The username is the address lowercased and then put through MediaWiki's username rules: the first
letter is capitalized and underscores become spaces, so `John_Doe@Example.com` logs in as
`John doe@example.com`. Addresses that cannot become a username, and addresses whose username is
already taken by an account that is not that member, are refused.

### Passwords

A member never has a password: setting one is refused, and so is having a temporary one mailed by a
password reset. Both stay open to accounts that were not admitted through the allowlist. Asking for
a reset of a member's address answers exactly as it does for an address that was never admitted.

### Single sign-on

Single sign-on logins are held to the same allowlist. With
[PluggableAuth](https://www.mediawiki.org/wiki/Extension:PluggableAuth) configured, the address the
identity provider returns has to match an entry or the login is refused, and a first login that
matches is provisioned exactly like a code login. For an account that is already a member, the
address checked is the one recorded when they were admitted, so removing their entry ends this route
too. Accounts that are not members are exempt, so staff signing in through the identity provider are
unaffected; when such a login uses an address the allowlist would not admit, it is written to the log
channel. A refusal is final: no other handler of the same hook can hand the login back. Without
PluggableAuth the check never runs.

### Deactivation

Deactivating a member blocks their account sitewide and indefinitely; removing their allowlist entry
alone leaves the account and its open session intact. The block is an ordinary one, so it appears in
the block log and can be undone by hand. A deactivated member asking for a login code gets no mail,
and the same answer as an address that was never admitted. Reactivating lifts the block and leaves
the account otherwise as it was.

A block placed by hand, for some other reason, is neither replaced when the member is deactivated
nor lifted when they are reactivated. Deactivating is refused while such a block would not keep the
member out by itself, because it runs out or is only partial.

### Rate limits and logging

Code requests are rate limited per email address and per client IP, with both a burst and a daily
limit. Codes are stored hashed and are burned after five wrong entries. Every issue, success,
failure and rate-limit hit is logged through the `MemberAccess` log channel, with the email
address hashed.

### The roster

A member's username is their email address, so anything that names accounts names the roster. The
action API query modules whose purpose is enumerating accounts are closed to the reader group, and
two logs are closed to anyone who cannot manage members: the new user log, where every member's
account creation is recorded, and the block log, where every deactivation is. Restricting a log type
also keeps it out of recent changes. Hiding the matching special pages beyond that is a wiki
configuration matter, for instance with [Lockdown](https://www.mediawiki.org/wiki/Extension:Lockdown).

Page histories and recent changes still name whoever acted, which on a members-only wiki means the
staff who edit: members cannot appear there, since they cannot change anything.

## What loading the extension changes on the wiki

Loading the extension is the switch that turns members-only access on. It:

* revokes from the reader group everything that would let a reader change the wiki or see behind the
  scenes: editing, commenting, moving, uploading, deleting, protecting, tagging, creating accounts,
  sending email, reading the abuse filters and their log, and reading or changing their own private
  information or preferences, which closes `Special:ChangeEmail` to them;
* sets `$wgBlockDisablesLogin`, so blocking a member keeps them out of a private wiki;
* restricts the `newusers` and `block` logs to the `memberaccess-manage` right, unless the wiki
  already restricted them;
* turns off ConfirmEdit's `badloginperuser` captcha trigger, so failed logins no longer escalate to a
  captcha for the account they name, for everyone on the wiki and not only for members; the per-IP
  `badlogin` trigger is left alone;
* grants `autocreateaccount` to anonymous visitors, since a member's account is created by logging in;
* removes `@` from `$wgInvalidUsernameCharacters`, and changes `$wgUserrightsInterwikiDelimiter` from
  `@` to `@@`, so that `Special:UserRights` can act on an account named after an address;
* sets `$wgExtendedLoginCookieExpiration` to `$wgMemberAccessSessionDurationSeconds`, which decides
  how long a remembered login lasts for everyone on the wiki, not only for members.

## Installation

Platform requirements:

* [PHP](https://www.php.net/) 8.3 or later
* [MediaWiki](https://www.mediawiki.org/) 1.43 or later
* MySQL, MariaDB or SQLite. No PostgreSQL schema is shipped
* Working outgoing email, since login codes are sent by mail

Clone into the wiki's `extensions/` directory:

```shell
git clone git@github.com:ProfessionalWiki/MemberAccess.git
```

Then add to `LocalSettings.php`:

```php
wfLoadExtension( 'MemberAccess' );
```

Run `php maintenance/run.php update --quick` to create the extension's tables.

## Management API

Groups, allowlist entries and the roster are managed over REST, under `/rest.php/member-access/v0/`.
Every endpoint requires the `memberaccess-manage` right, which sysops and bureaucrats have. Writes also require the
wiki's CSRF token in an `X-CSRF-TOKEN` header, unless the session provider is inherently CSRF-safe.

| Endpoint | What it does |
|---|---|
| `GET /groups` | Every group with its entry count and its total and active member counts |
| `POST /groups` | Creates a group. Body: `name` |
| `PUT /groups/{id}` | Renames a group. Body: `name` |
| `DELETE /groups/{id}` | Deletes a group. Refused while it still holds entries, or while members are attributed to it |
| `GET /groups/{id}/entries` | The group's allowlist entries |
| `POST /groups/{id}/entries` | Adds an entry. Body: `value`, an email address or `@domain` |
| `DELETE /entries/{id}` | Removes an allowlist entry |
| `GET /members` | The roster: each member's address, group, creation, last login and active flag, plus the totals overall and per group |
| `POST /members/{userId}/deactivate` | Ends a member's access. Also requires the `block` right, and refuses your own account |
| `POST /members/{userId}/reactivate` | Restores a member's access. Also requires the `block` right. The response's `blocked` says whether a block placed for another reason is still on the account |

A failure answers with the HTTP status and a body carrying a stable `errorCode` next to a
human-readable `error`: `not_logged_in`, `permission_denied`, `invalid_csrf_token`, `invalid_group_name`,
`group_name_too_long`, `duplicate_group_name`, `group_not_found`, `group_not_empty`, `group_has_members`,
`invalid_entry_value`, `entry_value_too_long`, `duplicate_entry`, `entry_not_found`, `not_a_member`,
`cannot_deactivate_self`, `block_right_required`, `block_failed`, `unblock_failed`. A `duplicate_entry` also carries
`conflictingGroupId` and `conflictingGroupName`, naming the group that already admits the value.
Malformed requests are refused by MediaWiki's REST framework before reaching the extension, and
carry its error shape rather than this one.

## Configuration

| Variable | Type | Default | Description |
|---|---|---|---|
| `$wgMemberAccessReaderGroup` | string | `'reader'` | Name of the user group that members are placed in |
| `$wgMemberAccessCodeTtlSeconds` | int | `600` | How long an issued login code stays valid, in seconds |
| `$wgMemberAccessCodeAttemptLimit` | int | `5` | How many times a code may be entered before it is burned |
| `$wgMemberAccessEmailBurstLimit` | int | `3` | Maximum code requests per email address within 15 minutes |
| `$wgMemberAccessEmailDailyLimit` | int | `10` | Maximum code requests per email address within 24 hours |
| `$wgMemberAccessIpBurstLimit` | int | `10` | Maximum code requests per client IP within 15 minutes |
| `$wgMemberAccessIpDailyLimit` | int | `50` | Maximum code requests per client IP within 24 hours |
| `$wgMemberAccessSenderAddress` | ?string | `null` | Address login codes are sent from. Falls back to `$wgPasswordSender` |
| `$wgMemberAccessSessionDurationSeconds` | int | `2592000` | How long a remembered login lasts, wiki-wide. Thirty days, against core's 180 days. `0` leaves `$wgExtendedLoginCookieExpiration` alone |
| `$wgMemberAccessBlockedApiModules` | string[] | `[ 'allusers', 'users', 'blocks' ]` | Action API query submodules the reader group may not use |

Issued codes and rate-limit counters are held in the main object stash (`$wgMainStash`), which is
database-backed by default. Point it at Redis or Valkey to keep them out of the database.

Route the log channel to keep the audit trail:

```php
$wgDebugLogGroups['MemberAccess'] = '/path/to/memberaccess.log';
```

## Development

Install dependencies from the extension directory:

```shell
composer install
```

Run all checks (PHPCS, PHPStan and PHPUnit) from a MediaWiki installation:

```shell
composer preflight
```

After changing a table definition in `sql/*.json`, regenerate the SQL for both database types:

```shell
php maintenance/run.php generateSchemaSql --json extensions/MemberAccess/sql/<table>.json \
	--sql extensions/MemberAccess/sql/mysql/<table>.sql --type mysql
php maintenance/run.php generateSchemaSql --json extensions/MemberAccess/sql/<table>.json \
	--sql extensions/MemberAccess/sql/sqlite/<table>.sql --type sqlite
```
