"""Check the dump importer's pure helpers without Docker or a database.

Database detection, the safety SQL, version ordering and config.php writing
are exercised against temporary files; nothing here starts a container.
"""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('importer', Path(__file__).resolve().parents[1] / 'bin/import-dump.py')
importer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(importer)


class ImportDumpTests(unittest.TestCase):
    """Exercise import-dump.py helpers on temporary dumps and boards."""

    def setUp(self):
        """Create a temporary directory for dumps and board files."""
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)

    def dump(self, text):
        """Write a dump file with the given SQL and return its path."""
        path = self.base / 'dump.sql'
        path.write_text(text)
        return path

    def test_database_comes_from_the_dump(self):
        dump = self.dump('-- header\nCREATE DATABASE /*!32312 IF NOT EXISTS*/ `real_board` /*!40100 DEFAULT CHARACTER SET latin1 */;\nUSE `real_board`;\n')
        self.assertEqual(importer.choose_database(dump, None), 'real_board')
        self.assertEqual(importer.choose_database(dump, 'real_board'), 'real_board')
        with self.assertRaisesRegex(ValueError, 'loads into'):
            importer.choose_database(dump, 'other')

    def test_dump_without_a_database_needs_one_configured(self):
        dump = self.dump('CREATE TABLE `phpbb_config` (`config_name` varchar(255));\n')
        self.assertEqual(importer.choose_database(dump, 'board_copy'), 'board_copy')
        with self.assertRaisesRegex(ValueError, 'does not name its database'):
            importer.choose_database(dump, None)

    def test_dump_with_two_databases_is_refused(self):
        dump = self.dump('USE `first`;\nUSE `second`;\n')
        with self.assertRaisesRegex(ValueError, 'more than one database'):
            importer.choose_database(dump, None)

    def test_safety_sql_turns_off_mail_and_extensions_and_sets_local_urls(self):
        sql = importer.safety_sql('phpbb_', 'localhost', '8115')
        self.assertIn('UPDATE phpbb_ext SET ext_active = 0;', sql)
        for name, value in [('email_enable', '0'), ('jab_enable', '0'), ('smtp_delivery', '0'), ('server_name', 'localhost'), ('server_port', '8115'), ('cookie_domain', '')]:
            self.assertIn(f"SET config_value = '{value}' WHERE config_name = '{name}';", sql)

    def test_safety_sql_rejects_unsafe_values(self):
        with self.assertRaisesRegex(ValueError, 'table prefix'):
            importer.safety_sql("phpbb_'; DROP", 'localhost', '8115')
        with self.assertRaisesRegex(ValueError, 'server name'):
            importer.safety_sql('phpbb_', "local'host", '8115')

    def test_version_order(self):
        self.assertLess(importer.version_key('3.3.17'), importer.version_key('3.3.19'))
        self.assertGreater(importer.version_key('4.0.0-a3-dev'), importer.version_key('3.3.19'))
        with self.assertRaises(ValueError):
            importer.version_key('unknown')

    def test_config_php_keeps_quotes_in_values(self):
        root = self.base / 'board'
        root.mkdir()
        importer.write_config(root, {'db_port': '33115', 'table_prefix': 'phpbb_'}, 'real_board', "pa'ss\\word")
        script = ('include $argv[1]; echo json_encode([$dbms, $dbport, $dbname, $dbpasswd, $table_prefix, $acm_type]);')
        output = subprocess.run(['php', '-r', script, str(root / 'config.php')], capture_output=True, text=True, check=True).stdout
        self.assertEqual(output, '["phpbb\\\\db\\\\driver\\\\mysqli","33115","real_board","pa\'ss\\\\word","phpbb_","phpbb\\\\cache\\\\driver\\\\file"]')
        self.assertEqual((root / 'config.php').stat().st_mode & 0o777, 0o640)


if __name__ == '__main__':
    unittest.main()
