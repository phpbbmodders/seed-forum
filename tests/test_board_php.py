"""Verify the docker command bin/board-php.py builds for MSSQL boards, without running docker."""
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('board_php', Path(__file__).resolve().parents[1] / 'bin/board-php.py')
board_php = importlib.util.module_from_spec(spec)
spec.loader.exec_module(board_php)


class BoardPhpTests(unittest.TestCase):
    """Check which directories an MSSQL board's PHP container mounts."""

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'board'
        self.root.mkdir()
        (self.root / '.seed-forum-database.json').write_text(json.dumps({'engine': 'mssql'}))

    def docker_argv(self) -> list:
        """Run main() with docker stubbed out and return the argv it would exec."""
        with patch.object(sys, 'argv', ['board-php.py', str(self.root), '-v']), \
                patch.object(board_php, 'driver_image', return_value='image:tag'), \
                patch.object(board_php.os, 'execvp') as execvp:
            board_php.main()
        return execvp.call_args.args[1]

    def test_without_outside_directory(self):
        """Only the board and the seed-forum checkout are mounted by default."""
        argv = self.docker_argv()
        mounts = [argv[i + 1] for i, arg in enumerate(argv) if arg == '-v' and i + 1 < len(argv) and ':' in argv[i + 1]]
        self.assertEqual(mounts, [f'{self.root}:{self.root}', f'{board_php.REPO}:{board_php.REPO}:ro'])

    def test_outside_directory_is_mounted_read_write(self):
        """BOARD_ROOT.outside, when it exists, is mounted read-write at its own path."""
        outside = self.root.with_name('board.outside')
        outside.mkdir()
        argv = self.docker_argv()
        self.assertIn(f'{outside}:{outside}', argv)
        self.assertEqual(argv[-3:], ['image:tag', 'php', '-v'])

    def test_outside_symlink_is_not_mounted(self):
        """A symlink named BOARD_ROOT.outside is ignored, so it cannot widen the mount."""
        target = Path(self.temp.name) / 'elsewhere'
        target.mkdir()
        self.root.with_name('board.outside').symlink_to(target)
        self.assertFalse(any('outside' in arg or 'elsewhere' in arg for arg in self.docker_argv()))


if __name__ == '__main__':
    unittest.main()
