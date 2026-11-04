"""Exercise CrabPHP migrations on disposable SQLite databases."""
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile

server = Path(__file__).resolve().parents[1]


def crab(path, *args, success=True):
    result = subprocess.run(
        ['php', str(server / 'cua'), *args],
        cwd=server,
        env={**os.environ, 'CRABASE_DB': str(path)},
        capture_output=True,
        text=True,
    )
    assert (result.returncode == 0) == success, result.stdout + result.stderr


with tempfile.TemporaryDirectory(prefix='crabase-migrations-') as temp:
    path = Path(temp) / 'test.sqlite'
    crab(path, 'migrate')
    with sqlite3.connect(path) as db:
        assert db.execute("SELECT count(*) FROM migrations WHERE batch > 0").fetchone() == (23,)
        assert not db.execute("SELECT name FROM sqlite_master WHERE name='phinxlog'").fetchall()
        db.execute("INSERT INTO projects (id,name,path) VALUES ('test','Test','/test')")
        db.execute("INSERT INTO chats (id,title,created_at,updated_at) VALUES ('1234567890abcdef','Preserve me','2026-09-09','2026-09-09')")
    crab(path, 'migrate')
    with sqlite3.connect(path) as db:
        assert db.execute('SELECT title FROM chats').fetchall() == [('Preserve me',)]
        assert db.execute('SELECT count(*) FROM migrations').fetchone() == (23,)

    # The chat model migration fills existing chats from their latest agent request.
    backfill = Path(temp) / 'backfill.sqlite'
    crab(backfill, 'migrate')
    with sqlite3.connect(backfill) as db:
        db.execute("DELETE FROM migrations WHERE name='20261103000000_chat_model_selection'")
        db.execute('ALTER TABLE chats DROP COLUMN effort'); db.execute('ALTER TABLE chats DROP COLUMN model')
        db.execute("INSERT INTO chats (id,title,created_at,updated_at) VALUES ('c1','Old','2026-09-09','2026-09-09'),('c2','Never asked','2026-09-09','2026-09-09')")
        db.execute("INSERT INTO jobs (chat_id,prompt,model,effort) VALUES ('c1','a','gpt-old','low'),('c1','b','gpt-new','high'),('c1','note',NULL,NULL)")
    crab(backfill, 'migrate')
    with sqlite3.connect(backfill) as db:
        assert db.execute("SELECT id,model,effort FROM chats ORDER BY id").fetchall() == [('c1','gpt-new','high'),('c2',None,None)]
    crab(backfill, 'migrate:rollback')
    with sqlite3.connect(backfill) as db:
        assert 'model' not in [row[1] for row in db.execute('PRAGMA table_info(chats)')]

    crab(path, 'migrate:rollback')
    with sqlite3.connect(path) as db:
        assert not db.execute("SELECT name FROM sqlite_master WHERE name='projects'").fetchall()
        assert db.execute('SELECT count(*) FROM migrations').fetchone() == (0,)
    crab(path, 'migrate')

    adopted = Path(temp) / 'adopted.sqlite'
    with sqlite3.connect(adopted) as db:
        db.execute("CREATE TABLE projects (id TEXT PRIMARY KEY, name TEXT NOT NULL, path TEXT NOT NULL UNIQUE)")
        db.execute("INSERT INTO projects VALUES ('legacy','Legacy','/legacy')")
        db.execute("CREATE TABLE phinxlog (version BIGINTEGER NOT NULL PRIMARY KEY, migration_name VARCHAR(100), start_time TEXT, end_time TEXT, breakpoint INTEGER NOT NULL DEFAULT 0)")
        db.execute("INSERT INTO phinxlog VALUES (20260909000000,'CreateProjects','2026-09-09 00:00:00','2026-09-09 00:00:00',0)")
    crab(adopted, 'migrate')
    with sqlite3.connect(adopted) as db:
        assert db.execute("SELECT name FROM sqlite_master WHERE name='phinxlog'").fetchall() == []
        assert db.execute("SELECT name FROM sqlite_master WHERE name='migrations'").fetchall()
        assert db.execute('SELECT name FROM migrations WHERE name=?', ('20260909000000_create_projects',)).fetchone()
        assert db.execute("SELECT id FROM projects WHERE id='legacy'").fetchone() == ('legacy',)

    seeded = Path(temp) / 'seeded.sqlite'
    crab(seeded, 'migrate')
    for _ in range(2):
        subprocess.run(
            ['php', str(server / 'bin/seed-workspace.php')],
            cwd=server,
            env={**os.environ, 'CRABASE_DB': str(seeded)},
            check=True,
        )
    with sqlite3.connect(seeded) as db:
        assert db.execute("SELECT count(*) FROM projects WHERE id='crabase'").fetchone() == (1,)

    bad = Path(temp) / 'incompatible.sqlite'
    with sqlite3.connect(bad) as db:
        db.execute('CREATE TABLE projects (id TEXT PRIMARY KEY)')
        db.execute("INSERT INTO projects VALUES ('keep')")
    crab(bad, 'migrate', success=False)
    with sqlite3.connect(bad) as db:
        assert db.execute('SELECT * FROM projects').fetchall() == [('keep',)]
        assert not db.execute("SELECT name FROM sqlite_master WHERE name='chats'").fetchall()
        assert db.execute('SELECT count(*) FROM migrations').fetchone() == (1,)

print('PASS: CrabPHP fresh schema, Phinx history adoption, rollback, seed, and failed migration rollback.')
