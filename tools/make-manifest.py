#!/usr/bin/env python3
"""
tools/make-manifest.py — write MANIFEST.md5 and VERSION for a release (v1.5.19)

Run in the package folder before zipping / tagging a release:

    python3 tools/make-manifest.py 1.5.19

MANIFEST.md5 lists every published file (the same selection as
tools/prepublish-check.py: Git's file list, else the top-level .gitignore)
with its md5, in `md5sum` format. update.sh uses the installed copy of it to
tell which files a station changed by hand, and which files a release
removed. MANIFEST.md5 itself is not listed.
"""
import hashlib
import importlib.util
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)


def main():
    if len(sys.argv) != 2 or not re.match(r'^\d+\.\d+\.\d+$', sys.argv[1]):
        sys.exit('usage: make-manifest.py X.Y.Z')
    ver = sys.argv[1]
    spec = importlib.util.spec_from_file_location('pp', os.path.join(HERE, 'prepublish-check.py'))
    pp = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(pp)
    files = pp.git_files(ROOT) or pp.gitignore_files(ROOT)
    with open(os.path.join(ROOT, 'VERSION'), 'w') as f:
        f.write(ver + '\n')
    files = sorted(set(files) | {'VERSION'})
    skip = {'MANIFEST.md5'}
    lines = []
    for rel in files:
        if rel in skip or '__pycache__' in rel or rel.endswith('.pyc'):
            continue
        with open(os.path.join(ROOT, rel), 'rb') as fh:
            lines.append(f'{hashlib.md5(fh.read()).hexdigest()}  {rel}')
    with open(os.path.join(ROOT, 'MANIFEST.md5'), 'w') as f:
        f.write('\n'.join(lines) + '\n')
    print(f'VERSION {ver}; MANIFEST.md5: {len(lines)} files')


if __name__ == '__main__':
    main()
