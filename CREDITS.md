# Credits

BPQ Dashboard is a web front end. The radio work is done by other people's
software, and the dashboard would be nothing without it. Thank you to:

## John Wiseman, G8BPQ — BPQ32 / LinBPQ

The node, BBS, chat server and APRS digipeater that every page of this
dashboard talks to. BPQ32 and LinBPQ have carried packet and Winlink traffic
for amateur radio networks around the world for decades, and John's
continued work and support on the BPQ mailing list are what make a project
like this possible. The dashboard reads LinBPQ's logs and talks to its node
and BBS over telnet; it is a separate project, not part of LinBPQ, so
questions about the dashboard belong on its GitHub issues, not with John.

- https://www.cantab.net/users/john.wiseman/Documents/index.html

## Dave Freese, W1HKJ — flrig and the fldigi suite

flrig is the rig-control program behind the Rig Status and RF Power Monitor
pages and the frequency and power changes made from the dashboard. The
dashboard talks to flrig's XML-RPC interface, so its support for Yaesu, Icom,
Kenwood, Elecraft and other radios is Dave's work, as is the rest of the
fldigi suite many stations run alongside it.

- http://www.w1hkj.org/

## Glenn Allison, N3MEL — PKTNET / TPRFN HTML Forms

The forms behind the Email Client's **PKTNET Forms** button: ICS 205, 213
and 214, radiograms, SKYWARN and hurricane reports, net check-ins and more,
each producing packet-ready text. The installer downloads them from Glenn's
repository; they remain his work under his own terms.

- https://github.com/N3MEL39/TPRFN_HTML_Forms

## Also built on

These projects and services are used by the dashboard; each keeps its own
license and terms (see `LICENSES/README.md`):

- **VARA HF** (EA5HVK), the modem the VARA HF Terminal and RF Connections
  pages work with
- **APRS-IS** and the aprs2.net servers, and **aprs.fi** for station links
- **APRS symbol set** by Heikki Hannikainen, OH7LZB (hessu/aprs-symbols)
- **National Weather Service** API (NWS alerts) and **NOAA Space Weather
  Prediction Center** (K-index, geomagnetic data)
- **HamQSL.com** solar data
- **QRZ.com** XML data and **callook.info** for callsign locations
- **OpenStreetMap** contributors and **CARTO** basemaps, drawn with
  **Leaflet**
- **Chart.js**, **Tailwind CSS** and the other browser libraries the pages
  load from public CDNs
- **TPRFN** operators, whose network and feedback shaped the RF
  Connections, forwarding and message-handling pages

Naming these projects doesn't mean they endorse BPQ Dashboard; any problem
with the dashboard is ours, not theirs. Please report it on the
dashboard's GitHub issues rather than to them.

If your work is used here and isn't credited correctly, please open an issue
and it will be fixed.

**73**
