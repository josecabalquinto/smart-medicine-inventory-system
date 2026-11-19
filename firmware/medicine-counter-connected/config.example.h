#pragma once

// Copy to config.local.h in this folder and fill it locally.
#define WIFI_SSID "YOUR_LOCAL_NETWORK"
#define WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#define DEVICE_ID "esp32-01"
#define DEVICE_CREDENTIAL "PASTE_ONCE_GENERATED_CREDENTIAL"

// Local development address of the Laravel computer on the same private Wi-Fi.
#define DEVICE_API_BASE "http://192.168.254.144:8765/api/v1/device"

// Deployed environments must use HTTPS with the real issuing CA certificate.
// No insecure TLS mode is provided.
#define DEVICE_USE_HTTPS 0
#define DEVICE_ROOT_CA R"CERT(
-----BEGIN CERTIFICATE-----
PASTE_DEPLOYMENT_CA_CERTIFICATE_HERE
-----END CERTIFICATE-----
)CERT"
