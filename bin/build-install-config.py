#!/usr/bin/env python3
"""Write phpBB installer YAML to stdout for the internal reset backend.

Read PHPBB_ROOT, SERVER_NAME, and SERVER_PORT from the environment and
Composer extension names from positional arguments. Preserve the template's
other installer settings; serialize paths and values with a YAML parser.
When bin/db-container.py has written PHPBB_ROOT/.seed-forum-database.json,
the board is installed into that database instead of SQLite.
"""
import json
import os
from pathlib import Path
import sys
import yaml

template = (Path(__file__).resolve().parent.parent / 'config/install.yml.example').read_text()
# Temporary values make the placeholder template parseable. Real paths are
# assigned to parsed fields so quotes, spaces, and YAML punctuation stay data.
template = template.replace('{{PHPBB_ROOT}}', '/placeholder').replace('{{SERVER_NAME}}', 'localhost').replace('{{SERVER_PORT}}', '8092').replace('{{EXTENSIONS_YAML}}', '        []')
document = yaml.safe_load(template)
installer = document['installer']
state_file = Path(os.environ['PHPBB_ROOT']) / '.seed-forum-database.json'
if state_file.is_file():
    details = json.loads(state_file.read_text())
    for key in ('dbms', 'dbhost', 'dbport', 'dbuser', 'dbpasswd', 'dbname'):
        installer['database'][key] = details[key]
else:
    database = str(Path(os.environ['PHPBB_ROOT']) / 'store/sqlite.db')
    installer['database']['dbhost'] = database
    installer['database']['dbname'] = database
installer['server']['server_name'] = os.environ['SERVER_NAME']
installer['server']['server_port'] = int(os.environ['SERVER_PORT'])
installer['extensions'] = sys.argv[1:]
yaml.safe_dump(document, sys.stdout, sort_keys=False)
