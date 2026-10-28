#!/usr/bin/env python3
"""Manual launcher for CRM7 Docker image build/push/release.

Interactive by default; arguments allow the same flow without prompts:
    python deploy/update_images.py --mode full --env test --tag 1.3.0 --yes
    python deploy/update_images.py --mode push --tag 1.3.0 --dry-run
"""
import argparse
import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
DEPLOY = ROOT / "deploy"
TAG_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]*")

if str(DEPLOY) not in sys.path:
    sys.path.insert(0, str(DEPLOY))

try:
    import paramiko  # noqa: F401
except ImportError:
    sys.exit("paramiko is required: pip install paramiko")

from release_crm7 import ENVS  # noqa: E402

MODES = {
    "build": "Собрать образы (без публикации)",
    "push": "Собрать и опубликовать в GHCR",
    "release": "Выпустить готовый тег на окружение",
    "full": "Полный цикл: собрать, опубликовать, выпустить",
}
ENV_LABELS = {
    "test": "test — тестовый",
    "demo": "demo — демо",
    "k": "k — боевой (реальные данные)",
    "all": "all — все окружения, включая k",
}
ENV_ORDER = tuple(ENV_LABELS)
PRODUCTION_ENVS = ("k", "all")


def package_version() -> str:
    try:
        data = json.loads((ROOT / "frontend" / "package.json").read_text(encoding="utf-8"))
        return str(data["version"])
    except Exception:
        return "0.0.0"


def ask(prompt: str, default: str) -> str:
    answer = input(f"{prompt} [{default}]: ").strip()
    return answer or default


def choose(title: str, options: list[tuple[str, str]], default_index: int = 0) -> str:
    print(title)

    for index, (_, label) in enumerate(options, start=1):
        print(f"  {index}) {label}")

    while True:
        raw = input(f"Выбор [{default_index + 1}]: ").strip()

        if not raw:
            return options[default_index][0]

        try:
            index = int(raw) - 1
        except ValueError:
            index = -1

        if 0 <= index < len(options):
            return options[index][0]

        print("Нет такого пункта, попробуйте ещё раз.")


def confirm(question: str, default: bool) -> bool:
    suffix = "Y/n" if default else "y/N"
    answer = input(f"{question} [{suffix}]: ").strip().lower()

    if not answer:
        return default

    return answer in ("y", "yes", "д", "да")


def git_dirty() -> bool:
    try:
        result = subprocess.run(
            ["git", "status", "--porcelain"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        return bool(result.stdout.strip())
    except Exception:
        return False


def display(argv: list[str]) -> str:
    return "python " + " ".join(argv[1:])


def main() -> int:
    parser = argparse.ArgumentParser(description="Сборка, публикация и выпуск образов CRM7")
    parser.add_argument("--mode", choices=tuple(MODES))
    parser.add_argument("--env", choices=ENV_ORDER)
    parser.add_argument("--tag")
    parser.add_argument("--yes", action="store_true", help="не спрашивать подтверждение")
    parser.add_argument("--dry-run", action="store_true", help="только показать команды")
    args = parser.parse_args()

    if not sys.stdin.isatty() and not args.mode:
        print("Нет параметров и нет терминала: укажите --mode, --env и --tag.", file=sys.stderr)
        return 2

    if args.mode:
        mode = args.mode
    else:
        mode = choose(
            "Что сделать?",
            [(name, label) for name, label in MODES.items()],
            default_index=3,
        )

    needs_env = mode in ("release", "full")
    version = package_version()

    if needs_env:
        if args.env:
            env = args.env
        elif sys.stdin.isatty():
            env = choose(
                "Окружение:",
                [(name, ENV_LABELS[name]) for name in ENV_ORDER],
                default_index=0,
            )
        else:
            env = "test"
    else:
        env = None

    if args.tag:
        tag = args.tag
    elif sys.stdin.isatty():
        tag = ask("Тег (версия)", version)
    else:
        tag = version

    tag = tag.strip()

    if not TAG_RE.fullmatch(tag):
        print(
            f"Некорректный тег: {tag!r}. Разрешены латиница, цифры, точка, дефис и подчёркивание.",
            file=sys.stderr,
        )
        return 2

    commands: list[list[str]] = []

    if mode in ("build", "push", "full"):
        build = [sys.executable, "deploy/build_images.py", "--tag", tag]

        if mode in ("push", "full"):
            build.append("--push")

        commands.append(build)

    if needs_env:
        commands.append(
            [sys.executable, "deploy/release_crm7.py", "--env", str(env), "--tag", tag]
        )

    print()
    print(f"Режим: {MODES[mode]}")
    print(f"Тег: {tag}")

    if env:
        print(f"Окружение: {env}")

    print("Будет выполнено:")

    for command in commands:
        print(f"  {display(command)}")

    if mode != "release" and git_dirty():
        print()
        print(
            "ВНИМАНИЕ: есть незакоммиченные изменения. Образ соберётся из рабочего каталога, "
            "а тег sha-<commit> будет указывать на HEAD."
        )

    if env in PRODUCTION_ENVS:
        print()
        print("ВНИМАНИЕ: это боевое окружение (k) или все окружения, включая k.")

    if args.dry_run:
        print()
        print("Режим --dry-run: команды не запускались.")
        return 0

    if not args.yes and not confirm("Продолжить?", default=env not in PRODUCTION_ENVS):
        print("Отменено.")
        return 0

    for command in commands:
        print()
        print(f"+ {display(command)}")

        result = subprocess.run(command, cwd=ROOT)

        if result.returncode != 0:
            print(
                f"Шаг завершился с кодом {result.returncode}. "
                "Проверьте VPN/SSH, исправьте ошибку и запустите снова.",
                file=sys.stderr,
            )
            return result.returncode

    print()
    print("Готово.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (KeyboardInterrupt, EOFError):
        print("\nОтменено.")
        raise SystemExit(130)
