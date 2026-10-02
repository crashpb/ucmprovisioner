#!/usr/bin/env python3
"""Build a deterministic FreePBX module archive from this source tree."""

from __future__ import annotations

import gzip
import hashlib
import io
import json
import pathlib
import re
import sys
import tarfile


ROOT = pathlib.Path(__file__).resolve().parent.parent
PROJECT = "ucmprovisioner"
EXCLUDED_PARTS = {".git", ".idea", ".vscode", "__pycache__", ".pytest_cache"}
EXCLUDED_NAMES = {".DS_Store", "Thumbs.db", "module.sig"}
EXCLUDED_SUFFIXES = {".pyc", ".pyo", ".tgz", ".tar", ".gz"}
FORBIDDEN_NAMES = {".env", ".htpasswd"}
FORBIDDEN_SUFFIXES = {".key", ".local", ".log", ".p12", ".pem", ".pfx", ".secret"}


def module_version() -> str:
    text = (ROOT / "module.xml").read_text(encoding="utf-8")
    match = re.search(r"<version>([^<]+)</version>", text)
    if not match or not re.fullmatch(r"[0-9A-Za-z.+~-]+", match.group(1)):
        raise SystemExit("module.xml has no safe version")
    return match.group(1)


def source_files() -> list[pathlib.Path]:
    files = []
    for path in ROOT.rglob("*"):
        relative = path.relative_to(ROOT)
        if any(part in EXCLUDED_PARTS for part in relative.parts):
            continue
        if path.name in FORBIDDEN_NAMES or path.suffix.lower() in FORBIDDEN_SUFFIXES:
            raise SystemExit(f"release source contains a private or local file: {relative}")
        if path.name in EXCLUDED_NAMES:
            continue
        if path.is_symlink():
            raise SystemExit(f"release source contains a symbolic link: {relative}")
        if path.is_dir():
            continue
        if not path.is_file():
            raise SystemExit(f"release source contains a special file: {relative}")
        if path.suffix.lower() in EXCLUDED_SUFFIXES:
            continue
        files.append(path)
    return sorted(files, key=lambda item: item.relative_to(ROOT).as_posix())


def mode_for(relative: str) -> int:
    executable = {
        "resources/ucmp-fail2ban",
        "resources/ucmp-patch-manager",
        "tools/build-release.py",
    }
    return 0o755 if relative in executable else 0o644


def tar_info(name: str, size: int, mode: int) -> tarfile.TarInfo:
    info = tarfile.TarInfo(name)
    info.size = size
    info.mode = mode
    info.mtime = 0
    info.uid = info.gid = 0
    info.uname = info.gname = "root"
    return info


def main() -> int:
    version = module_version()
    output_dir = (
        pathlib.Path(sys.argv[1]).resolve()
        if len(sys.argv) > 1
        else ROOT.parent.parent / "dist"
    )
    output_dir.mkdir(parents=True, exist_ok=True)
    output = output_dir / f"{PROJECT}-{version}.tgz"

    entries = []
    file_payloads: list[tuple[str, bytes, int]] = []
    for path in source_files():
        relative = path.relative_to(ROOT).as_posix()
        payload = path.read_bytes()
        if relative == "Ucmprovisioner.class.php" and b"\r\n" in payload:
            raise SystemExit("Ucmprovisioner.class.php must use LF: it embeds executable Linux agent and dispatcher scripts")
        mode = mode_for(relative)
        entries.append(
            {
                "path": relative,
                "bytes": len(payload),
                "sha256": hashlib.sha256(payload).hexdigest(),
                "mode": f"{mode:04o}",
            }
        )
        file_payloads.append((relative, payload, mode))

    canonical_entries = json.dumps(
        entries, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")
    identity = {
        "schema_version": 1,
        "module": PROJECT,
        "version": version,
        "source_sha256": hashlib.sha256(canonical_entries).hexdigest(),
        "files": entries,
    }
    identity_payload = (
        json.dumps(identity, ensure_ascii=False, sort_keys=True, indent=2) + "\n"
    ).encode("utf-8")

    raw_tar = io.BytesIO()
    with tarfile.open(fileobj=raw_tar, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        directory = tar_info(f"{PROJECT}/", 0, 0o755)
        directory.type = tarfile.DIRTYPE
        archive.addfile(directory)
        for relative, payload, mode in file_payloads:
            archive.addfile(
                tar_info(f"{PROJECT}/{relative}", len(payload), mode),
                io.BytesIO(payload),
            )
        archive.addfile(
            tar_info(
                f"{PROJECT}/SOURCE-IDENTITY.json",
                len(identity_payload),
                0o644,
            ),
            io.BytesIO(identity_payload),
        )

    with output.open("wb") as destination:
        with gzip.GzipFile(fileobj=destination, mode="wb", filename="", mtime=0) as zipped:
            zipped.write(raw_tar.getvalue())

    package_hash = hashlib.sha256(output.read_bytes()).hexdigest()
    checksum = output.with_suffix(output.suffix + ".sha256")
    checksum.write_text(f"{package_hash}  {output.name}\n", encoding="ascii", newline="\n")
    manifest = output.with_suffix(output.suffix + ".source.json")
    manifest.write_bytes(identity_payload)
    print(
        json.dumps(
            {
                "archive": str(output),
                "archive_sha256": package_hash,
                "source_sha256": identity["source_sha256"],
                "files": len(entries),
            },
            separators=(",", ":"),
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
