# Security policy

## Supported release

The stable 1.0.x line and the 1.1 release candidate are maintained. These notes
describe the local 1.1.0rc33 candidate (schema 34), not a deployment attestation.
Older pre-1.0 builds should be upgraded before a report is reproduced.

## Reporting a vulnerability

Use the repository's private vulnerability-reporting function when it is enabled.
Otherwise, contact the repository owner through a private channel before opening a
public issue. Include the affected version, prerequisites, a minimal reproduction,
impact and any relevant sanitized logs. Do not include passwords, API keys, bind
credentials, private keys, phone secrets, customer names, production addresses or
an unredacted configuration export in an issue.

## Trust boundaries

UCM Provisioner is an administrative FreePBX module. A user who can administer it
can deliberately change phone credentials, endpoint routes, Asterisk glue, TFTP
content and selected remote-site configuration. It is not a privilege boundary
between different FreePBX administrators.

The module separates these operational boundaries:

- **Browser to FreePBX:** mutations require an authenticated session, POST and the
  module page token. Read-only phone endpoints use independent unguessable tokens.
- **Employee web phonebook:** disabled unless a PBX-local policy explicitly allows
  the direct client's IPv4/IPv6 CIDR and selected published books. It has no login
  or administration privileges. Direct mode checks the actual connecting address
  and ignores forwarded-client headers, including when a reverse proxy sends them.
  Trusted-proxy mode requires an exact configured peer IP and one selected,
  overwritten single-IP client header; missing, chained, conflicting or untrusted
  forwarding is denied. Client CIDRs and proxy IPs are separate allowlists. Everyone
  admitted by the selected network mode can read the allowed fields and, if enabled, export
  them; this is not an
  individual-user authorization boundary. The same check covers HTML, assets,
  JSON and Excel. Raw AD attributes, source keys and phone tokens are never sent.
- **PBX to PBX:** the federation API uses authenticated encryption, timestamps and
  nonces. A pinned forced-command SSH account remains the recovery and bootstrap
  path. Both ends must run compatible module versions. This is shared
  administrative federation trust, not cryptographic authentication of each
  claimed origin site. Identity packets cannot claim the receiving PBX's local
  origin authority. That guard does not make a compromised trusted administrator
  harmless to the rest of the federation.
- **PBX to TFTP backend:** SFTP uses a restricted `ucmp-tftp` account and a pinned
  host key for both PBX-hosted and remote Docker-hosted trees. PBX bootstrap
  refuses an unrelated UDP/69 owner and a symlinked managed root. The phone-facing
  TFTP protocol itself is unauthenticated and must be isolated to the voice network.
- **PBX to Active Directory:** each source has a separate credential. Accepted
  revisions are written atomically; an LDAP failure cannot erase the last accepted
  directory. Credential export and phonebook replication are deliberately separate.
- **Web process to root operations:** Fail2ban and Patch Manager use fixed helper
  programs, strict JSON schemas, locks, bounded operations and narrow sudo rules.
  They do not accept arbitrary command lines from the browser.
- **Network discovery:** the target PBX authenticates temporary Linux administrator
  credentials with local `su`/PAM and uses that account's existing sudo rights for
  a fixed, bounded nmap command. Credentials travel over process stdin, never argv
  or disk. Remote initiation uses the encrypted API and the same local path, with
  no SSH fallback and no operation journal containing credentials. No new sudo
  policy or privileged helper is installed for discovery.
- **Release to installed module:** the source archive has deterministic hashes.
  FreePBX integrity signing is performed locally after installation because each
  PBX owns its own signing key and trust anchor.

## Secret storage and export

Sites preparation accepts privileged SSH credentials for the current request only.
Fresh host-key, platform and module probes precede installation; a failed probe
does not authorize a blank-install assumption. Neither filesystem nor registry
version may be downgraded. Enrollment repair preserves healthy keys and reports
module, SSH and API readiness independently. Docker enrollment targets an existing
compatible container and preserves unrelated authorized keys and TFTP contents;
it does not create Docker infrastructure or change firewall rules.

Sites fingerprint/version reads use validated current-form connection values
without saving them. Explicit install/enrollment actions save only the relevant
connection fields, with an old-value concurrency guard; other edits still require
Save. A changed SSH target invalidates its old pin/readiness and requires host-key
confirmation before credentials are sent. Docker key reads decode a bounded,
data-only record before comparing the original file hash and merging keys.
Signature checks use the PBX service account/API; the restricted SSH agent is not
granted access to the FreePBX GPG keyring. Local signing includes OpenPGP trust
setup, not an X.509 certificate import or weakened HTTPS verification.

Site API secrets and retained SSH credentials are encrypted with a per-installation
key before database storage. Managed AD reader credentials use separate owner-only
files, not database ciphertext. Their directory is mode 0700 and files are 0600,
owned by asterisk. The shared constrained site agent does not gain reader-secret
access. The key file is mode 0640 with the existing asterisk service group.

Configuration archives omit operational logs, live registrations, API secrets,
SSH passwords, directory bind credentials, private signing keys and Patch Manager
runtime state. Structured configuration values are projected recursively; generated
credential-bearing phone files are not a portable configuration export. Restricted
**recovery** archives are a separate explicit mode and can contain database secrets,
generated credentials and the matching encryption key. They are private backups,
not sanitized sharing artifacts. Both modes validate member SHA-256 and the local
controller identity on import; these checks are not a digital signature. Use
configuration federation, not archive import, for cross-controller policy transfer.
A replicated phonebook contains compiled contacts and layout policy, not the source
directory credential.

Backups that include the FreePBX database and the per-installation encryption key
must be protected together. Losing the key makes retained encrypted values
unrecoverable; exposing both defeats database-at-rest protection.

`LocalCredentialKey` reads existing key material without creating, rotating or
migrating it. The installation hook automatically moves a legacy database key to
`secrets/credentials.key` after verifying a matching sites/settings/directory/key
recovery archive. This storage-only backup excludes remote TFTP assets. The same
key, ciphertext, directory source identity, accepted revisions and saved AD transport
are retained. Matching files survive interrupted or uncertain commits so a retry
can finish without replacing credentials. Missing or disagreeing keys fail closed;
ordinary reads, cron, schema reconciliation and federation do not migrate keys.
A genuinely fresh installation seeds its first key during installation only.

Destination RPC operation receipts are encrypted because responses can contain
credentials. A lost response after dispatch is unresolved until its exact operation
receipt is reconciled; recovery-channel fallback must not execute the mutation a
second time. Running receipts and destination write locks are never stolen merely
because they are old. Preserve recovery evidence when Core/Asterisk compensation
reports `recovery_required`.

Managed TFTP transfers accept only recognized asset paths and the bounded retained
wallpaper/timezone subtrees. Generated phone configuration, lock/key state, path
traversal and symbolic-link ancestors are rejected. Source hashes and sizes bind
every staged destination file; destination revisions are checked before publication.
API and SFTP backup staging share one receipt and one destination-owned commit.
Only read-only API connectivity/service preflight can select the SFTP/SSH backup;
authentication, trust and capability failures cannot silently downgrade. Local
storage access still uses the configured jailed SFTP account and existing host pin.

Binary file requests retain encrypted metadata authentication and nonce replay
protection. File bodies use sodium secretstream with nonce/direction-derived keys,
fixed record bounds and a mandatory final tag; invalid or truncated input never
reaches publication. Private temporary files are closed after the request. PUT is
accepted only for authenticated binary uploads. After a lost acknowledgment, the
controller reconciles legacy whole-file and publication receipts without replay.
Negotiated rc19 private ranges are explicitly idempotent: an exact confirmed range
must match its retained digest, and an incomplete range may overwrite only its
unpublished span under the existing writer lock. Each retry has a fresh nonce and
operation receipt. Full-file hashes and destination revisions remain mandatory at
publication. This exception permits at most two same-API retries of private ranges;
it never permits a transport downgrade, commit replay or automatic lock removal.

## Network exposure

- Keep FreePBX administration and federation endpoints on a trusted management
  network or behind a correctly configured TLS reverse proxy.
- Prefer HTTPS with certificate verification for the federation API. Plain HTTP is
  supported for controlled private networks and for older phone firmware that
  cannot consume modern HTTPS, but it does not provide transport confidentiality.
- Restrict TFTP, SIP and legacy media endpoints to the voice network wherever
  possible.
- Do not expose a TFTP host's SSH service using password authentication after
  enrollment. The module-managed account must remain key-only, SFTP-only and jailed.
- Pin and verify PBX and TFTP host keys whenever an endpoint changes.
- Serve the employee directory from an internal HTTPS address and restrict the
  virtual host to the intended internal hostnames. Do not publish its endpoint to
  the Internet. In direct mode, allowing a proxy/NAT address allows everyone it
  forwards: restrict that audience upstream. No proxy-specific list is required
  for private connecting-address access. In optional trusted-
  proxy mode, the proxy must remove alternate forwarding headers and overwrite the
  configured `X-Real-IP` or single-IP `X-Forwarded-For`; an arbitrary forwarded
  address or proxy subnet is not trusted. The preferred URL setting does not create
  DNS, certificates, reverse-proxy routes or firewall rules.

Web phonebook access policy lives in the private `web-phonebook.json` file. It is
not exported or imported by configuration packages. The explicit `web_directory`
configuration-sync scope copies its normalized policy, never contacts, reader
secrets or inbound ownership claims. Job creation requires acknowledgement that
the source's networks, proxy mode, visible fields and export permission will apply
to the selected targets, including scheduled changes. Missing published book keys
leave the receiving policy untouched. Normal install upgrades only this policy
and its empty stable lock to mode 0640 with group asterisk. Atomic policy writes
retain that mode/group, allowing the web process and constrained SSH account to
read it without group-write on the file. The lock is opened read-only; managed
updates atomically replace the policy within the existing shared data directory.
AD reader-secret directories/files remain owner-only 0700/0600, unchanged.
The remote installer retains the normal PBX-wide `fwconsole chown` reset.
The module registers FreePBX's native `fwcChownFiles` permission hook. It declares
normal integer modes for existing module runtime storage and the module's existing
SSH key files; it does not run a separate chmod pass or modify framework policy.
The metadata snapshot is collected before framework defaults run, preserving
private work owned by root, asterisk or ucmp-pbx. Reader credentials remain
asterisk-owned 0700/0600; patch bundles remain root-owned 0750/0640. Retired media
retains its original payload/catalog modes behind private recovery directories so
rename-based restoration remains usable. Linked paths and unexpected service
owners/types stop declaration rather than being silently reassigned.
Shared media, cache and scheduler writers explicitly assign the existing service
group without depending on SGID. Permission failures are distinct from a busy lock.
Media-transfer staging and publish-mesh transaction records use this same group
(directories 0770, files 0660), so API and constrained SSH accounts can read and
update the same transaction. Mesh records include complete before/proposed copies
of pjsip_custom.conf; asterisk-group members are trusted with those contents and
transaction updates. Native permission resets retain each existing file owner.
Upgrades must normalize existing media-transfers and publish-mesh paths to these
modes with group asterisk before using both transports; an unprivileged process
cannot repair another account's old owner-only directory. Transfer identity,
content pins and commit reconciliation still govern replay and transport fallback.
This contract applies to the module's enabled native hook during normal FreePBX
resets; arbitrary external chmod/chown commands or administrator overrides can
still violate it. The hook is not a filesystem isolation boundary against a
concurrent privileged process changing paths during the reset.
Policy and source/job ownership metadata share one file lock and revision. A hard
lock rejects local saves even with a stale page or edit-confirmation flag; another
job cannot take over even with force. This is an application policy boundary, not
protection against host root or a compromised trusted federation administrator.
Soft local edits pause the policy; changing/deleting the owning job releases its
ownership, while disabling a schedule alone does not. Empty selections deny access.
Allowed fields are projected before browser delivery or export. All XLSX cells
are explicit text, including formula-looking values. Exports validate current
membership, fields and snapshot revision and never accept client-supplied cell
contents. Browser responses use no-store and no framing/CORS grants. Copies already
loaded, downloaded or printed cannot be revoked retroactively.

Retirement is reference-bound quarantine, not permanent deletion or immediate disk
reclamation. Incomplete database/storage inventories, active scans, generated aliases
and retained rollback references protect their assets. Restore requires the original
site/library and refuses conflicting newer bytes. Generic HTTP media transfers use
private per-transfer receipts, fixed content hashes, destination revision checks and
retained prior bytes; a transport acknowledgment is not handset acceptance.

## Deployment checklist

1. Verify the archive SHA-256 and `SOURCE-IDENTITY.json`.
2. Back up the database, Asterisk configuration and TFTP/media stores.
3. Install the same module version on every federated PBX.
4. Re-sign the installed module locally on each PBX.
5. Confirm `composer audit --locked` has no known advisory.
6. Validate the exact Asterisk/USECALLMANAGER pair and the expected Cisco runtime
   application before rebuilding Cisco XML phones.
7. Preview every Asterisk mesh or phone-file transaction before apply.
8. Canary one phone per firmware/configuration profile before fleet rollout.
9. Review the operation log and scheduled replication jobs after the change.

## Operational limitations that are not hidden

- Some older phones require plain HTTP and TFTP. The UI warns about that protocol
  boundary; the module cannot add confidentiality that the handset firmware lacks.
- Firmware uploaded for Yealink and Grandstream is treated as operator-supplied
  model data when the vendor does not provide a stable machine-verifiable naming
  scheme. Compatibility scope must be selected carefully.
- A phone firmware feature may render additional softkeys that are not serialized
  in a custom softkey XML file. Visual editor overlays are explanatory and are not
  transmitted unless the corresponding device setting is documented as writable.
- Patch Manager reduces routine command-line work but does not remove the need for
  a PBX backup, console access and a tested rollback plan.
- Candidate fixtures exercise failure, recovery and policy boundaries using
  synthetic data. Real FreePBX/Core and MySQL concurrency, LDAP, reverse-proxy
  deployment and representative physical-handset acceptance remain separate gates.
