#pragma once
// Copy this file to medicine-counter-connected/config.local.h.
// Never commit the populated copy.
#define WIFI_SSID "YOUR_LOCAL_NETWORK"
#define WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#define DEVICE_ID "esp32-01"
#define DEVICE_CREDENTIAL "PASTE_ONCE_GENERATED_CREDENTIAL"
// Local development only; deployed devices must use HTTPS and validate its certificate.
#define DEVICE_API_BASE "http://192.168.254.144:8765/api/v1/device"

// Set to 1 for a deployed HTTPS endpoint and paste the issuing CA certificate below.
// The connected sketch deliberately has no insecure TLS mode.
#define DEVICE_USE_HTTPS 0
#define DEVICE_ROOT_CA R"CERT(
-----BEGIN CERTIFICATE-----
PASTE_DEPLOYMENT_CA_CERTIFICATE_HERE
-----END CERTIFICATE-----
)CERT"
