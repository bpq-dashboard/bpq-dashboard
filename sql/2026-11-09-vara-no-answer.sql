-- v1.5.30: outgoing VARA calls that nobody answered were stored as 'no_data'
-- (RF Connections: No Data). They are now 'no_answer' (shown under Timeout
-- (no prop)). Moves the existing rows: outgoing, 0 bytes both ways, no S/N
-- (a link that came up has an S/N line). Safe to repeat.
UPDATE rf_connections
   SET failure_reason = 'no_answer'
 WHERE failure_reason = 'no_data'
   AND direction = 'outgoing'
   AND COALESCE(tx_bytes, 0) = 0
   AND COALESCE(rx_bytes, 0) = 0
   AND avg_snr_db IS NULL;
