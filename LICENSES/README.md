# Third-party components

BPQ Dashboard itself is released under the GNU General Public License
v3 (see `../LICENSE`). The components below are by other authors, are
not part of this repository, and keep their own licenses.

## Downloaded at install time

| Component | Fetched by | Source |
|---|---|---|
| TPRFN HTML Forms (opened by the Email Client's "PKTNET Forms" button, installed as `pktnet-forms/`) | `scripts/fetch-forms.sh`, run by `install.sh` | https://github.com/N3MEL39/TPRFN_HTML_Forms — by Glenn Allison N3MEL |
| APRS symbol sprites | `install.sh` | https://github.com/hessu/aprs-symbols (see that repository for credits and license) |

## Loaded from CDNs by the pages

Leaflet, Chart.js, Tailwind and similar browser libraries are loaded
from their public CDNs at page load; each is covered by its own license
at its source.
