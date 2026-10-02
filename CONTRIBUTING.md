# Contributing to UCM Provisioner

UCM Provisioner is maintained as an operational FreePBX module. Changes must keep
phone configuration, FreePBX endpoint state, Asterisk glue, TFTP assets and remote
site state coherent. A locally plausible XML change is not sufficient if another
layer resolves the same setting differently.

## Development setup

Use PHP 8.2 or newer and install the locked production dependency set:

```bash
composer install --no-dev
composer validate --strict
php tests/run.php
```

Tests are independent PHP assertion scripts rather than a framework-specific test
runner. `tests/run.php` first parses every first-party PHP file, then executes each
`*Test.php` in a clean child process.

The delayed browser-context regression is a separate optional Node.js check:

```bash
node tests/media-async-context.js
node tests/ui-usability.js
node tests/background-choices.js
node tests/site-bootstrap-actions.js
```

It executes extracted Media/Firmware helpers with synthetic deferred responses;
it is not a real-browser or handset acceptance test and does not add a production
Node.js dependency.

The usability check exercises selected-first multi-select ordering, required field
submission, preserved actions and the exact Fail2ban Unban confirmation handler
with synthetic data only.

## Change rules

- Generate module-owned asset names through `AssetFilename`; preserve full content
  hashes and test the emitted reference together with the published bytes. The
  complete producer/consumer and legacy compatibility inventory is in
  [Asset filenames](docs/ASSET-FILENAMES.md). Required vendor names remain exact.
- Put model and firmware compatibility facts in `lib/ModelCatalog.php`; generators,
  selectors and validation must read the same fact rather than copy a model list.
- Resolve driver/transport/port through `EndpointProfile`. Do not make the FreePBX
  endpoint and generated phone configuration choose independently.
- Resolve defaults, template stacks and identity labels through their existing
  services. Do not add a second precedence implementation in a view or generator.
- Use `TftpStore` and the build transaction services for managed files. Do not write
  directly to a site's TFTP path from a controller action.
- Background selectors must consume verified current catalog outputs. Keep raw
  storage observations and retained aliases separate from new assignment choices;
  exercise the real publisher, manifest reader and inventory together when changing
  that contract. A filename prefix or decodable image never proves catalog ownership.
- Preserve the API plus restricted-SSH dual channel. The API is the normal path;
  SSH is required for enrollment, recovery and version-skew diagnostics.
- Preserve last-known-good snapshots. An unreachable site must be unknown/read-only,
  never interpreted as an empty authoritative site.
- Keep full person/caller name separate from bounded phone/line/BLF labels. Resolve
  AD primary, linked additional and manual/shared identity through the common
  binding resolver; cached foreign projections must not override local authority.
- Treat preview packets and destination sets as immutable. Force refresh is not
  permission to reread LDAP, retarget a newly enabled site or ignore adoption,
  ownership, revision or dependency checks. Keep desired state, file publication,
  PBX activation, command delivery and physical observation distinct.
- Do not retry a dispatched mutation through another transport after an uncertain
  response. Reconcile its encrypted destination receipt; stale timeouts do not
  authorize replay or lock removal.
- Preserve upgrade reconciliation unless every supported historical schema path is
  intentionally retired in a documented major release.
- Keep privileged operations in fixed-schema helpers. Never pass a browser-supplied
  shell command to sudo.
- Comments should explain a boundary, vendor constraint or non-obvious reason. Do
  not narrate obvious syntax or record temporary debugging history.

## Testing expectations

A change must include the smallest meaningful regression test at the ownership
boundary it affects. Examples:

- generator output plus model applicability for a device feature;
- resolver trace plus final merged value for precedence changes;
- schema fixture plus a second idempotent upgrade run for migrations;
- preview hash, staged writes and rollback receipt for build changes;
- source/destination state and missing dependency behavior for replication;
- input validation, authorization and timeout behavior for external commands.

Retain focused tests for ownership/adoption
and person-bound overrides, fixed cross-site identity/publication plans, lost RPC
responses, no-write read paths, Core/runtime compensation failures, paired recovery
archives, source/destination file locks, chunk interleaving, retirement references
and no-overwrite restore. Test a change after preview and a failure at each relevant
commit/acknowledgment boundary; a successful happy path alone is insufficient.

Publication-plan output must contain only its safe hash and fixed destination
identities, never raw employee contacts or tokens. Web directory fixtures must cover
every route under direct connecting-address access (forwarding ignored) and
optional exact-proxy access, field-limited search/export,
stale membership/policy rejection and exceptional audience-change review. Use
isolated temporary roots and synthetic PDO/SFTP/Core fixtures, not production paths.

Credential-install checks must exercise verified local backup, identical key and
ciphertext, unconfigured fresh sources, invalid/missing input and interrupted or
uncertain commit/retry without overwriting a matching credential file. Runtime
readiness runs as asterisk, never by granting reader-secret access to the SSH agent.
Web-directory sync checks must cover explicit access acknowledgement, source/job
ownership, hard local-save rejection, soft pause/resume, release with content
preserved, old destination capabilities, and missing published book keys. These
settings checks must never publish contacts or mark phones for rebuild.
Web-policy permission tests verify the install backfill, stable read-only lock,
repeat saves and reader-secret isolation. Exact 0640/asterisk ownership checks
require Linux with the existing asterisk group; Windows results do not prove
POSIX permissions or access across separate service accounts.
Native FreePBX permission-hook fixtures must verify module.xml dispatch, metadata-
only behavior, exact normal modes, private-owner preservation, nested recovery
isolation and rename-restored media permissions. Shared-writer fixtures must cover
new directories/files without SGID, stable lock contention and explicit permission
failure. Run the administrator manual's repeated global-reset acceptance on Linux
before claiming the permission integration is production-verified.

Sites onboarding fixtures must cover blank/current/legacy/disabled and inconsistent
registry/filesystem states, newer-version rejection, unknown probes, repair retry,
API partial failure and retained SSH readiness. Docker fixtures must reject absent
or ambiguous containers before mutation and require access verification from both
the controller and owning PBX. Deferred browser fixtures cover fingerprint
cancellation, transport/business errors, password cleanup and nested button
ownership. Synthetic transports do not prove live service or handset behavior.

Do not weaken assertions to make a release pass. Device-family changes also require
a real-phone canary or a clearly documented outstanding validation item.

## Style

- Use `declare(strict_types=1);` in standalone PHP classes and tests.
- Follow the existing namespace and PSR-4 layout under `lib/`.
- Validate and normalize input at the boundary; keep normalized internal values.
- Escape view output and use existing authenticated AJAX helpers for mutations.
- Prefer short methods with one operational purpose. Large compatibility façades may
  be split only when transaction and migration behavior remains covered.
- Use examples from reserved documentation domains and address ranges. Never commit
  production names, addresses, credentials, hashes tied to identities or captures.

## Documentation and release changes

Update the administrator manual when UI behavior changes, the device support guide
when model facts change, and the architecture map when ownership moves. Add a dated
changelog entry for user-visible behavior.

Before proposing a release:

```bash
composer validate --strict
composer audit --locked
php tests/run.php
python3 tools/build-release.py /tmp/ucmp-release-one
python3 tools/build-release.py /tmp/ucmp-release-two
```

The two archives must have identical SHA-256 values. Inspect the archive file list,
verify there are no local credentials or signatures, install it on a disposable
FreePBX 17 system, and complete the representative canary matrix in the
administrator manual.

A passing local assertion suite does not prove FreePBX/Core or MySQL/MariaDB locking
behavior, a live LDAP read, DNS/TLS/proxy policy, supported phone reload semantics or
what a physical handset displays. Record these outstanding acceptance gates and
distinguish a local candidate/build from a published archive and installed release.
