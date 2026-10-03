"""Check config resolution and operation ordering without system mounts.

Each test uses a temporary board and command simulation. The config.php
sentinel verifies that mount, unmount, and cache workflows preserve board
data; mount sets and manifests model successful and failed cleanup.
"""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
import yaml

spec = importlib.util.spec_from_file_location('runner', Path(__file__).resolve().parents[1] / 'bin/seed-forum.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class RunnerTests(unittest.TestCase):
    """Exercise the public runner against isolated configs and board records."""

    def setUp(self):
        """Create a board sentinel and two distinct extension checkouts."""
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / 'board with spaces'
        self.root.mkdir()
        (self.root / 'config.php').write_text('database must survive')
        (self.root / 'bin').mkdir()
        (self.root / 'bin/phpbbcli.php').touch()
        (self.root / 'cache').mkdir()
        self.config = self.base / 'local.yml'
        self.document = {'board': {'root': str(self.root), 'seeds': []}, 'extensions': {}}
        for label in ['first', 'second']:
            source = self.base / label
            source.mkdir()
            (source / 'composer.json').write_text(json.dumps({'name': 'vendor/' + label}))
            self.document['extensions'][label] = {'source': str(source), 'seeds': []}
        self.save()

    def save(self):
        """Write the current test configuration as YAML in the temp directory."""
        self.config.write_text(yaml.safe_dump(self.document, sort_keys=False))

    def invoke(self, *args, mounted=(), fail_unmount=False):
        """Run CLI validation with simulated commands and return ordered actions.

        mounted is the initial mount set. fail_unmount models a busy target;
        dry commands record their plan without changing the simulated state.
        """
        actions = []
        mounts = set(mounted)

        def action(argv, **kwargs):
            """Record argv and model only the mount effects needed by assertions."""
            actions.append([str(arg) for arg in argv])
            if kwargs.get('dry'):
                return
            if str(argv[0]) == 'sudo' and argv[1] == 'umount':
                if fail_unmount:
                    raise OSError('busy mount')
                mounts.discard(Path(argv[2]))
            if str(argv[0]) == 'sudo' and argv[1] == 'mount':
                mounts.add(Path(argv[-1]))

        with patch.object(sys, 'argv', ['runner', '--config', str(self.config), *args]), patch.object(runner, 'run', side_effect=action), patch.object(runner.os.path, 'ismount', side_effect=lambda path: Path(path) in mounts), patch.object(runner.shutil, 'which', return_value='/usr/bin/tool'), contextlib.redirect_stdout(io.StringIO()):
            runner.main()
        self.assertEqual((self.root / 'config.php').read_text(), 'database must survive')
        return actions

    def test_no_operation_never_loads_config(self):
        with patch.object(sys, 'argv', ['runner']), patch.object(runner, 'load_config') as load, contextlib.redirect_stdout(io.StringIO()):
            runner.main()
        load.assert_not_called()

    def add_style(self):
        source = self.base / 'custom style'
        source.mkdir()
        (source / 'style.cfg').write_text('name = Example\nparent = prosilver\n')
        self.document['styles'] = {'Example': {'source': str(source), 'default': True}}
        self.save()
        return source

    def test_install_styles_copies_and_purges_without_reset(self):
        self.add_style()
        actions = self.invoke('--install-styles')
        self.assertTrue((self.root / 'styles/Example/style.cfg').is_file())
        install = next(i for i, action in enumerate(actions) if any(item.endswith('/install-style.php') for item in action))
        purge = next(i for i, action in enumerate(actions) if 'cache:purge' in action)
        self.assertLess(install, purge)
        self.assertEqual(actions[install][-2:], ['Example', '1'])
        self.assertFalse(any(any(item.endswith('/rebuild-board.sh') for item in action) for action in actions))

    def test_style_dry_run_does_not_copy(self):
        self.add_style()
        self.invoke('--install-styles', '--dry-run')
        self.assertFalse((self.root / 'styles').exists())

    def test_styles_reject_multiple_defaults(self):
        source = self.add_style()
        self.document['styles']['Another'] = {'source': str(source), 'default': True}
        self.save()
        with self.assertRaisesRegex(ValueError, 'Only one style'):
            self.invoke('--install-styles')

    def test_missing_style_fails_before_reset(self):
        source = self.add_style()
        (source / 'style.cfg').unlink()
        with self.assertRaisesRegex(ValueError, 'Missing style.cfg'):
            self.invoke('--reset', '--skip-seed')

    def test_style_target_symlink_is_rejected(self):
        source = self.add_style()
        (self.root / 'styles').mkdir()
        (self.root / 'styles/Example').symlink_to(source, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'ordinary directory'):
            self.invoke('--install-styles')

    def test_status_shows_configured_port_and_url(self):
        """Report default/explicit ports without claiming the server is listening."""
        for port in (None, '8094'):
            with self.subTest(port=port):
                if port:
                    self.document['board']['server_port'] = port
                    self.document['board']['server_name'] = 'forum.local'
                self.save()
                output = io.StringIO()
                with patch.object(sys, 'argv', ['runner', '--config', str(self.config), '--status']), patch.object(runner.subprocess, 'check_output', return_value='{"enabled": [], "disabled": []}'), patch.object(runner, 'run') as mutation, contextlib.redirect_stdout(output):
                    runner.main()
                expected_port = port or '8092'
                expected_host = 'forum.local' if port else 'localhost'
                self.assertIn(f'Configured port: {expected_port}', output.getvalue())
                self.assertIn(f'Configured URL: http://{expected_host}:{expected_port}/', output.getvalue())
                mutation.assert_not_called()
                self.assertEqual((self.root / 'config.php').read_text(), 'database must survive')

    def test_mount_enable_purge_order(self):
        actions = self.invoke('--mount', '--enable', '--purge-cache', '--only', 'first')
        mount = next(i for i, action in enumerate(actions) if action[:2] == ['sudo', 'mount'])
        enable = next(i for i, action in enumerate(actions) if 'extension:enable' in action)
        purge = next(i for i, action in enumerate(actions) if 'cache:purge' in action)
        self.assertLess(mount, enable)
        self.assertLess(enable, purge)
        self.assertEqual(runner.managed(self.root), [self.root / 'ext/vendor/first'])

    def test_remount_replaces_all_managed_mounts(self):
        targets = [self.root / 'ext/vendor/first', self.root / 'ext/vendor/second']
        runner.save_mounts(self.root, targets)
        actions = self.invoke('--remount', '--only', 'first', mounted=targets)
        self.assertEqual(sum(action[:2] == ['sudo', 'umount'] for action in actions), 2)
        self.assertEqual(runner.managed(self.root), targets[:1])

    def test_unmount_only_preserves_other_mount(self):
        targets = [self.root / 'ext/vendor/first', self.root / 'ext/vendor/second']
        runner.save_mounts(self.root, targets)
        self.invoke('--unmount', '--only', 'first', mounted=targets)
        self.assertEqual(runner.managed(self.root), targets[1:])

    def test_unmount_works_without_sources(self):
        target = self.root / 'ext/vendor/first'
        runner.save_mounts(self.root, [target])
        (self.base / 'first/composer.json').unlink()
        (self.base / 'second/composer.json').unlink()
        self.invoke('--unmount', mounted=[target])
        self.assertFalse((self.root / '.seed-forum-mounted-extensions').exists())

    def test_unmount_only_uses_saved_names(self):
        self.invoke('--mount', '--only', 'first')
        (self.base / 'first/composer.json').unlink()
        self.invoke('--unmount', '--only', 'first', mounted=[self.root / 'ext/vendor/first'])
        self.assertFalse((self.root / '.seed-forum-mounted-extensions').exists())

    def test_failed_unmount_retains_manifest(self):
        target = self.root / 'ext/vendor/first'
        runner.save_mounts(self.root, [target])
        with self.assertRaises(OSError):
            self.invoke('--unmount', mounted=[target], fail_unmount=True)
        self.assertEqual(runner.managed(self.root), [target])

    def test_dry_run_does_not_write_or_execute(self):
        before = sorted(str(path) for path in self.root.rglob('*'))
        actions = self.invoke('--mount', '--enable', '--purge-cache', '--dry-run')
        self.assertTrue(actions)
        self.assertEqual(before, sorted(str(path) for path in self.root.rglob('*')))

    def test_purge_does_not_require_source_checkout(self):
        (self.base / 'first/composer.json').unlink()
        self.invoke('--purge-cache')

    def test_reset_only_selects_matching_sources_and_skips_seeds(self):
        (self.root / 'install.disabled').mkdir()
        actions = self.invoke('--reset', '--skip-seed', '--only', 'second', '--dry-run')
        self.assertEqual(actions[0][-1], str(self.base / 'second'))
        self.assertFalse(any('run-seed.php' in arg for action in actions for arg in action))

    def test_bad_variable_and_duplicate_yaml_fail(self):
        self.document['board']['root'] = '${UNDEFINED_SEED_FORUM_TEST_VAR}'
        self.save()
        with self.assertRaisesRegex(ValueError, 'Undefined variable'):
            runner.load_config(self.config)
        self.config.write_text('board: {}\nboard: {}\n')
        with self.assertRaisesRegex(ValueError, 'Duplicate config key'):
            runner.load_config(self.config)

    def test_environment_overrides_named_variable(self):
        self.document['variables'] = {'CUSTOM_ROOT': '/unused'}
        self.document['board']['root'] = '${CUSTOM_ROOT}'
        self.save()
        with patch.dict(runner.os.environ, {'CUSTOM_ROOT': str(self.root)}):
            self.assertEqual(runner.load_config(self.config)['root'], self.root)

    def test_invalid_flags_fail_before_config_read(self):
        with patch.object(sys, 'argv', ['runner', '--reset', '--unmount']), patch.object(runner, 'load_config') as load, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                runner.main()
        load.assert_not_called()

    def test_repeat_seed_is_rejected_before_actions(self):
        script = runner.REPO / 'bin/seed-standard-fixtures.php'
        self.document['board']['seeds'] = [str(script)]
        self.save()
        (self.root / '.seed-forum-seeds.json').write_text(json.dumps({str(script): 'failed'}))
        with self.assertRaisesRegex(ValueError, 'Seed already attempted'):
            self.invoke('--seed-only')

    def test_manifest_cannot_point_outside_board(self):
        (self.root / '.seed-forum-mounted-extensions').write_text('/some/other/mount\n')
        with self.assertRaisesRegex(ValueError, 'outside board'):
            self.invoke('--unmount')

    def test_reset_refuses_non_phpbb_directory_before_actions(self):
        with self.assertRaisesRegex(ValueError, 'not a phpBB source tree'):
            self.invoke('--reset', '--skip-seed', '--dry-run')

    def test_reset_preflights_nginx_without_mutating_in_dry_run(self):
        (self.root / 'install.disabled').mkdir()
        self.document['board']['nginx_site'] = str(self.base / 'sites-available/board.conf')
        self.save()
        with patch.object(runner.subprocess, 'run') as check:
            actions = self.invoke('--reset', '--skip-seed', '--dry-run')
        self.assertEqual(check.call_args.args[0][-1], '--check')
        env = check.call_args.kwargs['env']
        self.assertEqual(env['SEED_FORUM_NGINX_ENABLED'], '1')
        self.assertEqual(env['SERVER_PORT'], '8092')
        self.assertEqual(actions[0][0], 'bash')

    def test_invalid_nginx_plan_stops_reset_before_backend(self):
        (self.root / 'install.disabled').mkdir()
        self.document['board']['nginx_site'] = str(self.base / 'sites-available/board.conf')
        self.save()
        with patch.object(runner.subprocess, 'run', side_effect=OSError('invalid site plan')), patch.object(runner, 'run') as mutation:
            with self.assertRaisesRegex(OSError, 'invalid site plan'):
                self.invoke('--reset', '--skip-seed', '--dry-run')
        mutation.assert_not_called()


if __name__ == '__main__':
    unittest.main()
