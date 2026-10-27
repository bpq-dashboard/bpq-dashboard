# RF Power Monitor → FLRIG Migration

Moves `rf-power-monitor.html`'s live gauge + all historical analytics
(Power History chart, Hourly Stats, Activity Heatmap, TX Events, playback
scrubber) off the now-decommissioned WaveNode USB hardware and onto the
radio's own internal meter, read via FLRIG through `vara-api.php` — the
same source `rig-status.html` already used for its simpler gauges.

`rig-status.html`'s TX Power/SWR gauges and 30-day history card have been
removed accordingly — that data now lives exclusively on
`rf-power-monitor.html`. `rig-status.html` keeps its frequency display,
S-meter, PTT indicator, and power control — untouched.

## Files changed

| File                    | What changed |
|--------------------------|--------------|
| `vara-api.php`           | `logRigHistory()` now also records `mhz`/`band` per sample (previously only `pwr`/`swr`/`tx`). Backward compatible — older log lines simply lack these fields. |
| `rf-power-monitor.html`  | Data source swapped from WaveNode/legacy CSV to `vara-api.php`. DC Volts/Amps cards removed entirely (no replacement — WaveNode was their only source). Manual CSV file-import button removed. Live gauge now polls `get_rig_info` every 3s. History/analytics now read `get_rig_history`. |
| `rig-status.html`        | TX Power and SWR gauge cards removed. 30-day TX Power/SWR history card and its Chart.js dependency removed. |

## Important: known accuracy tradeoff (as discussed)

WaveNode sampled continuously (~1/sec). FLRIG-sourced history is throttled
to roughly 1 sample/minute while idle (every 3s during active TX). This
page's Hourly Stats, Activity Heatmap, TX Events (duty-cycle %, watt-hours)
and Playback all now run on that coarser data, per your choice to rebuild
everything best-effort rather than drop those tabs. Session/duration
figures will be reasonably accurate for typical multi-second-to-minutes
transmissions, but won't have the same sub-second precision the old
WaveNode-based version had. This is inherent to the new data source, not
a bug — flagging it here so it's not a surprise later.

There is no reflected-power reading from FLRIG (it only reports a derived
SWR value). The Reflected-watts figure shown is back-calculated from
FLRIG's reported SWR + forward power using the same VSWR formula this page
already used (`calcSWR`), just run in reverse — so all downstream SWR math
still checks out, but "Reflected" isn't a directly hardware-measured value
anymore.

## Deploy

```bash
sudo cp vara-api.php rf-power-monitor.html rig-status.html /var/www/bpq-dashboard/
sudo chown www-data:www-data /var/www/bpq-dashboard/vara-api.php \
    /var/www/bpq-dashboard/rf-power-monitor.html /var/www/bpq-dashboard/rig-status.html
```

No schema/database changes, no new dependencies, no service restarts
needed. The rig-history log file itself (`logs/rig-history.jsonl` or
wherever your config points it) is unchanged in location — new entries
just carry two more fields.

## Verify

1. Open `rig-status.html` — confirm the frequency display, S-meter, and
   PTT/power controls all still work, and that the TX Power/SWR gauges
   and history chart are gone (replaced by nothing — that space is now
   just the S-meter card and frequency panel).
2. Open `rf-power-monitor.html` — the Channel 1 card should update every
   ~3 seconds with power/SWR from the rig. Key at the radio (or wait for
   a real transmission) and confirm the bar meter, peak readout, and SWR
   mini-bar respond.
3. Check the frequency/band tag on the Channel 1 card appears during TX —
   it should now show the rig's actual frequency, not a VARA-connection
   guess.
4. After a few minutes/transmissions, check the Power History tab
   populates, and that Hourly Stats / Heatmap / TX Events show something
   (will be sparse until enough new FLRIG-sourced samples accumulate —
   old WaveNode-era log lines lack `mhz`/`band` and will show blank tags
   there, which is expected).
5. Confirm the DC Volts/Amps cards are gone from the summary row.

## Rollback

Restore the three files from backup/git if anything's wrong — no data
migration occurred, so there's nothing to reverse in the database or log
files themselves.
