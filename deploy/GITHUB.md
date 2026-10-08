# Updates via GitHub

## One-time setup

**1. Push the plugin to a repository.** The folder in the zip must be
`rc-rocket`, which it is.

**2. Add the constants to each site's `wp-config.php`,** above the
`/* That's all, stop editing! */` line:

```php
define( 'RC_ROCKET_UPDATE_GITHUB', 'youragency/rc-rocket' );
define( 'RC_ROCKET_UPDATE_TOKEN', 'github_pat_...' );   // private repos only
```

**3. Verify on one site** — RC Rocket → System check → the Updates row should
name the repository and the available version. Or over SSH:

    wp rc-rocket version --flush

Only add the constants to the other 29 once that works.

## Publishing

    # bump the header in rc-rocket.php, commit
    git tag v0.6.1
    git push origin v0.6.1

The workflow in `.github/workflows/release.yml` refuses to build if the tag
does not match the plugin header, lints every file, runs all nine test suites,
and attaches the zip to the release.

If any test fails, no release is published. That is the point.

## Public or private?

**Private repository** means a token in `wp-config.php` on all 30 sites. Tokens
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
