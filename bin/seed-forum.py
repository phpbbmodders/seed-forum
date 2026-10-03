#!/usr/bin/env python3
"""Manage a disposable phpBB board from YAML configuration and CLI options.

Read config variables, extension checkouts, and associated PHP seed scripts.
Print validation results, status, or an operation plan to stdout; report
failures to stderr. Mutations require an explicit operation, and --dry-run
prints subprocess commands without executing them or updating state files.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import sys

REPO = Path(__file__).resolve().parent.parent
VARIABLE = re.compile(r"\$\{([A-Za-z_][A-Za-z0-9_]*)\}")
PACKAGE = re.compile(r"[a-z0-9_.-]+/[a-z0-9_.-]+")


def fail(message):
    """Raise a validation error for the CLI's stderr handler."""
    raise ValueError(message)


def run(argv, cwd=None, env=None, dry=False):
    """Print an argv-based command and execute it unless dry is true.

    cwd and env apply only to the child process. A nonzero exit stops the
    operation; commands never pass through a shell for argument expansion.
    """
    print("+ " + shlex.join([str(arg) for arg in argv]), flush=True)
    if not dry:
        subprocess.run([str(arg) for arg in argv], cwd=cwd, env=env, check=True)


def mapping(value, context, allowed=None):
    """Return a config mapping, rejecting wrong types and unknown keys."""
    if not isinstance(value, dict):
        fail(f"{context} must be a mapping")
    if allowed is not None and set(value) - allowed:
        fail(f"Unknown keys in {context}: {', '.join(sorted(set(value) - allowed))}")
    return value


def load_config(path):
    """Read YAML and return resolved board paths, settings, seeds, and extensions.

    Environment values override named variable defaults. Board/source paths
    are relative to the config file, while seed paths are relative to REPO.
    Parsing and interpolation do not modify the board or evaluate shell code.
    """
    try:
        import yaml
    except ImportError:
        fail("YAML support requires Python PyYAML (python3-yaml).")

    class UniqueLoader(yaml.SafeLoader):
        """Use safe YAML construction with duplicate mapping keys rejected."""
        pass

    def unique(loader, node, deep=False):
        """Construct string-keyed mappings without silently replacing entries."""
        result = {}
        for key_node, value_node in node.value:
            key = loader.construct_object(key_node, deep=deep)
            if not isinstance(key, str):
                fail("Config mapping keys must be strings")
            if key in result:
                fail(f"Duplicate config key: {key}")
            result[key] = loader.construct_object(value_node, deep=deep)
        return result

    UniqueLoader.add_constructor(yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, unique)
    try:
        document = yaml.load(path.read_text(), Loader=UniqueLoader)
    except yaml.YAMLError as error:
        fail(f"Invalid YAML: {error}")
    mapping(document, "config", {"variables", "board", "extensions", "styles"})
    defaults = mapping(document.get("variables", {}), "variables")
    resolved = {}

    def variable(name, stack=()):
        """Resolve one named variable recursively, rejecting reference cycles."""
        if name in resolved:
            return resolved[name]
        if name in stack:
            fail(f"Circular variable reference: {' -> '.join((*stack, name))}")
        value = os.environ.get(name, defaults.get(name))
        if value is None:
            fail(f"Undefined variable: {name}")
        if not isinstance(value, (str, int)) or isinstance(value, bool):
            fail(f"Variable {name} must be a string or integer")
        value = VARIABLE.sub(lambda match: variable(match[1], (*stack, name)), str(value))
        resolved[name] = value
        return value

    def expand(value):
        """Expand named references in a nonempty config string and validate it."""
        if not isinstance(value, str) or not value.strip():
            fail("Paths and config strings must be nonempty strings")
        result = VARIABLE.sub(lambda match: variable(match[1]), value)
        if "${" in result or "\n" in result or "\x00" in result:
            fail(f"Invalid variable reference or control character: {value!r}")
        return result

    for name in defaults:
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", name):
            fail(f"Invalid variable name: {name}")
        variable(name)

    def path_value(value, base):
        """Return an absolute path after variable and home-directory expansion."""
        path = Path(expand(value)).expanduser()
        return (base / path).resolve() if not path.is_absolute() else path.resolve()

    def scripts(items, context):
        """Resolve a seed list to PHP paths; existence is checked when needed."""
        if not isinstance(items, list):
            fail(f"{context} must be a list")
        result = [path_value(item, REPO) for item in items]
        if any(script.suffix != ".php" for script in result):
            fail(f"{context} must contain PHP scripts")
        return result

    board = mapping(document.get("board"), "board", {"root", "seeds", "server_name", "server_port", "nginx_site"})
    root = path_value(board.get("root"), path.parent)
    if root == Path(root.anchor) or root == REPO or root in REPO.parents:
        fail("board.root must be a dedicated board directory")
    port = expand(str(board.get("server_port", "8092")))
    if not port.isdigit() or not 1 <= int(port) <= 65535:
        fail("board.server_port must be between 1 and 65535")
    result = {
        "root": root,
        "server_name": expand(board.get("server_name", "localhost")),
        "server_port": port,
        "nginx_site": path_value(board["nginx_site"], path.parent) if board.get("nginx_site") else None,
        "seeds": scripts(board.get("seeds", []), "board.seeds"),
        "extensions": {},
        "styles": {},
    }
    for label, entry in mapping(document.get("extensions", {}), "extensions").items():
        if not re.fullmatch(r"[A-Za-z0-9_-]+", label):
            fail(f"Invalid extension label: {label}")
        mapping(entry, f"extensions.{label}", {"source", "seeds"})
        result["extensions"][label] = {
            "source": path_value(entry.get("source"), path.parent),
            "seeds": scripts(entry.get("seeds", []), f"extensions.{label}.seeds"),
        }
    for label, entry in mapping(document.get("styles", {}), "styles").items():
        if not re.fullmatch(r"[A-Za-z0-9_-]+", label) or label.lower() in {"all", "adm", "admin", "prosilver"}:
            fail(f"Invalid custom style directory: {label}")
        mapping(entry, f"styles.{label}", {"source", "default"})
        default = entry.get("default", False)
        if not isinstance(default, bool):
            fail(f"styles.{label}.default must be a boolean")
        result["styles"][label] = {"source": path_value(entry.get("source"), path.parent), "default": default}
    if sum(style["default"] for style in result["styles"].values()) > 1:
        fail("Only one style may be the default")
    return result


def package(source):
    """Read a checkout's Composer name and require a safe vendor/name path."""
    document = json.loads((source / "composer.json").read_text())
    mapping(document, f'{source}/composer.json')
    name = document.get("name", "")
    if not isinstance(name, str) or not PACKAGE.fullmatch(name) or any(part in (".", "..") for part in name.split("/")):
        fail(f"Invalid composer package name in {source}")
    return name


def is_mounted(path):
    """Detect mount points, including bind mounts on the same filesystem."""
    if not Path(path).exists():
        return False
    result = subprocess.run(['mountpoint', '-q', '--', str(path)], check=False)
    if result.returncode not in (0, 32):
        fail(f"Cannot determine mount state: {path}")
    return result.returncode == 0


def managed(root):
    """Return recorded mount targets confined to root/ext plus the legacy mount.

    Records are retained even when a target is no longer mounted, so callers
    can reconcile state after an interrupted operation or reboot.
    """
    manifest = root / ".seed-forum-mounted-extensions"
    targets = []
    if manifest.exists():
        for line in manifest.read_text().splitlines():
            if not line:
                continue
            target = Path(line)
            try:
                parts = target.relative_to(root / "ext").parts
            except ValueError:
                fail(f"Managed mount outside board ext directory: {target}")
            if len(parts) != 2 or not PACKAGE.fullmatch("/".join(parts)) or any(part in (".", "..") for part in parts):
                fail(f"Invalid managed mount: {target}")
            if not target.parent.resolve().is_relative_to(root):
                fail(f"Managed mount parent escapes board root: {target}")
            targets.append(target)
    legacy = root / "ext/phpbbmodders/knowledgebase"
    if legacy not in targets and is_mounted(legacy):
        targets.append(legacy)
    return targets


def save_mounts(root, targets):
    """Write the remaining managed targets, removing an empty manifest."""
    manifest = root / ".seed-forum-mounted-extensions"
    if targets:
        manifest.write_text("".join(f"{path}\n" for path in targets))
    else:
        manifest.unlink(missing_ok=True)


def cli(root, command, dry=False):
    """Run a noninteractive phpBB command from the configured board root."""
    run(["php", root / "bin/phpbbcli.php", *command, "--no-interaction"], cwd=root, dry=dry)


def main():
    """Validate CLI/config inputs, then execute operations in dependency order.

    Reset is the only database-wiping operation. Disable precedes unmount,
    enable follows mount, and cache purge follows seeding. Validation and
    status return before the mutation path.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=REPO / "config/local.yml")
    for option, help_text in {
        "reset": "wipe/reinstall, mount/enable configured extensions, then seed",
        "mount": "mount selected extensions, keeping existing mounts",
        "remount": "replace managed mounts with selected extensions",
        "unmount": "unmount all managed extensions, or those selected by --only",
        "enable": "enable selected extensions through phpBB CLI",
        "disable": "disable selected extensions through phpBB CLI",
        "seed-only": "run seed scripts once on an existing board",
        "install-styles": "copy/install configured styles and select the default without resetting",
        "skip-seed": "skip seeds during reset",
        "purge-cache": "purge phpBB cache independently or after other operations",
        "dry-run": "validate and print actions without changing anything",
        "status": "show configured port/URL, sources, mounts, and extension state",
        "validate": "validate config, sources, and PHP seed syntax",
    }.items():
        parser.add_argument("--" + option, action="store_true", help=help_text)
    parser.add_argument("--only", help="comma-separated config labels; excludes board seeds")
    args = parser.parse_args()
    operations = [args.reset, args.mount, args.remount, args.unmount, args.enable, args.disable, args.seed_only, args.purge_cache, args.status, args.validate, args.install_styles]
    if not any(operations):
        if args.only or args.skip_seed or args.dry_run:
            parser.error("Select an operation; see --help")
        parser.print_help()
        return
    if sum([args.reset, args.mount, args.remount, args.unmount]) > 1:
        parser.error("Choose one of --reset, --mount, --remount, --unmount")
    if args.enable and args.disable:
        parser.error("--enable and --disable cannot be combined")
    if args.skip_seed and not args.reset:
        parser.error("--skip-seed requires --reset")
    if args.reset and (args.disable or args.seed_only):
        parser.error("--reset cannot be combined with --disable or --seed-only")
    if args.unmount and (args.enable or args.seed_only):
        parser.error("--unmount cannot be combined with --enable or --seed-only")
    if args.disable and args.seed_only:
        parser.error("--disable cannot be combined with --seed-only")
    if args.only is not None and not args.only:
        parser.error('--only requires at least one extension label')
    if (args.status or args.validate) and (any(operations[:8]) or args.install_styles):
        parser.error("--status and --validate cannot be combined with changes")

    config = load_config(args.config.resolve())
    root = config["root"]
    if args.reset or args.install_styles or args.validate:
        for label, style in config["styles"].items():
            source = style["source"]
            target = root / "styles" / label
            if not (source / "style.cfg").is_file():
                fail(f"Missing style.cfg: {source}")
            if target.is_symlink() or is_mounted(target) or not target.parent.resolve().is_relative_to(root):
                fail(f"Style target must be an ordinary directory inside the board: {target}")
            if source == target.resolve() or source.is_relative_to(target.resolve()) or target.resolve().is_relative_to(source):
                fail(f"Style source and target must be separate directories: {source}")
    labels = list(config["extensions"])
    if args.only:
        requested = args.only.split(",")
        if any(label not in labels for label in requested):
            fail("--only contains unknown extension labels")
        labels = [label for label in labels if label in requested]
    extensions = [(label, config["extensions"][label]) for label in labels]
    # Missing checkouts must not prevent cache purge or cleanup of old mounts.
    needs_sources = args.reset or args.mount or args.remount or args.validate
    names_file = root / '.seed-forum-extension-names.json'
    saved_names = mapping(json.loads(names_file.read_text()), 'saved extension names') if names_file.exists() else {}
    names = set()
    for label, extension in extensions:
        try:
            extension["name"] = package(extension["source"])
        except (OSError, ValueError) as error:
            extension['name'] = saved_names.get(label)
            if needs_sources or (not extension['name'] and (args.enable or args.disable or args.seed_only or (args.unmount and args.only))):
                fail(f"{label}: {error}")
        if extension["name"]:
            if not isinstance(extension['name'], str) or not PACKAGE.fullmatch(extension['name']) or any(part in ('.', '..') for part in extension['name'].split('/')):
                fail(f"Invalid saved package name for {label}")
            if extension["name"] in names:
                fail(f"Duplicate package name: {extension['name']}")
            names.add(extension["name"])
    # A label selection runs only extension seeds, not the board-wide fixtures.
    seeds = ([] if args.only else config["seeds"]) + [script for _, ext in extensions for script in ext["seeds"]]
    if len(seeds) != len(set(seeds)):
        fail("Each seed script may appear only once in the selected config")
    if args.validate or args.seed_only or (args.reset and not args.skip_seed):
        for script in seeds:
            if not script.is_file():
                fail(f"Missing seed script: {script}")
            subprocess.run(["php", "-l", str(script)], check=True, stdout=subprocess.DEVNULL)
    targets = managed(root)
    for _, extension in extensions:
        if extension['name']:
            parent = (root / 'ext' / extension['name']).parent
            if not parent.resolve().is_relative_to(root):
                fail(f"Extension parent escapes board root: {parent}")
    if args.validate:
        print(f"Valid: {args.config} ({len(extensions)} extensions, {len(seeds)} seeds)")
        return
    if args.status:
        print(f"Board: {root} ({'installed' if (root / 'config.php').is_file() else 'not installed'})")
        # Configured values do not imply that nginx is listening on this port.
        print(f"Configured port: {config['server_port']}")
        print(f"Configured URL: http://{config['server_name']}:{config['server_port']}/")
        for label, style in config["styles"].items():
            print(f"Style: {label}; source={style['source']}; default={style['default']}")
        for label, extension in extensions:
            name = extension["name"]
            mounted = bool(name and is_mounted(root / "ext" / name))
            print(f"{label}: {name or 'missing/invalid composer.json'}; source={extension['source']}; mounted={mounted}")
        for target in targets:
            print(f"Managed: {target}; mounted={is_mounted(target)}")
        if (root / "config.php").is_file():
            if args.dry_run:
                run(['php', REPO / 'bin/extension-state.php', root], dry=True)
            else:
                state = json.loads(subprocess.check_output(['php', str(REPO / 'bin/extension-state.php'), str(root)], cwd=root, text=True))
                print('Enabled: ' + ', '.join(state['enabled']))
                print('Disabled: ' + ', '.join(state['disabled']))
        return
    if not args.reset and not args.unmount:
        if not (root / "config.php").is_file() or not (root / "bin/phpbbcli.php").is_file():
            fail(f"Operation requires an installed board at {root}")
    if args.unmount and not root.is_dir():
        fail(f"No board directory at {root}")
    if args.reset and root.exists() and any(root.iterdir()):
        if not (root / 'bin/phpbbcli.php').is_file() or not any((root / directory).is_dir() for directory in ('install', 'install.disabled')):
            fail(f'Nonempty reset directory is not a phpBB source tree: {root}')
    if args.mount:
        for _, extension in extensions:
            if is_mounted(root / "ext" / extension["name"]):
                fail(f"Already mounted: {extension['name']}; use --remount")
    if args.enable and not (args.mount or args.remount or args.reset):
        for _, extension in extensions:
            if not (root / "ext" / extension["name"] / "composer.json").is_file():
                fail(f"Extension is not mounted/installed: {extension['name']}")
    if args.seed_only:
        # Reject prior attempts before enable/mount commands can change state.
        # run-seed.php repeats this check while holding its execution lock.
        ledger = root / ".seed-forum-seeds.json"
        previous = json.loads(ledger.read_text()) if ledger.exists() else {}
        for script in seeds:
            if str(script) in previous:
                fail(f"Seed already attempted ({previous[str(script)]}): {script}; reset before repeating")
        if not args.dry_run and not args.enable:
            state = json.loads(subprocess.check_output(['php', str(REPO / 'bin/extension-state.php'), str(root)], cwd=root, text=True))
            for label, extension in extensions:
                if extension['seeds'] and extension['name'] not in state['enabled']:
                    fail(f"Enable {label} before running its seeds")

    required = ['php']
    if args.reset:
        required += ['bash', 'jq', 'sqlite3', 'curl', 'unzip', 'sha256sum', 'sudo']
    elif args.mount or args.remount or args.unmount or args.enable or args.disable or args.purge_cache or args.seed_only or args.install_styles:
        required += ['sudo']
    for command in required:
        if not shutil.which(command):
            fail(f"Required command not found: {command}")

    if args.reset:
        if args.dry_run:
            print(f'Reset: remove config.php and store/sqlite.db under {root}, reinstall phpBB, mount and enable: {", ".join(sorted(names)) or "no extensions"}')
        env = os.environ.copy()
        env.update(PHPBB_ROOT=str(root), SEED_FORUM_CONFIGURED="1", SERVER_NAME=config["server_name"], SERVER_PORT=config["server_port"], NGINX_SITE=str(config["nginx_site"] or root / ".no-nginx-site"), SEED_FORUM_NGINX_ENABLED='1' if config['nginx_site'] else '0')
        if config['nginx_site']:
            # Validate site shape and new-site socket selection before wiping data.
            subprocess.run(['python3', str(REPO / 'bin/nginx-site.py'), '--check'], env=env, check=True)
            if args.dry_run:
                print(f'nginx: create/update and enable {config["nginx_site"]}, set root {root} and port {config["server_port"]}, validate and reload')
        run(["bash", REPO / "bin/rebuild-board.sh", *[ext["source"] for _, ext in extensions]], env=env, dry=args.dry_run)
        if not args.dry_run:
            (root / ".seed-forum-seeds.json").unlink(missing_ok=True)
            names_file.write_text(json.dumps({label: ext['name'] for label, ext in extensions}, indent=2))
    if args.disable:
        # Reverse config order when disabling extensions that may depend on peers.
        for _, extension in reversed(extensions):
            cli(root, ["extension:disable", extension["name"]], dry=args.dry_run)
    if args.remount or args.unmount:
        selected = {root / "ext" / ext["name"] for _, ext in extensions if ext["name"]}
        removing = targets if args.remount or not args.only else [target for target in targets if target in selected]
        for target in removing:
            if is_mounted(target):
                run(["sudo", "umount", target], dry=args.dry_run)
            targets = [entry for entry in targets if entry != target]
            if not args.dry_run:
                # Persist each success so a later busy mount remains retryable.
                save_mounts(root, targets)
        if not args.dry_run:
            save_mounts(root, targets)
    if args.mount or args.remount:
        for label, extension in extensions:
            source = extension["source"]
            target = root / "ext" / extension["name"]
            if is_mounted(target) and not args.dry_run:
                fail(f"Already mounted: {target}")
            if target.is_symlink():
                run(["rm", "--", target], dry=args.dry_run)
            run(["mkdir", "-p", target], dry=args.dry_run)
            run(["sudo", "mount", "--bind", source, target], dry=args.dry_run)
            if target not in targets:
                targets.append(target)
            if not args.dry_run:
                save_mounts(root, targets)
                saved_names[label] = extension['name']
                names_file.write_text(json.dumps(saved_names, indent=2))
    if args.enable and not args.reset:
        for _, extension in extensions:
            cli(root, ["extension:enable", extension["name"]], dry=args.dry_run)
    if args.seed_only or (args.reset and not args.skip_seed):
        for script in seeds:
            run(["php", REPO / "bin/run-seed.php", root, script], dry=args.dry_run)
    if args.reset or args.install_styles:
        for label, style in config["styles"].items():
            target = root / "styles" / label
            print(f"Copy style: {style['source']} -> {target}", flush=True)
            if not args.dry_run:
                shutil.copytree(style["source"], target, dirs_exist_ok=True, ignore=shutil.ignore_patterns('.git'))
            run(["php", REPO / "bin/install-style.php", root, label, "1" if style["default"] else "0"], dry=args.dry_run)
    if args.purge_cache or ((args.reset or args.install_styles) and config["styles"]):
        cli(root, ["cache:purge"], dry=args.dry_run)
    if args.enable or args.disable or args.seed_only or (args.reset and not args.skip_seed) or args.purge_cache or args.install_styles or (args.reset and config["styles"]):
        # CLI-created cache files must remain writable by the web-server group.
        run(["sudo", "chgrp", "-R", "www-data", root / "cache"], dry=args.dry_run)
        run(["sudo", "chmod", "-R", "g+rwX", root / "cache"], dry=args.dry_run)
    print("Dry run complete; no changes made." if args.dry_run else "Done.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print(f"Error: {error}", file=sys.stderr)
        sys.exit(1)
