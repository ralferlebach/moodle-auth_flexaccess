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
"""Install the sibling FlexAccess plugins (and tool_mergeusers) for a CI run at exact commits.

The four plugins depend on each other, so a CI run tests the plugin under test together with its siblings.
This script resolves the siblings from a branch to commit SHAs ONCE per run, checks that every plugin
carries the same version, writes that combination as a run lock (JSON, an artifact of the run, never
committed) and fetches exactly those commits for the jobs of the run. There is no fallback: a missing
branch, an unknown commit, a version that differs, a tampered run lock or an unpinned tool_mergeusers
fail hard. (A committed release lock and a separate release gate existed until 2026102700 and were removed.)

Commands
  resolve  Resolve the siblings of the plugin under test from a branch and write the run lock.
           --wait SECONDS keeps polling while a sibling still carries another version (the four
           repositories can only be uploaded one after another); after the wait it fails.
  fetch    Fetch exactly the commits of a run lock into a directory (moodle-plugin-ci extra plugins,
           a Moodle tree, or one directory per repository) and verify each fetched version.php.

Only the Python standard library and git are used. The configuration (repositories, Moodle paths,
pinned tool_mergeusers) is .github/flexaccess-family.json, identical in all four repositories.
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time

COMPONENTS = ['auth_flexaccess', 'enrol_flexaccess', 'mod_flexaccess', 'tool_flexaccess']
SHA_RE = re.compile(r'^[0-9a-f]{40}$')
VERSION_RE = re.compile(r"\$plugin->version\s*=\s*([0-9]{10})\s*;")
COMPONENT_RE = re.compile(r"\$plugin->component\s*=\s*'([a-z0-9_]+)'\s*;")
HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_CONFIG = os.path.join(os.path.dirname(HERE), 'flexaccess-family.json')


class FamilyError(Exception):
    """A hard failure: the combination cannot be pinned or verified."""


# Options for every git call of the resolver. Since git 2.47, "git fetch" starts automatic maintenance (gc,
# commit-graph) as a detached background process. In the throw-away repositories of the resolver that process
# still writes into .git/objects while the directory is removed, and the removal fails ("Directory not empty:
# 'objects'", seen on the GitHub runner with git 2.55). The resolver never needs maintenance.
GIT_OPTIONS = ('-c', 'maintenance.auto=false', '-c', 'gc.auto=0', '-c', 'fetch.writeCommitGraph=false')


def git(*args, cwd=None):
    """Run git (without background maintenance) and return stdout; raise FamilyError on failure."""
    result = subprocess.run(['git', *GIT_OPTIONS, *args], cwd=cwd, capture_output=True, text=True)
    if result.returncode != 0:
        raise FamilyError('git %s failed: %s' % (' '.join(args), (result.stderr or result.stdout).strip()))
    return result.stdout


def load_config(path):
    """The family configuration: repositories, paths and the pinned tool_mergeusers."""
    with open(path, encoding='utf-8') as handle:
        config = json.load(handle)
    if sorted(config.get('plugins', {})) != sorted(COMPONENTS):
        raise FamilyError('The family configuration must list exactly %s.' % ', '.join(COMPONENTS))
    merge = config.get('mergeusers', {})
    if not SHA_RE.match(str(merge.get('sha', ''))):
        raise FamilyError('tool_mergeusers is not pinned to a commit SHA in %s.' % path)
    return config


def repo_url(repo):
    """Clone URL of a repository ("owner/name"); FLEXACCESS_FAMILY_URL lets tests use local repositories."""
    template = os.environ.get('FLEXACCESS_FAMILY_URL', 'https://github.com/{repo}.git')
    return template.format(repo=repo)


def plugin_facts(text):
    """Version and component from the text of a version.php."""
    version = VERSION_RE.search(text)
    component = COMPONENT_RE.search(text)
    return (int(version.group(1)) if version else None, component.group(1) if component else None)


def branch_sha(repo, ref):
    """The commit a branch points to right now; a missing branch is an error, never a fallback."""
    out = git('ls-remote', repo_url(repo), 'refs/heads/' + ref).split()
    if not out:
        raise FamilyError('Branch "%s" does not exist in %s (no fallback to another branch).' % (ref, repo))
    return out[0]


def fetch_commit(repo, sha, dest):
    """Fetch exactly one commit into dest (no history, no .git left behind)."""
    if not SHA_RE.match(sha):
        raise FamilyError('"%s" is not a full commit SHA (%s).' % (sha, repo))
    if os.path.exists(dest):
        shutil.rmtree(dest)
    os.makedirs(dest)
    git('init', '-q', cwd=dest)
    try:
        git('fetch', '-q', '--depth', '1', repo_url(repo), sha, cwd=dest)
    except FamilyError as error:
        raise FamilyError('Commit %s is not available in %s: %s' % (sha, repo, error)) from error
    git('-c', 'advice.detachedHead=false', 'checkout', '-q', 'FETCH_HEAD', cwd=dest)
    actual = git('rev-parse', 'HEAD', cwd=dest).strip()
    if actual != sha:
        raise FamilyError('Fetched %s instead of %s from %s.' % (actual, sha, repo))
    shutil.rmtree(os.path.join(dest, '.git'))


def remote_version(repo, sha):
    """Version and component of a commit, read without a working copy."""
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp:
        git('init', '-q', cwd=tmp)
        git('fetch', '-q', '--depth', '1', repo_url(repo), sha, cwd=tmp)
        return plugin_facts(git('show', 'FETCH_HEAD:version.php', cwd=tmp))


def read_version(directory):
    """Version and component of a plugin directory."""
    with open(os.path.join(directory, 'version.php'), encoding='utf-8') as handle:
        return plugin_facts(handle.read())


def validate_lock(lock, config):
    """Structural and semantic checks of a lock; raises on any deviation (tamper detection)."""
    if lock.get('schema') != 1:
        raise FamilyError('Unknown lock schema %r.' % lock.get('schema'))
    family = lock.get('family_version')
    if not isinstance(family, int) or len(str(family)) != 10:
        raise FamilyError('The lock has no valid family_version.')
    plugins = lock.get('plugins', {})
    if sorted(plugins) != sorted(COMPONENTS):
        raise FamilyError('The lock must pin exactly %s.' % ', '.join(COMPONENTS))
    for component, entry in plugins.items():
        if entry.get('repo') != config['plugins'][component]['repo']:
            raise FamilyError('%s points to %r, expected %r.' % (component, entry.get('repo'),
                                                                  config['plugins'][component]['repo']))
        sha = entry.get('sha')
        if not SHA_RE.match(str(sha)):
            raise FamilyError('%s is not pinned to a full commit SHA (%r).' % (component, sha))
        if entry.get('version') != family:
            raise FamilyError('%s is pinned at version %r, the family is %r.' % (component, entry.get('version'), family))
    merge = lock.get('mergeusers', {})
    if not SHA_RE.match(str(merge.get('sha', ''))):
        raise FamilyError('tool_mergeusers is not pinned to a commit SHA.')
    if merge.get('repo') != config['mergeusers']['repo']:
        raise FamilyError('tool_mergeusers points to %r, expected %r.' % (merge.get('repo'), config['mergeusers']['repo']))


def summary_markdown(lock, title):
    """The SHA matrix as a Markdown table for the job summary."""
    lines = ['### %s' % title, '', 'Family version `%s`' % lock['family_version'], '',
             '| Component | Repository | Commit | Version |', '|---|---|---|---|']
    for component in COMPONENTS:
        entry = lock['plugins'][component]
        lines.append('| %s | %s | `%s` | %s |' % (component, entry['repo'], entry['sha'], entry.get('version', '')))
    merge = lock['mergeusers']
    lines.append('| tool_mergeusers | %s | `%s` | %s |' % (merge['repo'], merge['sha'], merge.get('version', '')))
    return '\n'.join(lines) + '\n'


def emit(lock, args, title):
    """Write the lock to --out, the GitHub output and the job summary."""
    text = json.dumps(lock, indent=2, sort_keys=True)
    if args.out:
        with open(args.out, 'w', encoding='utf-8') as handle:
            handle.write(text + '\n')
    if args.github_output:
        with open(args.github_output, 'a', encoding='utf-8') as handle:
            handle.write('lock=%s\n' % json.dumps(lock, sort_keys=True))
    if args.summary:
        with open(args.summary, 'a', encoding='utf-8') as handle:
            handle.write(summary_markdown(lock, title))
    print(summary_markdown(lock, title))


def cmd_resolve(args, config):
    """Pin the siblings of the plugin under test from one branch, waiting for a rollout to finish."""
    own_version, own_component = read_version(args.self_dir)
    if own_component != args.self:
        raise FamilyError('%s/version.php declares %r, expected %r.' % (args.self_dir, own_component, args.self))
    deadline = time.time() + max(0, args.wait)
    while True:
        lock = {'schema': 1, 'family_version': own_version, 'source': 'branch:' + args.ref, 'plugins': {}}
        mismatches = []
        for component in COMPONENTS:
            repo = config['plugins'][component]['repo']
            if component == args.self:
                lock['plugins'][component] = {'repo': repo, 'sha': args.self_sha, 'version': own_version}
                continue
            sha = branch_sha(repo, args.ref)
            version, declared = remote_version(repo, sha)
            if declared != component:
                raise FamilyError('%s@%s declares component %r.' % (repo, sha, declared))
            lock['plugins'][component] = {'repo': repo, 'sha': sha, 'version': version}
            if version != own_version:
                mismatches.append('%s@%s (%s) is at version %s, %s is at %s' % (
                    component, args.ref, sha[:12], version, args.self, own_version))
        if not mismatches:
            break
        if time.time() >= deadline:
            raise FamilyError('The plugin family is not at one version:\n  ' + '\n  '.join(mismatches) +
                              '\nUpload all four plugins at the same version, then re-run the workflow.')
        print('Waiting for the rollout: ' + '; '.join(mismatches), flush=True)
        time.sleep(args.interval)
    merge = config['mergeusers']
    lock['mergeusers'] = {'repo': merge['repo'], 'sha': merge['sha'], 'version': merge.get('version')}
    validate_lock(lock, config)
    emit(lock, args, 'FlexAccess plugin family (resolved from %s)' % args.ref)


def lock_from_args(args, config):
    """The lock passed to fetch (JSON text or file)."""
    if args.lock_json:
        lock = json.loads(args.lock_json)
    else:
        with open(args.lock_file, encoding='utf-8') as handle:
            lock = json.load(handle)
    validate_lock(lock, config)
    return lock


def cmd_fetch(args, config):
    """Fetch the pinned commits and verify what was fetched."""
    lock = lock_from_args(args, config)
    os.makedirs(args.dest, exist_ok=True)
    for component in COMPONENTS:
        if component == args.self:
            continue
        entry = lock['plugins'][component]
        if args.layout == 'moodle':
            dest = os.path.join(args.dest, config['plugins'][component]['path'])
        else:
            dest = os.path.join(args.dest, entry['repo'].split('/')[-1])
        fetch_commit(entry['repo'], entry['sha'], dest)
        version, declared = read_version(dest)
        if declared != component or version != entry['version']:
            raise FamilyError('Fetched %s@%s is %s at %s, the lock says %s.' % (
                entry['repo'], entry['sha'], declared, version, entry['version']))
        print('%s %s -> %s' % (component, entry['sha'], dest))
    if args.with_mergeusers:
        merge = lock['mergeusers']
        if args.layout == 'moodle':
            dest = os.path.join(args.dest, config['mergeusers']['path'])
        else:
            dest = os.path.join(args.dest, merge['repo'].split('/')[-1])
        fetch_commit(merge['repo'], merge['sha'], dest)
        print('tool_mergeusers %s -> %s' % (merge['sha'], dest))


def main(argv=None):
    """Command line entry point."""
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('--config', default=DEFAULT_CONFIG)
    sub = parser.add_subparsers(dest='command', required=True)

    def outputs(p):
        p.add_argument('--out')
        p.add_argument('--github-output')
        p.add_argument('--summary')

    p = sub.add_parser('resolve')
    p.add_argument('--self', required=True, choices=COMPONENTS)
    p.add_argument('--self-dir', default='plugin')
    p.add_argument('--self-sha', required=True)
    p.add_argument('--ref', required=True)
    p.add_argument('--wait', type=int, default=0)
    p.add_argument('--interval', type=int, default=30)
    outputs(p)
    p = sub.add_parser('fetch')
    group = p.add_mutually_exclusive_group(required=True)
    group.add_argument('--lock-json')
    group.add_argument('--lock-file')
    p.add_argument('--self', choices=COMPONENTS)
    p.add_argument('--dest', required=True)
    p.add_argument('--layout', choices=['extra', 'moodle'], default='extra')
    p.add_argument('--with-mergeusers', action='store_true')

    args = parser.parse_args(argv)
    if getattr(args, 'self_sha', None) is not None and not SHA_RE.match(args.self_sha):
        parser.error('--self-sha must be a full commit SHA')
    try:
        config = load_config(args.config)
        {'resolve': cmd_resolve, 'fetch': cmd_fetch}[args.command](args, config)
    except FamilyError as error:
        print('::error::FlexAccess plugin family: %s' % error, file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
