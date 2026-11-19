# ESP32 physical integration

The working local sketch supplied on November 17, 2026 is preserved at
`medicine-counter-local/medicine-counter-local.ino`. The separate integration is
`medicine-counter-connected/medicine-counter-connected.ino`; the local baseline
is not overwritten. No physical upload or hardware test has been performed yet.

The connected version preserves the pins, four-button workflow, signed raw
factor, 20-reading capture, 1/10-total consistency check, spread checks and OLED
count display. Wi-Fi and HTTP execute in a bounded FreeRTOS task on ESP32 core 0;
the Arduino sensor/button/OLED loop remains on core 1. Failed network state is not
replayed later as fresh telemetry. If synchronization is lost after calibration
has advanced, the OLED shows `NEW`, local counting continues, and staff must press
physical NEW before starting another web-associated calibration.

Copy `medicine-counter-connected/config.example.h` to `config.local.h` in that
same folder. Populated `config.local.h` and `secrets.h` files are ignored by Git.
There are no real Wi-Fi or device secrets in this repository.

## Arduino requirements and upload

Install/select an ESP32 Arduino core compatible with the ESP32 Dev Module, then
install these Arduino libraries:

- Adafruit GFX Library
- Adafruit SSD1306
- HX711 by Bogdan Necula
- ArduinoJson 7

`WiFi`, `HTTPClient`, FreeRTOS and `WiFiClientSecure` come from the ESP32 Arduino
core. Open `medicine-counter-connected.ino`, select the correct ESP32 board and
serial port, compile, then upload. Start Serial Monitor at 115200 baud. A local
server connection prints the ESP32 IP and HTTP rejection details without printing
the credential.

For the current local network, start Laravel with:

```powershell
php artisan serve --host=0.0.0.0 --port=8765
```

The supplied configuration example targets
`http://192.168.254.144:8765/api/v1/device`. Confirm the computer still has that
address before uploading. The computer and ESP32 must use the same non-guest Wi-Fi,
and Windows Firewall must allow private-network TCP traffic to port 8765. Use
plain HTTP only for this trusted local/testing setup. A deployed endpoint must set
`DEVICE_USE_HTTPS` to 1 and include its real CA certificate; the sketch has no
insecure TLS fallback.

## Wiring to preserve

| Component | Pin |
| --- | --- |
| HX711 DT | GPIO25 |
| HX711 SCK | GPIO26 |
| OLED SDA | GPIO21 |
| OLED SCL | GPIO22 |
| NEW | GPIO32 |
| TARE | GPIO33 |
| SAMPLE | GPIO18 |
| CALIBRATE | GPIO19 |

ESP32 DevKit, 1 kg four-wire load cell, HX711, 128x64 I2C OLED using working Adafruit SSD1306 code, four normally open momentary buttons using INPUT_PULLUP. There is no identified pin conflict and none of these assignments should change.

## Preserve local behavior

NEW starts a new calibration. TARE records the empty tray. SAMPLE first takes ONE piece. Leave it in place, add NINE (TEN total), then SAMPLE again. CALIBRATE uses `(ten_raw - calibration_tare_raw) / 10` as the signed fractional raw units/piece. One-piece net is a coarse detection/consistency check, not another equally weighted sample. Added weight currently decreases raw readings, so negative factors are expected. Reject zero/non-finite factors. Count `round((current_raw-current_session_tare_raw)/factor)`. Sample pieces remain part of the total when stock is subsequently added. Never convert to grams without an implemented/validated weight calibration.

The 1 kg capacity includes the platform, tray and all contents. No measured overload threshold or verified accuracy percentage exists. Stable means settled signal, not guaranteed piece accuracy. The occasional missed single-piece change remains an unresolved physical measurement issue; neither web logic nor the simulator demonstrates that it has been fixed.

## Implemented network behavior

1. Bounded Wi-Fi reconnect and 5-second HTTP connect/request timeouts run on core 0.
2. Boot, calibration and tare UUIDv4 identifiers plus a per-boot monotonic sequence are reported.
3. The bounded eight-event telemetry queue retains ordered button-stage events while the server is healthy. Overflow or a failed accepted-state chain forces a physical NEW rather than replaying stale data.
4. Current telemetry publishes about every two seconds; commands poll about every five seconds.
5. Calibration capture is sent only when an authorized staff counting session was already known at CALIBRATE time, using the exact accepted ready-reading sequence.
6. Saved profiles are checked against command ID, boot, active staff session, expected calibration epoch and a finite nonzero signed factor.
7. Successful application creates a new calibration epoch, acknowledges the command, reports `tare_required`, and blocks physical TARE until the acknowledgement succeeds.
8. A new tare UUID and raw zero are generated only after the user physically presses TARE with the empty tray.
9. Physical NEW clears the applied profile, tare, pending local command and web association while leaving the local scale usable.
10. OLED status is `OFF` when disconnected, `NEW` when web resynchronization requires physical NEW, and `WEB` while synchronized.
11. Reading sequence numbers are reserved before HTTP delivery and never reused. If a response times out after Laravel committed the reading, the next attempt uses a higher sequence instead of becoming trapped in a duplicate-response loop.

The firmware cannot prove command expiry from wall-clock time because the ESP32 has
no trusted clock in this prototype. It applies only commands that the server still
returns as pending and immediately valid, and checks boot/session/epoch again before
application. The server filters expired/superseded commands. A deployed iteration
may add authenticated time synchronization if offline command retention is ever
introduced; this version deliberately does not retain commands across disconnects.

## Physical acceptance checklist

- Verify every existing pin/button and OLED function before/after integration.
- Exercise local one/ten-total calibration with negative raw readings and confirm remaining stock includes sample pieces.
- Verify repeated known counts, adding/removing one piece, tray-position variation, drift, vibration and settling; retain the observed missed-single-piece limitation until measurements show otherwise.
- Confirm all-stock identity prompts and compare estimated/manual counts. Never use the proof of concept as dispensing verification.
- Load each medicine's saved profile: exact matching ack, original tare cleared, fresh empty-tray tare required. Test wrong versions, expired/superseded commands and NEW racing with profile delivery.
- Test Wi-Fi loss, slow/unreachable server, reconnect and reboot while buttons/weighing/OLED remain responsive. Confirm no buffered old readings become fresh stock evidence.
- Verify sequence reset is accepted only for a new boot; old boots/readings cannot take over. Verify fresh tare IDs after every new profile/boot.
- Verify two staff cannot own the scale simultaneously; browser closure expires the reservation; Finish releases it.
- Confirm only staff Save Count replaces stock and inserts one history record, including on lost response/retry. Prove unstable/stale/uncalibrated/mismatched profile states cannot save.
- Record actual hardware, firmware and library versions and results here after on-device testing. Simulator success must remain separately labeled.
