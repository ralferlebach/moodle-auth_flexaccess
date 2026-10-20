#!/usr/bin/env python3
# This file is part of Moodle - http://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Moodle is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
"""Self-tests of flexaccess_family.py against local fixture repositories (tool_flexaccess #12).

Every negative case must fail hard: a missing branch, unequal versions, a missing or short SHA, an
unpinned tool_mergeusers, a tampered lock (wrong repository, wrong version, unknown commit, extra or
missing plugin) and a release ZIP checksum mismatch. Run: python3 test_flexaccess_family.py
"""

import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flexaccess_family as family  # noqa: E402

GIT = ['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.invalid', '-c', 'init.defaultBranch=main']


def load(path):
    """A JSON file."""
    with open(path, encoding='utf-8') as handle:
        return json.load(handle)


def run(*args, cwd):
    """git in a fixture repository."""
    subprocess.run(GIT + list(args), cwd=cwd, check=True, capture_output=True)


class FamilyTest(unittest.TestCase):
    """Resolver behaviour on four local fixture repositories plus a fake tool_mergeusers."""

    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.root = os.path.join(self.tmp, 'remotes')
        os.environ['FLEXACCESS_FAMILY_URL'] = 'file://' + self.root + '/{repo}'
        self.config = {
            'plugins': {c: {'repo': 'owner/moodle-' + c, 'path': c.replace('_', '/')} for c in family.COMPONENTS},
            'mergeusers': {'repo': 'other/moodle-tool_mergeusers', 'path': 'admin/tool/mergeusers'},
        }
        self.heads = {}
        for component in family.COMPONENTS:
            self.heads[component] = self.make_repo('owner/moodle-' + component, component, 2026101800)
        self.config['mergeusers']['sha'] = self.make_repo('other/moodle-tool_mergeusers', 'tool_mergeusers', 2026081302)
        self.config_file = os.path.join(self.tmp, 'family.json')
        self.write_config()
        self.self_dir = os.path.join(self.tmp, 'plugin')
        self.write_plugin(self.self_dir, 'tool_flexaccess', 2026101800)

    def tearDown(self):
        shutil.rmtree(self.tmp)
        os.environ.pop('FLEXACCESS_FAMILY_URL', None)

    def write_config(self):
        """Persist the fixture configuration."""
        with open(self.config_file, 'w', encoding='utf-8') as handle:
            json.dump(self.config, handle)

    @staticmethod
    def write_plugin(directory, component, version):
        """A minimal plugin: version.php only."""
        os.makedirs(directory, exist_ok=True)
        with open(os.path.join(directory, 'version.php'), 'w', encoding='utf-8') as handle:
            handle.write("<?php\n$plugin->version = %d;\n$plugin->component = '%s';\n" % (version, component))

    def make_repo(self, repo, component, version, branch='development'):
        """A fixture repository with one commit on branch; returns its SHA."""
        path = os.path.join(self.root, repo)
        if not os.path.isdir(path):
            os.makedirs(path)
            run('init', '-q', cwd=path)
        run('checkout', '-q', '-B', branch, cwd=path)
        self.write_plugin(path, component, version)
        run('add', '-A', cwd=path)
        run('commit', '-q', '--allow-empty', '-m', 'v%d' % version, cwd=path)
        return subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=path, check=True, capture_output=True,
                              text=True).stdout.strip()

    def cli(self, *args):
        """Run the script; returns (exit code, stdout, stderr)."""
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            code = family.main(['--config', self.config_file, *args])
        return code, out.getvalue(), err.getvalue()

    def resolve(self, *extra):
        """Resolve the siblings of tool_flexaccess from development into lock.json."""
        lock = os.path.join(self.tmp, 'lock.json')
        code, out, err = self.cli('resolve', '--self', 'tool_flexaccess', '--self-dir', self.self_dir,
                                  '--self-sha', 'a' * 40, '--ref', 'development', '--out', lock, *extra)
        return code, lock, err

    def test_resolve_pins_every_sibling_to_its_head(self):
        code, lock, err = self.resolve()
        self.assertEqual(0, code, err)
        data = load(lock)
        for component in ('auth_flexaccess', 'enrol_flexaccess', 'mod_flexaccess'):
            self.assertEqual(self.heads[component], data['plugins'][component]['sha'])
            self.assertEqual(2026101800, data['plugins'][component]['version'])
        self.assertEqual('a' * 40, data['plugins']['tool_flexaccess']['sha'])
        self.assertEqual(self.config['mergeusers']['sha'], data['mergeusers']['sha'])

    def test_missing_branch_fails_without_fallback(self):
        run('branch', '-q', '-m', 'development', 'other', cwd=os.path.join(self.root, 'owner/moodle-enrol_flexaccess'))
        code, _, err = self.resolve()
        self.assertEqual(1, code)
        self.assertIn('does not exist', err)

    def test_sibling_version_mismatch_fails(self):
        self.make_repo('owner/moodle-auth_flexaccess', 'auth_flexaccess', 2026092900)
        code, _, err = self.resolve('--wait', '0')
        self.assertEqual(1, code)
        self.assertIn('not at one version', err)
        self.assertIn('auth_flexaccess@development', err)

    def test_unpinned_mergeusers_fails(self):
        self.config['mergeusers']['sha'] = 'MOODLE_405_STABLE'
        self.write_config()
        code, _, err = self.resolve()
        self.assertEqual(1, code)
        self.assertIn('not pinned', err)

    def fetch(self, lock):
        """fetch a lock (dict) into a fresh directory."""
        dest = os.path.join(self.tmp, 'fetched')
        return self.cli('fetch', '--lock-json', json.dumps(lock), '--self', 'tool_flexaccess', '--dest', dest,
                        '--with-mergeusers'), dest

    def test_fetch_installs_exactly_the_pinned_commits(self):
        code, lock, err = self.resolve()
        self.assertEqual(0, code, err)
        (code, out, err), dest = self.fetch(load(lock))
        self.assertEqual(0, code, err)
        self.assertEqual((2026101800, 'auth_flexaccess'), family.read_version(os.path.join(dest, 'moodle-auth_flexaccess')))
        self.assertFalse(os.path.exists(os.path.join(dest, 'moodle-auth_flexaccess', '.git')))
        self.assertTrue(os.path.isfile(os.path.join(dest, 'moodle-tool_mergeusers', 'version.php')))
        self.assertFalse(os.path.exists(os.path.join(dest, 'moodle-tool_flexaccess')), 'The plugin under test is not fetched.')

    def test_tampered_locks_fail(self):
        code, lock, err = self.resolve()
        self.assertEqual(0, code, err)
        good = load(lock)
        cases = {
            'short sha': ('plugins', 'mod_flexaccess', 'sha', self.heads['mod_flexaccess'][:12]),
            'unknown commit': ('plugins', 'mod_flexaccess', 'sha', 'f' * 40),
            'foreign repository': ('plugins', 'mod_flexaccess', 'repo', 'attacker/moodle-mod_flexaccess'),
            'wrong version': ('plugins', 'mod_flexaccess', 'version', 2026092900),
            'unpinned mergeusers': ('mergeusers', None, 'sha', 'MOODLE_405_STABLE'),
        }
        for name, (section, component, key, value) in cases.items():
            lock = json.loads(json.dumps(good))
            target = lock[section][component] if component else lock[section]
            target[key] = value
            (code, _, err), _ = self.fetch(lock)
            self.assertEqual(1, code, name)
            self.assertIn('FlexAccess plugin family', err, name)
        lock = json.loads(json.dumps(good))
        lock['plugins']['local_extra'] = dict(lock['plugins']['mod_flexaccess'])
        (code, _, _), _ = self.fetch(lock)
        self.assertEqual(1, code, 'an extra plugin')
        lock = json.loads(json.dumps(good))
        del lock['plugins']['auth_flexaccess']
        (code, _, _), _ = self.fetch(lock)
        self.assertEqual(1, code, 'a missing plugin')

    def test_fetched_content_must_match_the_lock(self):
        code, lock, err = self.resolve()
        self.assertEqual(0, code, err)
        data = load(lock)
        # A newer commit with another version: the lock still names the old commit, which is what is fetched.
        self.make_repo('owner/moodle-enrol_flexaccess', 'enrol_flexaccess', 2026101900)
        (code, _, err), dest = self.fetch(data)
        self.assertEqual(0, code, err)
        self.assertEqual(2026101800, family.read_version(os.path.join(dest, 'moodle-enrol_flexaccess'))[0])
        # A lock that claims the new version for the old commit is rejected.
        data['family_version'] = 2026101900
        for entry in data['plugins'].values():
            entry['version'] = 2026101900
        (code, _, err), _ = self.fetch(data)
        self.assertEqual(1, code)
        self.assertIn('the lock says', err)

    def write_release_lock(self, data):
        """A committed release lock file."""
        path = os.path.join(self.tmp, 'release.lock.json')
        with open(path, 'w', encoding='utf-8') as handle:
            json.dump(data, handle)
        return path

    def release_lock(self):
        """A valid release lock for the fixture heads (tool_flexaccess as SELF)."""
        code, out, err = self.cli('pin', '--ref', 'development')
        self.assertEqual(0, code, err)
        return json.loads(out)

    def test_release_lock_is_verified_and_completed(self):
        data = self.release_lock()
        self.assertEqual('SELF', data['plugins']['tool_flexaccess']['sha'])
        out = os.path.join(self.tmp, 'resolved.json')
        code, _, err = self.cli('lock', '--lock-file', self.write_release_lock(data), '--self', 'tool_flexaccess',
                                '--self-dir', self.self_dir, '--self-sha', 'b' * 40, '--out', out)
        self.assertEqual(0, code, err)
        self.assertEqual('b' * 40, load(out)['plugins']['tool_flexaccess']['sha'])

    def test_release_lock_with_wrong_commit_version_fails(self):
        data = self.release_lock()
        old = self.make_repo('owner/moodle-mod_flexaccess', 'mod_flexaccess', 2026092900, branch='old')
        data['plugins']['mod_flexaccess']['sha'] = old
        code, _, err = self.cli('lock', '--lock-file', self.write_release_lock(data), '--self', 'tool_flexaccess',
                                '--self-dir', self.self_dir, '--self-sha', 'b' * 40)
        self.assertEqual(1, code)
        self.assertIn('the lock says', err)

    def test_release_lock_must_name_self(self):
        data = self.release_lock()
        data['plugins']['tool_flexaccess']['sha'] = self.heads['tool_flexaccess']
        code, _, err = self.cli('lock', '--lock-file', self.write_release_lock(data), '--self', 'tool_flexaccess',
                                '--self-dir', self.self_dir, '--self-sha', 'b' * 40)
        self.assertEqual(1, code)
        self.assertIn('SELF', err)

    def test_missing_release_lock_fails(self):
        code, _, err = self.cli('lock', '--lock-file', os.path.join(self.tmp, 'absent.json'), '--self', 'tool_flexaccess',
                                '--self-dir', self.self_dir, '--self-sha', 'b' * 40)
        self.assertEqual(1, code)
        self.assertIn('no release lock', err)

    def test_zip_checksum_mismatch_fails(self):
        code, lock, err = self.resolve()
        data = load(lock)
        data['plugins']['tool_flexaccess']['sha'] = self.heads['tool_flexaccess']
        code, out, err = self.cli('zipcheck', '--lock-json', json.dumps(data))
        self.assertEqual(0, code, err)
        data['plugins']['auth_flexaccess']['zip_sha256'] = '0' * 64
        code, _, err = self.cli('zipcheck', '--lock-json', json.dumps(data))
        self.assertEqual(1, code)
        self.assertIn('checksum', err)


if __name__ == '__main__':
    unittest.main(verbosity=2)
