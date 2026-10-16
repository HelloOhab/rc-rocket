# Updates via GitHub

## One-time setup

Nothing to add to `wp-config.php`. Since 0.12.3 the plugin looks for
releases in the public `HelloOhab/rc-rocket` repository by itself: upload
the zip once (Plugins → Add Plugin → Upload) and new versions show up in
Dashboard → Updates like any other plugin's.

**Verify on one site:** RC Rocket → System check → the Updates row should
name the installed version and say whether a newer one exists. Or over SSH:

    wp rc-rocket version --flush

Sites still on 0.12.2 or older need the 0.12.3 zip uploaded by hand once
(or the old `RC_ROCKET_UPDATE_GITHUB` line); from then on they update
themselves.

`wp-config.php` constants still work and override the built-in defaults,
for a site that needs something different:

```php
define( 'RC_ROCKET_UPDATE_GITHUB', 'youragency/rc-rocket' ); // '' switches updates off
define( 'RC_ROCKET_UPDATE_TOKEN', 'github_pat_...' );       // private repos only
define( 'RC_ROCKET_UPDATE_URL', 'https://…/rc-rocket.json' ); // own manifest instead
define( 'RC_ROCKET_UPDATE_CHANNEL', 'beta' );                // overrides the setting
```

## Publishing

    # bump the header in rc-rocket.php, commit
    git tag v0.6.1
    git push origin v0.6.1

The workflow in `.github/workflows/release.yml` refuses to build if the tag
does not match the plugin header, lints every file, runs every test suite,
and attaches the zip to the release.

If any test fails, no release is published. That is the point.

## Rolling out to the fleet

Every release is published as a **pre-release**. Sites only see it if they
are on the beta channel: RC Rocket → Advanced → Safety → Updates → tick
**Get test versions early** (test sites only).

1. Put two or three sites on the beta channel: at least one on Divi 4 and
   one on Divi 5, ideally on different hosts. System check → Updates says
   "Beta channel" on those sites.
2. Tag the release. Update the beta sites (Dashboard → Updates, or
   `wp rc-rocket version --flush` and then `wp plugin update rc-rocket`).
3. Run `tools/compare` against several pages on each beta site. Fix every
   FAIL, and look at every CHECK.
4. Check the admin by hand on one site: settings save, cache clears, the
   Divi builder opens and saves.
5. Edit the release on GitHub and untick **Set as a pre-release**. It becomes
   the latest release, and every other site is offered it within six hours.

Releases are offered, not installed: a site only updates by itself if
auto-updates are on for RC Rocket on its Plugins screen. To roll out in
waves, leave auto-updates off and update a batch at a time.

## Public or private?

The repository is public, which is what lets the plugin update with no
setup. If it ever goes private again:

**Private repository** means a token in `wp-config.php` on every site. Tokens
expire, so rotation means editing 30 files. Use a fine-grained token scoped to
read-only Contents on that one repository.

**Public repository** needs no token at all and removes the rotation problem.
The plugin is GPL by virtue of using WordPress APIs, and there is nothing
sensitive in it — no keys, no client data. The only thing you lose is the
convenience of not showing your work.

**Private code, public releases** is the third option and probably the best:
keep the repository private, and have the workflow also upload the zip and a
JSON manifest to an S3 bucket or Cloudflare R2. Sites then use
`RC_ROCKET_UPDATE_URL` and never see a token.
