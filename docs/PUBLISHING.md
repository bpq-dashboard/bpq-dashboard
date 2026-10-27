# Publishing checklist

This project is developed on a live station and published to GitHub from
the same working copy. Anything specific to one station (passwords,
domains, exact location, home paths) must stay out of what gets pushed.

## How that's arranged

- **Local-only files** are listed in `.gitignore`: `config.php`,
  `db-config.php`, `bbs-config.php`, `nws-config.php`, the `private/`
  folder, runtime data (logs, cache, data), and `*.bak*` files. Each
  config file has a committed `*.example` template.
- **Station values** (callsign, node, hub, grid square, paths, APRS login)
  are read from `config.php` at run time. Public pages get only what
  `station-info.php` returns: callsign, node, grid square and the
  **centre** of the grid square, never your exact coordinates.

## Before every push

```bash
python3 tools/prepublish-check.py
```

On the server, also check that none of your *real* passwords appears
anywhere (they are read from your live config and never printed):

```bash
sudo python3 tools/prepublish-check.py --secrets-from /var/www/bpq-dashboard
```

Exit code `0` = OK to push (review any warnings); `1` = fix first.

Blocked:
- private keys and API tokens
- real-looking password values (placeholders such as `CHANGE_THIS_…` are fine)
- your live secrets (with `--secrets-from`)
- anything on your `!` list

Warned: public IP addresses, real-looking email addresses, and non-`!`
terms from your list.

## Your personal terms list

Create `private/prepublish-terms.txt`. It's gitignored, so it is never
published:

```text
# "!" = block the push, otherwise warn
!mydomain.example
!/home/myname
!12.34.56.78
MYCALL
```

To accept a specific line on purpose, put `prepublish-ok` in it.

## Starting a fresh repository

If something private was ever committed, deleting it in a new commit is
not enough: it stays in the history, and anyone can still open the old
commit. Start again with a repository that has no history:

1. On GitHub, delete the old repository (Settings → General → Danger
   Zone) and create a new, **empty** one with the same name (no README,
   no license, no .gitignore — this project brings its own). A
   force-push is not a substitute: old commits stay reachable by their
   ID.
2. From a clean copy of the working tree:

   ```bash
   sudo python3 tools/prepublish-check.py --secrets-from /var/www/bpq-dashboard
   git init -b main
   git add -A
   git ls-files | grep -E '^private/|^pktnet-forms/|SESSION-JOURNAL|^(db-|bbs-|nws-)?config\.php$|\.bak' \
       && echo "STOP: private file staged" || echo "staging OK"
   git commit -m "BPQ Dashboard v1.5.14"
   git tag -a v1.5.14 -m "BPQ Dashboard v1.5.14"
   git remote add origin https://github.com/OWNER/REPO.git
   git push -u origin main
   git push origin v1.5.14
   ```

   When `git push` asks for a password, give it a GitHub personal access
   token (Contents: read and write), not your account password. If
   pasting into the hidden prompt is awkward (PuTTY), put the token in a
   file you can see, push with it, then delete the file:

   ```bash
   nano ~/.gh-token && chmod 600 ~/.gh-token
   git -c credential.helper= -c credential.helper='!f() { echo username=OWNER; printf "password=%s\n" "$(tr -d " \r\n" < "$HOME/.gh-token")"; }; f' push -u origin main v1.5.14
   rm -f ~/.gh-token
   ```

3. Check that the download link in the README works:
   `https://github.com/OWNER/REPO/archive/refs/tags/v1.5.14.zip`
   should unzip to a folder named `REPO-1.5.14`.
