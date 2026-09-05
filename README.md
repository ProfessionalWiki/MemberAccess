# Member Access

[![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/ProfessionalWiki/MemberAccess/ci.yml?branch=master)](https://github.com/ProfessionalWiki/MemberAccess/actions?query=workflow%3ACI)
[![codecov](https://codecov.io/gh/ProfessionalWiki/MemberAccess/branch/master/graph/badge.svg)](https://codecov.io/gh/ProfessionalWiki/MemberAccess)
[![Latest Stable Version](https://poser.pugx.org/professional-wiki/member-access/v/stable)](https://packagist.org/packages/professional-wiki/member-access)
[![Download count](https://poser.pugx.org/professional-wiki/member-access/downloads)](https://packagist.org/packages/professional-wiki/member-access)
[![License](https://poser.pugx.org/professional-wiki/member-access/license)](LICENSE)

MediaWiki extension for members-only wikis: readers log in with an email one-time code,
admitted by an allowlist of addresses and domains organized into named groups.

* A member never has a password, and a login is remembered for about a month.
* Members can read and nothing else: everything that would let them change the wiki or see behind
  the scenes is revoked.
* Accounts create themselves at first login. Removing an allowlist entry ends access at the next
  login; deactivating a member blocks them at once.
* Single sign-on logins through [OpenIDConnect] can be held to the same allowlist; staff accounts
  are exempt.
* Nothing gives a member's identity away: their account is named after nobody, and code and
  password-reset requests answer the same for every address.
* An admitted address can be mailed an invitation naming the login page and how to log in.
* Groups, allowlist entries and the member roster are managed over a REST API.
* It does not make the wiki private: restricting who may read stays a wiki configuration decision.

- [Introduction to the extension](https://professional.wiki/en/extension/member-access#Overview)
- [Usage documentation](https://professional.wiki/en/extension/member-access#Usage)
- [How it works](#how-it-works)
- [What loading the extension changes on the wiki](#what-loading-the-extension-changes-on-the-wiki)
- [Installation](#installation)
- [Management API](#management-api)
- [Configuration](#configuration)
- [Development](#development)
- [Release notes](#release-notes)

Get professional support for this extension via [Professional Wiki], its creators and maintainers.
We provide [MediaWiki Development], [MediaWiki Hosting], and [MediaWiki Consulting] services.

## How it works

What follows describes each login route with that route turned on. Neither is offered until a setting
says so: see [Login routes](#login-routes).

### Login codes

A visitor asks for a login code by entering their email address in the box the login form leads
with. Whether one is sent depends on the allowlist, where each entry belongs to exactly one group. A
code is six digits, valid for ten minutes and usable once, and leads the subject line of the mail
that carries it, so it can be read off a notification. The response to a code request is the same
either way, so it never reveals who is on the list.

The screen that asks for the code names the address back, so an address typed wrongly can be seen
rather than waited on. It names it whether or not the allowlist admits it. From there the visitor
can ask for another code, which replaces the one before it, or go back and enter a different
address. Asking for another is counted by the same rate limits as asking for the first; once they
are spent the offer is withdrawn, and the code already sent goes on working.

Entering the right code logs the visitor in, and the first time also creates their account: it is
named after nobody, placed in the reader group, and the address is recorded as confirmed. The
allowlist is consulted again at that point, so removing an entry ends access at the next login. A
code never opens an account that was created some other way.

### Invitations

Adding an address to the allowlist grants access but sends no mail. An invitation is asked for
separately: it mails that address the login page, the address to log in with, and that a code arrives
by mail instead of a password. It names no username and no inviter.

Only an address entry can be invited, not a domain rule, and only while the
[code route](#login-routes) is offered, since the mail tells the recipient to log in with a code.
Inviting again sends another mail; the entry records when the last one was sent.

### Usernames

A member's account is named `Member` and six characters drawn at random, `Member A7K2M4` for
instance, which says nothing about who holds it. Everywhere MediaWiki names an account is a place a
member could be recognised, which a name that identifies nobody makes harmless.

The address is on the account as its confirmed email and in the roster, which is what joins an
address to an account: a code login goes to the account the roster names for that address. Nothing
about the address constrains the name, so an address that could never have been a username is
admitted like any other.

### Passwords

A member never has a password: setting one is refused, and so is having a temporary one mailed by a
password reset. Both stay open to accounts that were not admitted through the allowlist. Asking for
a reset of a member's address answers exactly as it does for an address that was never admitted.

### Single sign-on

Single sign-on logins are held to the same allowlist. With [PluggableAuth] configured, the address
the identity provider returns has to match an entry or the login is refused, and a first login that
matches is provisioned exactly like a code login. For an account that is already a member, the
address checked is the one recorded when they were admitted, so removing their entry ends this route
too. Accounts that are not members are exempt, so staff signing in through the identity provider are
unaffected; when such a login uses an address the allowlist would not admit, it is written to the log
channel. An account that carries the reader group without being on the roster is no staff account
but a forgotten member account — a removed member's closed account, or one left behind by a failed
provisioning — and is refused rather than exempted. A refusal is final: no other handler of the same
hook can hand the login back. Without PluggableAuth the check never runs.

The account is created by the identity provider's plugin, which settles on its name before the
extension is asked anything. [OpenIDConnect] offers a say over that name, which the extension takes
for the logins the allowlist admits, so that a member is named after nobody here as well. A
processor the wiki configured itself is kept and runs first, and decides the name of every login
that is not a member's. A plugin offering no such say would name the account itself, so a login the
allowlist admits arriving through one is refused, and the refusal is written to the log channel.
Holding single sign-on to the allowlist therefore works with OpenIDConnect and no other plugin.

### Deactivation and removal

Deactivating a member blocks their account sitewide and indefinitely; removing their allowlist entry
alone leaves the account and its open session intact. The block is an ordinary one, so it appears in
the block log and can be undone by hand. A deactivated member asking for a login code gets no mail,
and the same answer as an address that was never admitted. Reactivating lifts the block and leaves
the account otherwise as it was.

A block placed by hand, for some other reason, is neither replaced when the member is deactivated
nor lifted when they are reactivated. Deactivating is refused while such a block would not keep the
member out by itself, because it runs out or is only partial.

Removing a member makes the roster forget them and closes their account: the address goes off it
and the open sessions with it, so their address is free again and reaches a new account at the next
code login. What is removed is the member, not their admission: the allowlist entry that admits
them stays, and a deactivation block stays behind on the closed account rather than reaching the new
one. An identity provider that recorded the account still points at the closed one, so a removed
member's single sign-on logins arrive there and are refused rather than reaching a fresh account.

### Rate limits and logging

Code requests are rate limited per email address and per client IP, with both a burst and a daily
limit. Codes are stored hashed and are burned after five wrong entries. Every issue, success,
failure and rate-limit hit is logged through the `MemberAccess` log channel, with the email
address hashed.

### Login routes

Two settings, one per login route, say what the allowlist governs there and whether the code route
is offered at all.

`$wgMemberAccessCodeLogin` says whom the one-time code route admits:

| Value | What the route does |
|---|---|
| `allowlisted` | Admits the addresses an allowlist entry matches |
| `open` | Admits every address. A matching entry still attributes the member to its group; without a match they have no group |
| `off` | Is not offered: no button on the login form, and no code is issued. The default |

An unrecognized value is read as `off`, with a warning in the log. So is an empty one, without a
warning.

`$wgMemberAccessApplyAllowlistToSso` holds single sign-on logins to the allowlist when set to `true`.
Anything else, the default included, leaves that route alone: no login is refused, none is logged, and
the accounts that route creates are ordinary accounts rather than members. Setting it to `true` later
does not reach them. An account that is not a member is exempt, so everyone who signed in while the
allowlist was off that route keeps their account and the rights it carries, outside the allowlist,
until an administrator deals with the account by hand.

An open route is exactly that: anyone who can receive mail at the address they enter gets an account
and a roster row, without an administrator having seen the address first. The per-address rate
limits bound what can be aimed at one mailbox; an attacker who varies the address meets only the IP
limits. The route suits a wiki with another gate in front of it, an internal network for instance,
rather than one on the open internet.

The open route changes only the allowlist check; everything else still holds. A member whom no entry
matched has no group until one does: their next login, over either route, writes that group down.
The group a member already has is never moved.

Narrowing a route ends the access of everyone it no longer admits, at their next login: everyone on
the code route, and every member on single sign-on.

With the code route off and single sign-on left alone, the allowlist governs nothing.

## What loading the extension changes on the wiki

Whatever the login routes are set to, loading the extension:

* revokes from the reader group everything that would let a reader change the wiki or see behind the
  scenes: editing, commenting, moving, uploading, deleting, protecting, tagging, creating accounts,
  sending email, reading the abuse filters and their log, and reading or changing their own private
  information or preferences, which closes `Special:ChangeEmail` to them;
* sets `$wgBlockDisablesLogin`, so blocking a member keeps them out of a private wiki;
* restricts the `renameuser` log to the `memberaccess-manage` right, unless the wiki already
  restricted it, since an entry there names what an account was called before, which for a member
  can be their email address;
* refuses members a password, whatever the routes: setting one and having a temporary one mailed
  stay refused.

While the code route is turned on, it also turns off ConfirmEdit's `badloginperuser` captcha trigger,
so failed logins no longer escalate to a captcha for the account they name, for everyone on the wiki
and not only for members; the per-IP `badlogin` trigger is left alone.

While the allowlist governs single sign-on, it also sets `$wgOpenIDConnect_PreferredUsernameProcessor`,
so that the accounts that route creates for members are named after nobody, and
`$wgOpenIDConnect_EmailProcessor`, so that the address the plugin resolved is the one the allowlist is
asked about. Processors the wiki configured itself are kept and run first.

While any route can log a member in — the code route turned on, or the allowlist governing single
sign-on — it also:

* grants `autocreateaccount` to anonymous visitors, since a member's account is created by logging
  in;
* sets `$wgExtendedLoginCookieExpiration` to `$wgMemberAccessSessionDurationSeconds`, which decides
  how long a remembered login lasts for everyone on the wiki, not only for members.

A wiki with the code route off and single sign-on left alone gets the first list and nothing else:
what an anonymous visitor may do, what ConfirmEdit does, how single sign-on names the accounts it
creates, and how long a remembered login lasts are left as the wiki has them. That is a wiki that has
just loaded the extension, since neither route is offered until a setting says so.

## Installation

Platform requirements:

* [PHP] 8.3 or later
* [MediaWiki] 1.43 or later
* MySQL, MariaDB or SQLite. No PostgreSQL schema is shipped
* Working outgoing email while the code route is offered, since login codes and invitations are sent by mail

Optional platform requirements:

* [OpenIDConnect] 8.3 or later while single sign-on is held to the allowlist, with
  `$wgOpenIDConnect_UseRandomUsernames` left off and its preferred username processor left to this
  extension rather than set per provider. Every other way of naming a single sign-on account is one
  the extension cannot make opaque, and a member's login is then refused.
  `$wgOpenIDConnect_MigrateUsersByUserName` has to be off as well, since it hands a login the account
  whose name it presents as its `preferred_username`, which is a member's account claimed without
  their address. `$wgOpenIDConnect_MigrateUsersByEmail` is what joins a single sign-on login to the
  account the code route created for the same address; without it that address gets a second account

Clone into the wiki's `extensions/` directory:

```shell
git clone git@github.com:ProfessionalWiki/MemberAccess.git
```

Then add to `LocalSettings.php`:

```php
wfLoadExtension( 'MemberAccess' );
$wgMemberAccessCodeLogin = 'allowlisted';
```

Loading alone admits nobody: the second line turns on the code login route, held to the allowlist.
See [Login routes](#login-routes) for what each route setting admits.

To send the login code and the invitation as rich email rather than plain text, set:

```php
$wgAllowHTMLEmail = true;
```

Run `php maintenance/run.php update --quick` to create the extension's tables, and again after every
upgrade, since one may add a column to a table the wiki already has. Until it has run, anything that
reads those tables fails with a database error; a wiki missing them altogether also says so, with a
warning on the `MemberAccess` log channel.

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
| `POST /groups/{id}/entries` | Adds entries, at most 500 per request. Body: `values`, a list of email addresses and `@domain`s |
| `DELETE /entries/{id}` | Removes an allowlist entry |
| `POST /entries/{id}/invitation` | Mails an invitation to the entry's address and records the time as the entry's `invited`. Refused for a domain rule, or while the code route is off |
| `GET /members` | The roster: each member's address, group, creation, last login and active flag, plus the totals overall and per group |
| `POST /members/{userId}/deactivate` | Ends a member's access. Also requires the `block` right, and refuses your own account |
| `POST /members/{userId}/reactivate` | Restores a member's access. Also requires the `block` right. The response's `blocked` says whether a block placed for another reason is still on the account |
| `DELETE /members/{userId}` | Removes a member, freeing their address for a new account. Refuses your own account |

Adding entries answers `200` with one result per value, in the order the values were given, each
echoing its value as sent and saying in `added` whether it was added. A refused value neither stops
the values after it nor undoes the ones before it, and says why in `errorCode` and `error`:
`invalid_entry_value`, `entry_value_too_long`, or `duplicate_entry`, which also names the group that
already admits the value.

```json
{
	"results": [
		{
			"value": "jane@example.com",
			"added": true,
			"entry": { "id": 7, "value": "jane@example.com", "kind": "email", "created": "2026-05-04T09:12:33Z", "invited": null }
		},
		{
			"value": "john@example.net",
			"added": false,
			"errorCode": "duplicate_entry",
			"error": "A group already admits that address or domain",
			"conflictingGroupId": 2,
			"conflictingGroupName": "Umbrella"
		}
	]
}
```

What concerns the request as a whole answers as a failed request instead, and adds nothing: no such
group (`group_not_found`), a body without a `values` list or with a value that is not text
(`invalid_request_body`), and more values than one request may carry (`too_many_entry_values`).

A failed request answers with the HTTP status and a body carrying a stable `errorCode` next to a
human-readable `error`: `not_logged_in`, `permission_denied`, `invalid_csrf_token`,
`invalid_request_body`, `invalid_group_name`, `group_name_too_long`, `duplicate_group_name`,
`group_not_found`, `group_not_empty`, `group_has_members`, `too_many_entry_values`,
`entry_not_found`, `not_an_address`, `code_login_off`, `invitation_not_sent`, `not_a_member`,
`cannot_deactivate_self`, `block_right_required`, `block_failed`, `unblock_failed`,
`cannot_remove_self`. A request the REST framework refuses first — an id that is not a number, a
body it cannot read — carries MediaWiki's error shape rather than this one.

## Configuration

| Variable | Type | Default | Description |
|---|---|---|---|
| `$wgMemberAccessCodeLogin` | string | `'off'` | Whom the one-time code route admits: `allowlisted`, `open` or `off`. See [Login routes](#login-routes) |
| `$wgMemberAccessApplyAllowlistToSso` | bool | `false` | Whether single sign-on logins are held to the allowlist. See [Login routes](#login-routes) |
| `$wgMemberAccessReaderGroup` | string | `'reader'` | Name of the user group that members are placed in |
| `$wgMemberAccessCodeTtlSeconds` | int | `600` | How long an issued login code stays valid, in seconds |
| `$wgMemberAccessCodeAttemptLimit` | int | `5` | How many times a code may be entered before it is burned |
| `$wgMemberAccessEmailBurstLimit` | int | `3` | Maximum code requests per email address within 15 minutes |
| `$wgMemberAccessEmailDailyLimit` | int | `10` | Maximum code requests per email address within 24 hours |
| `$wgMemberAccessIpBurstLimit` | int | `10` | Maximum code requests per client IP within 15 minutes |
| `$wgMemberAccessIpDailyLimit` | int | `50` | Maximum code requests per client IP within 24 hours |
| `$wgMemberAccessSenderAddress` | ?string | `null` | Address that login codes and invitations are sent from. Falls back to `$wgPasswordSender` |
| `$wgMemberAccessSessionDurationSeconds` | int | `2592000` | How long a remembered login lasts, wiki-wide. Thirty days, against core's 180 days. `0` leaves `$wgExtendedLoginCookieExpiration` alone |

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

Changing a table that installs already run also needs a patch, so that they get the change. Add an
abstract schema change under `sql/abstractSchemaChanges/`, generate its SQL for both database types,
and register it in `SchemaChangesHandler` after the `addExtensionTable` calls:

```shell
php maintenance/run.php generateSchemaChangeSql \
	--json extensions/MemberAccess/sql/abstractSchemaChanges/<patch>.json \
	--sql extensions/MemberAccess/sql/mysql/<patch>.sql --type mysql
php maintenance/run.php generateSchemaChangeSql \
	--json extensions/MemberAccess/sql/abstractSchemaChanges/<patch>.json \
	--sql extensions/MemberAccess/sql/sqlite/<patch>.sql --type sqlite
```

## Release notes

### Version 1.1.0 - 2026-09-04

* Enhanced login form UX for code login: the email address box now comes first and has focus,
  "Continue with email" is the primary button, Enter in the address box requests a code, and the
  password form follows under "or log in with a password"
* Improved the login code email by including the code in the email title
* Reduced the login code from 8 to 6 digits for better UX

### Version 1.0.0 - 2026-09-03

Initial release for MediaWiki 1.43+ with these features:

* Login with an eight-digit code mailed to the member's address, valid for ten minutes and usable
  once; a login is remembered for thirty days
* An allowlist of email addresses and domains, organized into named groups, decides who is admitted
* Accounts create themselves at first login, into a reader group that may read and nothing else,
  under a name that identifies nobody
* Single sign-on logins through [OpenIDConnect] can be held to the same allowlist, with staff
  accounts exempt
* Login routes are off by default: the code route admits the addresses the allowlist matches, every
  address, or nobody; single sign-on is held to the allowlist or left alone
* Members never have a password: setting one and having a temporary one mailed are both refused
* Deactivation blocks a member's account, reactivation lifts the block, and removal closes the
  account and frees the address for a new one
* Code requests are rate limited per email address and per client IP; codes are stored hashed and
  burned after five wrong entries
* Code and password-reset requests answer the same for every address, so neither reveals who is a
  member
* Every code issue, login success, login failure and rate-limit hit is logged on the `MemberAccess`
  channel, with the address hashed
* An invitation can be mailed to an admitted address, naming the login page and how to log in
* A REST API for managing groups, allowlist entries and the roster

[MediaWiki]: https://www.mediawiki.org
[Professional Wiki]: https://professional.wiki
[MediaWiki Development]: https://professional.wiki/en/mediawiki-development
[MediaWiki Hosting]: https://pro.wiki
[MediaWiki Consulting]: https://professional.wiki/en/mediawiki-consulting-services
[PHP]: https://www.php.net
[PluggableAuth]: https://www.mediawiki.org/wiki/Extension:PluggableAuth
[OpenIDConnect]: https://www.mediawiki.org/wiki/Extension:OpenID_Connect
