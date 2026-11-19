# Device protocol v1

Base: `/api/v1/device`. JSON bodies use actual numbers/booleans (not numeric strings). Every request includes `Accept: application/json`, `X-Device-ID: esp32-01`, and `Authorization: Bearer <generated credential>`. POST also uses `Content-Type: application/json`. Body `device_id` must equal the authenticated scale. The plaintext credential is never returned by this API or stored in telemetry. 8 KiB/request maximum; 120 requests/minute/IP.

HTTPS with certificate validation is mandatory on deployments. Plain HTTP is allowed only when Laravel is running local/testing on a trusted development LAN. Do not expose a development server publicly. Device endpoints have no browser CSRF token; staff forms retain normal session/CSRF protection.

## Identifiers and lifecycle

Generate UUIDv4 IDs for each boot and each NEW calibration epoch. Sequence is an integer increasing within a boot (0 is allowed first). The first telemetry from a new boot must be `new`, uncalibrated, with null profile/tare. Previously seen retired boots can never take over again: boot tombstones and sequence cursors are durable. Duplicates/out-of-order messages return 422, and do not refresh freshness. Network retries must not replay a backlog as new/current readings; send the newest measurement with a new sequence, not old captured data with a new timestamp.

Generate a new `tare_revision` UUID for every empty-tray tare. A tare UUID is permanently bound to its scale, boot, calibration epoch and raw zero. A loaded profile creates a new calibration epoch (acknowledged explicitly) and requires a new tare UUID/raw zero. Original profile tare is retained only for audit. Boot, calibration epoch and tare associations survive only as explicitly reported and matched; never infer a medicine from an open browser.

Staff acquire an exclusive expiring session before calibration capture. `GET /commands` returns its opaque ID and expiry; the device includes this ID with the capture event. This session authorizes capture association, not inventory writes. If there is no active session, continue local operation but do not publish a calibration capture for automatic association later.

## POST /readings

```json
{
  "device_id": "esp32-01",
  "boot_id": "11111111-1111-4111-8111-111111111111",
  "sequence": 0,
  "calibration_session_id": "22222222-2222-4222-8222-222222222222",
  "applied_profile_id": null,
  "applied_profile_version": null,
  "tare_revision": null,
  "calibration_stage": "new",
  "current_raw": 100000,
  "current_tare_raw": null,
  "estimated_count": null,
  "stable": false,
  "calibrated": false,
  "firmware_version": "your-version"
}
```

200: `{"accepted":true,"sequence":0,"received_at":1794924000}` (illustrative server Unix timestamp). The arrival timestamp is assigned by the server. Latest telemetry and immutable review snapshots are cached; there is no permanent row per reading. Sequence cursors, boot IDs and tare provenance are durable.

All fields above are required, including explicit nullable fields. `current_raw`/`current_tare_raw` are finite within +/- 10^12 raw units; quantities are 0..1,000,000 prototype data bounds, **not a hardware capacity or overload measurement**. A negative raw calibration factor is valid. Telemetry includes no grams.

### Stages

| Stage | Next stages | Meaning |
| --- | --- | --- |
| new | new, tared | Calibration/profile/tare cleared |
| tared | tared, one_sample | Empty-tray reference captured |
| one_sample | one_sample, ten_sample | One piece detected |
| ten_sample | ten_sample, ready | Ten pieces TOTAL sampled |
| ready | ready, tared | Local calibration/counting ready |
| profile_pending | profile_pending, tare_required | Reserved protocol state; server command is pending until ack |
| tare_required | tare_required, ready | Applied profile has no current zero point |

NEW with a new epoch may interrupt any stage and immediately invalidates the browser profile association and pending commands. Reboot likewise invalidates association. Matching successful profile ack permits transition to `tare_required` (or `ready` with a newly captured tare); ordinary calibration cannot jump directly from NEW to ready. Send every stage event in order while connected, even if ordinary readings are throttled. If disconnected stage transitions cannot be published, resynchronize with a NEW epoch before capturing a web profile. Local calibration/counting must remain available independently; reconnecting does not make offline samples fresh web captures.

`new` clears profile, calibrated flag and tare. `tare_required` clears tare/raw zero/estimate. `ready` alone is insufficient to save: the server still requires a matching acknowledged profile and valid fresh tare. `stable` is the device's signal-settling indicator, never a guarantee of counting accuracy.

## POST /calibrations

After a ready reading from the locally completed one/ten-piece calibration, send:

```json
{
  "device_id": "esp32-01",
  "counting_session_id": "33333333-3333-4333-8333-333333333333",
  "boot_id": "11111111-1111-4111-8111-111111111111",
  "sequence": 4,
  "calibration_session_id": "22222222-2222-4222-8222-222222222222",
  "tare_raw": 100000,
  "one_piece_raw": 99874.5,
  "ten_piece_raw": 98745
}
```

The sequence must exactly match the latest ready telemetry; serialize this event immediately after that reading and before subsequent telemetry. Its boot/epoch must match the authorized active counting session. It must come from local calibration (`applied_profile_id=null`), not a loaded profile. A device-supplied medicine ID is ignored: the authorized staff session determines the medicine only when staff explicitly save the capture.

200: `{"captured":true,"saved_profile":false}`. Capture expires after 3 minutes and is cleared by NEW/reboot/save. It is not a saved profile until staff choose Save captured calibration.

Server calculation:

```text
one_piece_net = one_piece_raw - tare_raw
ten_piece_net = ten_piece_raw - tare_raw
factor = ten_piece_net / 10
```

The factor is floating-point and signed. Zero/non-finite factors, absent one-piece detection, mismatched signs and grossly inconsistent one-piece samples are rejected. Coarse screening requires `one_piece_net / factor` within 0.25..1.75; this generous prototype sanity bound is **not an experimentally validated accuracy tolerance**. The one-piece sample never gets equal weight with ten pieces when determining the factor. Previous profile versions remain; a partial unique SQLite index allows only one active profile per exact medicine/scale pair. Captured profiles must also be loaded/acknowledged and freshly tared before a web count is saved.

## GET /commands

```json
{
  "server_time": "2026-11-17T14:00:00+00:00",
  "active_session": {
    "id": "33333333-3333-4333-8333-333333333333",
    "expires_at": "2026-11-17T14:03:00+00:00"
  },
  "commands": [{
    "command_id": "44444444-4444-4444-8444-444444444444",
    "type": "apply_profile",
    "counting_session_id": "33333333-3333-4333-8333-333333333333",
    "boot_id": "11111111-1111-4111-8111-111111111111",
    "expected_calibration_session_id": "22222222-2222-4222-8222-222222222222",
    "profile_id": 1,
    "profile_version": 1,
    "raw_units_per_piece": -125.5,
    "expires_at": "2026-11-17T14:01:00+00:00"
  }]
}
```

An idle response has `active_session:null` and `commands:[]`. Delivery does not mark success. Commands expire after 60 seconds; status is pending, acknowledged, failed, expired or superseded. Repeated GET returns the same pending command, not another command ID. Issuing a newer command supersedes older pending commands.

Device requirements: apply only an unexpired command for the current boot and **expected current calibration epoch** and still-current counting session. Check age using server time plus a local monotonic receipt timer (bound network delay). On NEW, discard locally buffered commands and generate a new epoch before further network processing. Never execute commands from a previous epoch or reboot. Process a command ID once and resend the identical ack on duplicate delivery; do not clear an already freshly captured tare because a duplicate arrived. Obtain the current pending command response before applying; do not retain commands over disconnection. Server rejection cannot physically undo a command the firmware incorrectly applied, so firmware epoch/expiry checks are essential.

## POST /commands/{command_id}/ack

Successful application creates a fresh calibration epoch and invalidates the prior zero point:

```json
{
  "device_id": "esp32-01",
  "boot_id": "11111111-1111-4111-8111-111111111111",
  "profile_id": 1,
  "profile_version": 1,
  "calibration_session_id": "55555555-5555-4555-8555-555555555555",
  "success": true,
  "error": null
}
```

200: `{"acknowledged":true}`. A matching repeat is safe. Wrong device/profile/version/boot, reused calibration epoch on success, expired/superseded commands or expired sessions fail. On application error send `success:false`, a concise required `error`, and the unchanged epoch; no count becomes enabled. The acknowledgement response accepts/report records the failure; it does not imply a successfully applied profile.

Next send `tare_required` telemetry with the acknowledged profile ID/version and new epoch, null tare/raw zero/estimate. After physical TARE on the empty tray, send ready telemetry with a new tare UUID, current raw zero, signed profile association and stable/calibrated fields. For example current tare 100000, factor -125.5 and raw 94729 produce 42 estimated pieces. Sample pieces remain included in total stock when counting with the original local workflow; loading/re-taring requires an empty tray first.

## Failure responses

| HTTP | Cause | Example |
| --- | --- | --- |
| 401 | Missing/invalid credential or inactive physical device | `{"message":"Invalid device credential."}` |
| 403 | Authenticated device ID mismatch / deployed HTTP | `{"message":"Device identity mismatch."}` |
| 404 | Unknown command or a command belonging to another scale | `{"message":"..."}` |
| 413 | Body exceeds 8 KiB | `{"message":"Payload too large."}` |
| 422 | Shape, state, numeric, sequence, capture or ack mismatch | `{"message":"...","errors":{"reading":["Duplicate or out-of-order sequence."]}}` |
| 429 | Rate limit | `{"message":"Too Many Attempts."}` |

Validation errors use Laravel's `message` and field-keyed `errors` convention. Do not log Authorization headers, Wi-Fi passwords or generated credentials. Network work must not block the local weighing/button/OLED loop.
