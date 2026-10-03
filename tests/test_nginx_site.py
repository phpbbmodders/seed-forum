"""Verify nginx site creation/update and rollback using temporary directories."""
import contextlib
import importlib.util
import io
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('nginx_site', Path(__file__).resolve().parents[1] / 'bin/nginx-site.py')
nginx_site = importlib.util.module_from_spec(spec)
spec.loader.exec_module(nginx_site)


class NginxSiteTests(unittest.TestCase):
    """Keep all file mutations and sudo command effects inside a temp nginx tree."""

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        (self.base / 'sites-available').mkdir()
        (self.base / 'sites-enabled').mkdir()
        self.site = self.base / 'sites-available/board.conf'
        self.enabled = self.base / 'sites-enabled/board.conf'
        self.env = {'NGINX_SITE': str(self.site), 'PHPBB_ROOT': '/tmp/board with spaces', 'SERVER_NAME': 'localhost', 'SERVER_PORT': '8093', 'PHP_FPM_SOCKET': '/run/php/php8.4-fpm.sock'}
        self.original = 'server {\n    listen 8092;\n    listen [::]:8092;\n    server_name oldhost;\n    root /old/board/;\n    index index.php;\n    # Keep my custom rule.\n}\n'

    def invoke(self, text, fail_command=None):
        """Simulate sudo mutations and optionally fail one validation/reload."""
        actions = []
        failed = False

        def command(argv, **kwargs):
            nonlocal failed
            actions.append(list(map(str, argv)))
            args = list(map(str, argv))[1:]
            if args[0] == fail_command and not failed:
                failed = True
                raise subprocess.CalledProcessError(1, argv)
            if args[0] == 'install':
                shutil.copyfile(args[-2], args[-1])
            elif args[0] == 'ln':
                Path(args[-1]).symlink_to(args[-2])
            elif args[0] == 'rm':
                Path(args[-1]).unlink()
            return subprocess.CompletedProcess(argv, 0)

        with patch.object(nginx_site.subprocess, 'run', side_effect=command), contextlib.redirect_stdout(io.StringIO()):
            nginx_site.apply(self.site, self.enabled, text)
        return actions

    def test_new_site_uses_loopback_and_configured_socket(self):
        with patch.object(Path, 'is_socket', return_value=True):
            site, enabled, text = nginx_site.prepare(self.env)
        self.assertEqual(site, self.site)
        self.assertEqual(enabled, self.enabled)
        self.assertIn('listen 127.0.0.1:8093;', text)
        self.assertIn('listen [::1]:8093;', text)
        self.assertIn('root "/tmp/board with spaces/";', text)
        self.assertIn('fastcgi_pass unix:/run/php/php8.4-fpm.sock;', text)
        self.assertNotIn('{{', text)
        self.assertFalse(site.exists())

    def test_update_changes_root_host_port_and_keeps_other_rules(self):
        self.site.write_text(self.original)
        _, _, text = nginx_site.prepare(self.env)
        self.assertIn('listen 8093;', text)
        self.assertIn('listen [::]:8093;', text)
        self.assertIn('server_name localhost;', text)
        self.assertIn('root "/tmp/board with spaces/";', text)
        self.assertIn('# Keep my custom rule.', text)

    def test_conflicting_enabled_link_is_rejected(self):
        self.enabled.symlink_to(self.base / 'other.conf')
        with self.assertRaisesRegex(ValueError, 'different site'):
            nginx_site.prepare(self.env)

    def test_multi_server_site_is_rejected(self):
        self.site.write_text(self.original + self.original)
        with self.assertRaisesRegex(ValueError, 'one server block'):
            nginx_site.prepare(self.env)

    def test_success_creates_and_enables_site(self):
        actions = self.invoke(self.original)
        self.assertEqual(self.site.read_text(), self.original)
        self.assertEqual(self.enabled.resolve(), self.site)
        self.assertIn(['sudo', 'nginx', '-t', '-q'], actions)
        self.assertIn(['sudo', 'systemctl', 'reload-or-restart', 'nginx'], actions)

    def test_failed_validation_removes_new_site_and_link(self):
        with self.assertRaises(subprocess.CalledProcessError):
            self.invoke(self.original, fail_command='nginx')
        self.assertFalse(self.site.exists())
        self.assertFalse(self.enabled.is_symlink())

    def test_failed_reload_restores_existing_site_and_link(self):
        self.site.write_text(self.original)
        self.enabled.symlink_to(self.site)
        with self.assertRaises(subprocess.CalledProcessError):
            self.invoke(self.original.replace('8092', '8093'), fail_command='systemctl')
        self.assertEqual(self.site.read_text(), self.original)
        self.assertEqual(self.enabled.resolve(), self.site)

    def test_unchanged_site_is_reloaded_without_rewriting(self):
        self.site.write_text(self.original)
        self.enabled.symlink_to(self.site)
        actions = self.invoke(self.original)
        self.assertFalse(any(action[1] == 'install' for action in actions))
        self.assertIn(['sudo', 'systemctl', 'reload-or-restart', 'nginx'], actions)


if __name__ == '__main__':
    unittest.main()
