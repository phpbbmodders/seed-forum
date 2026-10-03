#!/usr/bin/env python3
"""Create/enable a board's nginx site or update its root, host, and listen port.

Read PHPBB_ROOT, SERVER_NAME, SERVER_PORT, and NGINX_SITE from the reset
backend's environment. PHP_FPM_SOCKET optionally selects the socket for a new
site; otherwise discover the installed socket. --check validates the plan
without writing. Apply changes through sudo, validate nginx, and reload it;
restore the previous file/link if validation or reload fails.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

REPO = Path(__file__).resolve().parent.parent


def prepare(environ):
    """Return site/link paths and rendered text without altering the host."""
    site = Path(environ['NGINX_SITE'])
    if site.parent.name != 'sites-available' or not re.fullmatch(r'[A-Za-z0-9_.-]+', site.name):
        raise ValueError('nginx_site must name a file under nginx sites-available')
    enabled = site.parent.parent / 'sites-enabled' / site.name
    if enabled.is_symlink():
        if enabled.resolve() != site.resolve():
            raise ValueError(f'{enabled} already points to a different site')
    elif enabled.exists():
        raise ValueError(f'{enabled} is an existing file, not this site\'s symlink')
    root = environ['PHPBB_ROOT']
    host = environ['SERVER_NAME']
    port = environ['SERVER_PORT']
    if any(char in root for char in ('$', '\n', '\r', '\x00')) or not Path(root).is_absolute():
        raise ValueError('Board root must be an absolute nginx-safe path')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+', host):
        raise ValueError('server_name must be a hostname without nginx directives')
    if not port.isdigit() or not 1 <= int(port) <= 65535:
        raise ValueError('server_port must be between 1 and 65535')
    quoted_root = json.dumps(root.rstrip('/') + '/', ensure_ascii=False)
    if site.exists():
        text = site.read_text()
        if len(re.findall(r'^\s*server\s*\{', text, re.M)) != 1:
            raise ValueError('Automatic updates require one server block per board site')
        for directive, value in [('root', quoted_root), ('server_name', host)]:
            text, count = re.subn(r'^(\s*' + directive + r'\s+)[^;\n]+;', lambda match: match[1] + value + ';', text, flags=re.M)
            if count != 1:
                raise ValueError(f'Expected one {directive} directive in {site}')

        def listen(match):
            """Preserve each listener's address and flags while changing its port."""
            address = match[2]
            if address.isdigit():
                address = port
            elif re.fullmatch(r'(?:\[[^\]]+\]|[^:]+):[0-9]+', address):
                address = address.rsplit(':', 1)[0] + ':' + port
            else:
                raise ValueError(f'Unsupported listener: {address}')
            return match[1] + address + match[3]

        text, count = re.subn(r'^(\s*listen\s+)([^\s;]+)([^\n]*;)', listen, text, flags=re.M)
        if not count:
            raise ValueError(f'No listen directive in {site}')
    else:
        socket = environ.get('PHP_FPM_SOCKET')
        if not socket:
            default = Path('/run/php/php-fpm.sock')
            sockets = list(Path('/run/php').glob('php*-fpm.sock'))
            if default.exists():
                socket = str(default.resolve())
            elif len(sockets) == 1:
                socket = str(sockets[0])
            else:
                raise ValueError('Set PHP_FPM_SOCKET to select an installed PHP-FPM socket')
        if not Path(socket).is_socket() or not re.fullmatch(r'/[A-Za-z0-9_./-]+', socket):
            raise ValueError(f'PHP-FPM socket is missing or invalid: {socket}')
        text = (REPO / 'config/nginx-site.conf.example').read_text()
        for token, value in {'BOARD_ROOT': quoted_root, 'PORT': port, 'SERVER_NAME': host, 'PHP_FPM_SOCKET': socket, 'SITE_NAME': site.stem}.items():
            text = text.replace('{{' + token + '}}', value)
    return site, enabled, text


def apply(site, enabled, text):
    """Install a prepared site transactionally, retaining existing permissions."""
    previous = site.read_bytes() if site.exists() else None
    link_existed = enabled.is_symlink()
    changed = previous is None or previous.decode() != text
    if not changed and link_existed:
        # An enabled file may not yet be loaded by the running nginx process.
        subprocess.run(['sudo', 'nginx', '-t', '-q'], check=True)
        subprocess.run(['sudo', 'systemctl', 'reload-or-restart', 'nginx'], check=True)
        print(f'==> nginx site already matches: {site}')
        return

    def sudo(*args):
        subprocess.run(['sudo', *map(str, args)], check=True)

    with tempfile.TemporaryDirectory(prefix='seed-forum-nginx-') as directory:
        candidate = Path(directory) / 'site.conf'
        backup = Path(directory) / 'previous.conf'
        candidate.write_text(text)
        if previous is not None:
            backup.write_bytes(previous)
        mode = format(site.stat().st_mode & 0o777, '04o') if previous is not None else '0644'
        installed = linked = False
        try:
            if changed:
                sudo('install', '-m', mode, candidate, site)
                installed = True
            if not link_existed:
                sudo('ln', '-s', site, enabled)
                linked = True
            sudo('nginx', '-t', '-q')
            sudo('systemctl', 'reload-or-restart', 'nginx')
        except (OSError, subprocess.CalledProcessError):
            # Restore only changes made by this invocation; keep unrelated sites.
            if linked:
                sudo('rm', '--', enabled)
            if installed:
                if previous is None:
                    sudo('rm', '--', site)
                else:
                    sudo('install', '-m', mode, backup, site)
            sudo('nginx', '-t', '-q')
            sudo('systemctl', 'reload-or-restart', 'nginx')
            raise
    print(f'==> nginx site configured and enabled: {site}')


def main():
    """Validate environment settings and apply them unless --check is supplied."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    site, enabled, text = prepare(os.environ)
    if args.check:
        print(f'==> nginx plan validated: {site}')
    else:
        apply(site, enabled, text)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit(f'nginx setup failed: {error}')
