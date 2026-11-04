# Fleet Reporter

Craft CMS plugin that reports an installation's Craft, PHP and database versions, sites, plugins, updates and licences to One Darnley Road's Fleet. It supports Craft 3.7, 4 and 5.

## Install on a project

Add the repository to the project's `composer.json`:

```json
"repositories": [
  { "type": "vcs", "url": "git@github.com:onedarnleyroad/craft-fleet-reporter.git" }
]
```

Then:

```bash
composer require onedarnleyroad/craft-fleet-reporter
php craft plugin/install fleet-reporter
```

Set `FLEET_URL` and `FLEET_ORG_KEY` on each environment. Optional aliases go in `config/fleet-reporter.php`.

## Report contract

`tests/fixtures/report-v1.schema.json` is a copy of `docs/schema/report-v1.schema.json` in the `fleet` repo, which is canonical. Keep them identical.

## Developing against the stub

The stub stands in for Fleet's API: it checks signatures and validates every body against the schema.

```bash
cd tests/stub-server && composer install && cd -
php -S 127.0.0.1:8787 tests/stub-server/index.php
```

On a local Craft site, set `FLEET_URL=http://127.0.0.1:8787` (from DDEV, use `http://host.docker.internal:8787`) and `FLEET_ORG_KEY=fok_stub`. Accepted bodies are saved to `tests/stub-server/received/`.

```bash
php tests/validate.php some-report.json                 # check a file against the schema
php tests/send.php tests/fixtures/report-v1.example.json # send a signed report to the stub
```

## Keeping the repo publishable

Nothing in this repo may reveal where Fleet runs or which clients use it:
- The Fleet URL and keys come only from env vars, with no default.
- Examples and fixtures use `example.com` / `example.org`.
- Real reports never get committed: `tests/stub-server/received/` is ignored.

`tests/check-public.sh` fails on any host outside a short allowlist. Run it before pushing.

Emit JSON objects as objects: an empty PHP array encodes as `[]`, which the schema rejects for `unknowns` and `updates.plugins`.
