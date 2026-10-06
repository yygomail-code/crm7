#!/usr/bin/env python3
"""Release a CRM7 image version to one or more environments.

For each environment: set CRM7_VERSION -> (optionally pull) -> run migrations
-> recreate containers -> verify health. Data volumes are untouched.

Usage:
    python deploy/release_crm7.py --env all --tag 1.3.0
    python deploy/release_crm7.py --env test --tag sha-cefec9b --no-pull
    python deploy/release_crm7.py --env k --tag 1.4.0
"""
import argparse
import pathlib
import sys

try:
    import paramiko
except ImportError:
    sys.exit("paramiko is required: pip install paramiko")

ENVS = {
    "test": "/srv/projects/crm7-test",
    "k": "/srv/projects/crm7-k",
    "demo": "/srv/projects/crm7-demo",
}


def remote_block(env: str, directory: str, tag: str, pull: bool) -> str:
    pull_cmd = "docker compose pull" if pull else "echo '(skip pull)'"
    return f"""echo "=== {env} ==="
cd {directory}
sed -i 's/^CRM7_VERSION=.*/CRM7_VERSION={tag}/' .env
grep -q '^CRM7_VERSION=' .env || echo 'CRM7_VERSION={tag}' >> .env
{pull_cmd}
docker compose run --rm php php /var/www/html/api/bin/migrate.php
docker compose up -d
sleep 4
docker compose ps --format "{{{{.Name}}}} {{{{.Status}}}}"
docker exec crm7-{env}-web wget -qO- http://127.0.0.1/api/v2/health
echo "RELEASE_OK_{env}"
"""


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--env", required=True, help="test|k|demo|all")
    ap.add_argument("--tag", required=True)
    ap.add_argument("--no-pull", action="store_true")
    ap.add_argument("--server", default="root@172.30.171.254")
    ap.add_argument("--key", default=str(pathlib.Path.home() / ".ssh" / "id_ed25519"))
    args = ap.parse_args()

    envs = list(ENVS) if args.env == "all" else [args.env]
    for env in envs:
        if env not in ENVS:
            sys.exit(f"unknown env: {env}")

    script = "set -e\n" + "\n".join(
        remote_block(env, ENVS[env], args.tag, not args.no_pull) for env in envs
    )

    user, _, hostport = args.server.partition("@")
    if not hostport:
        user, hostport = "root", args.server
    host, _, port = hostport.partition(":")
    port = int(port or 22)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(host, port=port, username=user, key_filename=args.key, timeout=15)
    stdin, stdout, stderr = client.exec_command(script, timeout=600)
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    rc = stdout.channel.recv_exit_status()
    client.close()

    sys.stdout.write(out)
    if err.strip():
        sys.stderr.write(err)

    missing = [env for env in envs if f"RELEASE_OK_{env}" not in out]
    if rc != 0 or missing:
        print(f"release FAILED (envs without OK: {missing})", file=sys.stderr)
        return 1
    print(f"release OK: {', '.join(envs)} @ {args.tag}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
