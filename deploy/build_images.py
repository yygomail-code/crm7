#!/usr/bin/env python3
"""Build (and optionally push) CRM7 container images on the dev server.

Pipeline: build frontend -> pack api + dist + deploy files -> upload ->
docker build app+web tagged by version and git sha -> optional push to GHCR.

Usage:
    python deploy/build_images.py [--server root@172.30.171.254]
                                  [--key ~/.ssh/id_ed25519]
                                  [--registry ghcr.io/yygomail-code]
                                  [--tag 1.3.0] [--skip-build] [--push]

Requires: paramiko, Node.js for the frontend build, docker on the server.
"""
import argparse
import io
import json
import os
import pathlib
import subprocess
import sys
import tarfile

try:
    import paramiko
except ImportError:
    sys.exit("paramiko is required: pip install paramiko")

ROOT = pathlib.Path(__file__).resolve().parent.parent
EXCLUDE_PREFIXES = ("api/db/backups/", "api/var/", "api/storage/")
EXCLUDE_FILES = ("api/config/.env", "api/config/database.json")
DEPLOY_FILES = ("Dockerfile.app", "Dockerfile.web", "nginx.conf")


def git_sha() -> str:
    try:
        out = subprocess.check_output(
            ["git", "rev-parse", "--short", "HEAD"], cwd=ROOT, text=True
        ).strip()
        return out or "nogit"
    except Exception:
        return "nogit"


def package_version() -> str:
    try:
        return json.loads((ROOT / "frontend" / "package.json").read_text(encoding="utf-8"))["version"]
    except Exception:
        return "0.0.0"


def build_frontend() -> None:
    print("== building frontend ==")
    subprocess.run("npm run build", cwd=ROOT / "frontend", check=True, shell=(os.name == "nt"))


def make_context() -> bytes:
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        dist = ROOT / "frontend" / "dist"
        if not dist.is_dir():
            sys.exit(f"frontend build not found: {dist}")
        for path in sorted(dist.rglob("*")):
            tar.add(path, arcname=f"frontend/dist/{path.relative_to(dist).as_posix()}", recursive=False)

        api = ROOT / "api"
        for path in sorted(api.rglob("*")):
            rel = path.relative_to(ROOT).as_posix()
            if any(rel.startswith(p) for p in EXCLUDE_PREFIXES):
                continue
            if rel in EXCLUDE_FILES:
                continue
            tar.add(path, arcname=rel, recursive=False)

        for name in DEPLOY_FILES:
            path = ROOT / "deploy" / name
            tar.add(path, arcname=f"deploy/{name}", recursive=False)

        tar.add(ROOT / ".dockerignore", arcname=".dockerignore", recursive=False)
    return buf.getvalue()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--server", default="root@172.30.171.254")
    ap.add_argument("--key", default=str(pathlib.Path.home() / ".ssh" / "id_ed25519"))
    ap.add_argument("--registry", default="ghcr.io/yygomail-code")
    ap.add_argument("--tag", default=None)
    ap.add_argument("--skip-build", action="store_true")
    ap.add_argument("--push", action="store_true")
    args = ap.parse_args()

    if not args.skip_build:
        build_frontend()

    version = args.tag or package_version()
    sha = git_sha()
    app_image = f"{args.registry}/crm7-app"
    web_image = f"{args.registry}/crm7-web"
    print(f"version={version} sha={sha}")
    print(f"images: {app_image}  {web_image}")

    data = make_context()
    print(f"context: {len(data)} bytes")

    user, _, hostport = args.server.partition("@")
    if not hostport:
        user, hostport = "root", args.server
    host, _, port = hostport.partition(":")
    port = int(port or 22)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(host, port=port, username=user, key_filename=args.key, timeout=15)
    sftp = client.open_sftp()
    with sftp.open("/tmp/crm7-images.tgz", "wb") as handle:
        handle.write(data)
    sftp.close()
    print("uploaded")

    tags_app = f"-t {app_image}:{version} -t {app_image}:sha-{sha}"
    tags_web = f"-t {web_image}:{version} -t {web_image}:sha-{sha}"
    push = ""
    if args.push:
        push = (
            f"docker push {app_image}:{version} && docker push {app_image}:sha-{sha} && "
            f"docker push {web_image}:{version} && docker push {web_image}:sha-{sha}"
        )

    remote = f"""set -e
rm -rf /srv/build/crm7
mkdir -p /srv/build/crm7
tar -xzf /tmp/crm7-images.tgz -C /srv/build/crm7
cd /srv/build/crm7
docker build -f deploy/Dockerfile.app {tags_app} .
docker build -f deploy/Dockerfile.web {tags_web} .
{push}
echo BUILD_OK
"""

    stdin, stdout, stderr = client.exec_command(remote, timeout=900)
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    rc = stdout.channel.recv_exit_status()
    client.close()

    sys.stdout.write(out[-4000:])
    if err.strip():
        sys.stderr.write(err[-4000:])
    if rc != 0 or "BUILD_OK" not in out:
        print("build FAILED", file=sys.stderr)
        return 1
    print("build OK" + (" (pushed)" if args.push else ""))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
