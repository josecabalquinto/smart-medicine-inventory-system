#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <HX711.h>
#include <WiFi.h>

#include "config.local.h"

#if DEVICE_USE_HTTPS
#include <WiFiClientSecure.h>
#endif
#include <esp_system.h>
#include <math.h>

// Hardware pins are intentionally unchanged from the working local sketch.
constexpr int HX711_DT_PIN = 25;
constexpr int HX711_SCK_PIN = 26;
constexpr int OLED_SDA_PIN = 21;
constexpr int OLED_SCL_PIN = 22;
const int buttonPins[] = {32, 33, 18, 19};

constexpr long MAX_CAPTURE_SPREAD = 1000;
constexpr uint32_t READING_INTERVAL_MS = 2000;
constexpr uint32_t COMMAND_INTERVAL_MS = 5000;
constexpr uint32_t WIFI_ATTEMPT_MS = 10000;
constexpr uint32_t WIFI_BACKOFF_MS = 5000;
constexpr uint32_t HTTP_TIMEOUT_MS = 5000;
constexpr size_t ID_SIZE = 37;

HX711 scale;
Adafruit_SSD1306 display(128, 64, &Wire, -1);

enum Stage {
  WAIT_NEW,
  WAIT_TARE,
  WAIT_ONE,
  WAIT_TEN,
  WAIT_CALIBRATE,
  COUNTING
};

struct TelemetrySnapshot {
  char calibrationSessionId[ID_SIZE];
  char tareRevision[ID_SIZE];
  int profileId;
  int profileVersion;
  double currentRaw;
  double currentTareRaw;
  long estimatedCount;
  bool hasProfile;
  bool hasTare;
  bool hasEstimate;
  bool stable;
  bool calibrated;
  char calibrationStage[20];

  bool hasCalibrationCapture;
  char countingSessionId[ID_SIZE];
  double captureTareRaw;
  double captureOneRaw;
  double captureTenRaw;
};

struct PendingProfile {
  bool available;
  char commandId[ID_SIZE];
  char countingSessionId[ID_SIZE];
  char expectedCalibrationSessionId[ID_SIZE];
  char bootId[ID_SIZE];
  int profileId;
  int profileVersion;
  double unitsPerPiece;
};

struct CommandAck {
  char commandId[ID_SIZE];
  char bootId[ID_SIZE];
  char calibrationSessionId[ID_SIZE];
  int profileId;
  int profileVersion;
  bool success;
  char error[96];
};

Stage stage = WAIT_NEW;
bool lastReading[4] = {HIGH, HIGH, HIGH, HIGH};
bool stableButton[4] = {HIGH, HIGH, HIGH, HIGH};
unsigned long changedAt[4] = {0, 0, 0, 0};

double emptyRaw = 0;
double oneRaw = 0;
double tenRaw = 0;
double unitsPerPiece = 0;
double currentRaw = 0;
long currentSpread = 0;
long currentEstimatedCount = 0;
bool currentStable = false;
bool hasCurrentEstimate = false;

bool profileApplied = false;
bool freshTare = false;
volatile bool waitingProfileAck = false;
int appliedProfileId = -1;
int appliedProfileVersion = -1;

char bootId[ID_SIZE] = "";
char calibrationSessionId[ID_SIZE] = "";
char tareRevision[ID_SIZE] = "";
char activeCountingSessionId[ID_SIZE] = "";
char lastAppliedCommandId[ID_SIZE] = "";

volatile bool networkConnected = false;
volatile bool webSynchronized = false;
volatile bool profileAckNotice = false;

int64_t readingSequence = -1;
unsigned long lastUpdate = 0;

SemaphoreHandle_t stateMutex;
QueueHandle_t telemetryQueue;
QueueHandle_t ackQueue;
PendingProfile pendingProfile = {};

#if DEVICE_USE_HTTPS
WiFiClientSecure networkClient;
#else
WiFiClient networkClient;
#endif

void safeCopy(char *destination, const char *source, size_t size) {
  if (size == 0) return;
  if (source == nullptr) source = "";
  strncpy(destination, source, size - 1);
  destination[size - 1] = '\0';
}

bool sameId(const char *left, const char *right) {
  return left != nullptr && right != nullptr && strcmp(left, right) == 0;
}

void generateUuid(char output[ID_SIZE]) {
  uint8_t bytes[16];
  esp_fill_random(bytes, sizeof(bytes));
  bytes[6] = (bytes[6] & 0x0F) | 0x40;
  bytes[8] = (bytes[8] & 0x3F) | 0x80;

  snprintf(output, ID_SIZE,
           "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
           bytes[0], bytes[1], bytes[2], bytes[3],
           bytes[4], bytes[5], bytes[6], bytes[7],
           bytes[8], bytes[9], bytes[10], bytes[11],
           bytes[12], bytes[13], bytes[14], bytes[15]);
}

const char *connectionLabel() {
  if (!networkConnected) return "OFF";
  if (!webSynchronized) return "NEW";
  return "WEB";
}

void showMessage(const char *line1,
                 const char *line2 = "",
                 const char *line3 = "") {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.print("MEDICINE COUNTER");
  display.setCursor(106, 0);
  display.print(connectionLabel());
  display.setCursor(0, 20);
  display.println(line1);
  display.setCursor(0, 35);
  display.println(line2);
  display.setCursor(0, 50);
  display.println(line3);
  display.display();
}

void showPrompt() {
  if (waitingProfileAck) {
    showMessage("Profile received", "Waiting for server", "Do not tare yet");
    return;
  }

  switch (stage) {
    case WAIT_NEW:
      showMessage("Press NEW to begin");
      break;
    case WAIT_TARE:
      showMessage("Empty the tray", "Then press TARE",
                  profileApplied ? "Saved profile loaded" : "");
      break;
    case WAIT_ONE:
      showMessage("Add ONE piece", "Then press SAMPLE");
      break;
    case WAIT_TEN:
      showMessage("Add 9 more pieces", "10 TOTAL in tray", "Then press SAMPLE");
      break;
    case WAIT_CALIBRATE:
      showMessage("Both samples ready", "Press CALIBRATE");
      break;
    case COUNTING:
      break;
  }
}

// This is the working sketch's capture method. It can wait for the HX711, but
// HTTP never runs on this task, so network failure cannot extend that wait.
bool capture(double &average, long &spread) {
  const int samples = 20;
  double sum = 0;
  long lowest = 8388607L;
  long highest = -8388608L;

  for (int i = 0; i < samples; i++) {
    if (!scale.wait_ready_timeout(1000)) {
      showMessage("HX711 not responding", "Check sensor wiring");
      return false;
    }

    long value = scale.read();
    if (value <= -8388608L || value >= 8388607L) {
      showMessage("Sensor out of range", "Check load / wiring");
      return false;
    }

    sum += value;
    if (value < lowest) lowest = value;
    if (value > highest) highest = value;
  }

  spread = highest - lowest;
  average = sum / samples;

  if (spread > MAX_CAPTURE_SPREAD) {
    showMessage("Reading unsettled", "Wait, release button", "then try again");
    Serial.print("Capture spread: ");
    Serial.println(spread);
    return false;
  }

  return true;
}

const char *protocolStageLocked() {
  if (profileApplied && !freshTare) return "tare_required";

  switch (stage) {
    case WAIT_NEW:
    case WAIT_TARE:
      return "new";
    case WAIT_ONE:
      return "tared";
    case WAIT_TEN:
      return "one_sample";
    case WAIT_CALIBRATE:
      return "ten_sample";
    case COUNTING:
      return "ready";
  }

  return "new";
}

TelemetrySnapshot makeSnapshot(bool includeCalibrationCapture = false) {
  TelemetrySnapshot snapshot = {};

  xSemaphoreTake(stateMutex, portMAX_DELAY);
  safeCopy(snapshot.calibrationSessionId, calibrationSessionId, ID_SIZE);
  safeCopy(snapshot.tareRevision, tareRevision, ID_SIZE);
  snapshot.profileId = appliedProfileId;
  snapshot.profileVersion = appliedProfileVersion;
  snapshot.currentRaw = currentRaw;
  snapshot.currentTareRaw = emptyRaw;
  snapshot.estimatedCount = currentEstimatedCount;
  snapshot.hasProfile = profileApplied;
  snapshot.hasTare = freshTare && tareRevision[0] != '\0';
  snapshot.hasEstimate = hasCurrentEstimate && stage == COUNTING;
  snapshot.stable = currentStable;
  snapshot.calibrated = isfinite(unitsPerPiece) && unitsPerPiece != 0;
  safeCopy(snapshot.calibrationStage, protocolStageLocked(), sizeof(snapshot.calibrationStage));

  if (includeCalibrationCapture && activeCountingSessionId[0] != '\0') {
    snapshot.hasCalibrationCapture = true;
    safeCopy(snapshot.countingSessionId, activeCountingSessionId, ID_SIZE);
    snapshot.captureTareRaw = emptyRaw;
    snapshot.captureOneRaw = oneRaw;
    snapshot.captureTenRaw = tenRaw;
  }
  xSemaphoreGive(stateMutex);

  return snapshot;
}

void loseWebSynchronization() {
  webSynchronized = false;
  if (telemetryQueue != nullptr) xQueueReset(telemetryQueue);
}

void enqueueTelemetryEvent(bool includeCalibrationCapture = false) {
  if (!networkConnected || !webSynchronized) {
    loseWebSynchronization();
    return;
  }

  TelemetrySnapshot snapshot = makeSnapshot(includeCalibrationCapture);
  if (xQueueSend(telemetryQueue, &snapshot, 0) != pdTRUE) {
    // Never replay a partial/overflowed state history as though it were current.
    loseWebSynchronization();
  }
}

void startNewCalibration() {
  xSemaphoreTake(stateMutex, portMAX_DELAY);
  generateUuid(calibrationSessionId);
  tareRevision[0] = '\0';
  activeCountingSessionId[0] = '\0';
  pendingProfile.available = false;
  stage = WAIT_TARE;
  emptyRaw = 0;
  oneRaw = 0;
  tenRaw = 0;
  unitsPerPiece = 0;
  currentEstimatedCount = 0;
  hasCurrentEstimate = false;
  currentStable = false;
  profileApplied = false;
  freshTare = false;
  waitingProfileAck = false;
  appliedProfileId = -1;
  appliedProfileVersion = -1;
  xSemaphoreGive(stateMutex);

  if (networkConnected) {
    webSynchronized = true;
    xQueueReset(telemetryQueue);
    enqueueTelemetryEvent();
  } else {
    loseWebSynchronization();
  }

  showPrompt();
}

void queueFailureAck(const PendingProfile &profile, const char *message) {
  CommandAck ack = {};
  safeCopy(ack.commandId, profile.commandId, ID_SIZE);
  safeCopy(ack.bootId, bootId, ID_SIZE);
  safeCopy(ack.calibrationSessionId, calibrationSessionId, ID_SIZE);
  ack.profileId = profile.profileId;
  ack.profileVersion = profile.profileVersion;
  ack.success = false;
  safeCopy(ack.error, message, sizeof(ack.error));
  xQueueSend(ackQueue, &ack, 0);
}

void applyPendingProfile() {
  PendingProfile profile = {};

  xSemaphoreTake(stateMutex, portMAX_DELAY);
  if (!pendingProfile.available) {
    xSemaphoreGive(stateMutex);
    return;
  }
  profile = pendingProfile;
  pendingProfile.available = false;
  xSemaphoreGive(stateMutex);

  if (!sameId(profile.bootId, bootId)) {
    queueFailureAck(profile, "Boot changed before application");
    return;
  }
  if (!sameId(profile.expectedCalibrationSessionId, calibrationSessionId)) {
    queueFailureAck(profile, "Calibration epoch changed");
    return;
  }
  if (!isfinite(profile.unitsPerPiece) || profile.unitsPerPiece == 0) {
    queueFailureAck(profile, "Invalid profile factor");
    return;
  }

  CommandAck ack = {};

  xSemaphoreTake(stateMutex, portMAX_DELAY);
  webSynchronized = false;
  xQueueReset(telemetryQueue);
  unitsPerPiece = profile.unitsPerPiece;  // Preserve the signed factor.
  appliedProfileId = profile.profileId;
  appliedProfileVersion = profile.profileVersion;
  profileApplied = true;
  freshTare = false;
  waitingProfileAck = true;
  emptyRaw = 0;
  tareRevision[0] = '\0';
  hasCurrentEstimate = false;
  currentStable = false;
  stage = WAIT_TARE;
  generateUuid(calibrationSessionId);
  safeCopy(lastAppliedCommandId, profile.commandId, ID_SIZE);

  safeCopy(ack.commandId, profile.commandId, ID_SIZE);
  safeCopy(ack.bootId, bootId, ID_SIZE);
  safeCopy(ack.calibrationSessionId, calibrationSessionId, ID_SIZE);
  ack.profileId = profile.profileId;
  ack.profileVersion = profile.profileVersion;
  ack.success = true;
  ack.error[0] = '\0';
  xSemaphoreGive(stateMutex);

  xQueueSend(ackQueue, &ack, 0);
  showPrompt();
}

void handleButton(int button) {
  if (button == 0) {
    startNewCalibration();
    return;
  }

  double reading;
  long spread;

  if (button == 1 && stage == WAIT_TARE) {
    if (waitingProfileAck) {
      showMessage("Waiting for server", "Tare after profile ack");
      return;
    }

    showMessage("Capturing empty tray", "Do not touch scale");
    if (!capture(reading, spread)) return;

    xSemaphoreTake(stateMutex, portMAX_DELAY);
    emptyRaw = reading;
    currentRaw = reading;
    currentSpread = spread;
    currentStable = true;
    freshTare = true;
    generateUuid(tareRevision);
    hasCurrentEstimate = profileApplied;
    currentEstimatedCount = 0;
    stage = profileApplied ? COUNTING : WAIT_ONE;
    xSemaphoreGive(stateMutex);

    enqueueTelemetryEvent();
    showPrompt();
  } else if (button == 2 && stage == WAIT_ONE) {
    showMessage("Capturing ONE piece", "Do not touch scale");
    if (!capture(reading, spread)) return;

    double minimumSignal = fmax(1000.0, spread * 5.0);
    if (fabs(reading - emptyRaw) < minimumSignal) {
      showMessage("One piece too small", "Check setup, then", "release and retry");
      return;
    }

    xSemaphoreTake(stateMutex, portMAX_DELAY);
    oneRaw = reading;
    currentRaw = reading;
    currentSpread = spread;
    currentStable = true;
    stage = WAIT_TEN;
    xSemaphoreGive(stateMutex);

    enqueueTelemetryEvent();
    showPrompt();
  } else if (button == 2 && stage == WAIT_TEN) {
    showMessage("Capturing 10 pieces", "Do not touch scale");
    if (!capture(reading, spread)) return;

    double oneSignal = oneRaw - emptyRaw;
    double tenSignal = reading - emptyRaw;
    double ratio = tenSignal / oneSignal;

    if (!isfinite(ratio) || ratio < 7.0 || ratio > 13.0) {
      showMessage("Check sample count", "Need 10 TOTAL", "Release and retry");
      return;
    }

    xSemaphoreTake(stateMutex, portMAX_DELAY);
    tenRaw = reading;
    currentRaw = reading;
    currentSpread = spread;
    currentStable = true;
    stage = WAIT_CALIBRATE;
    xSemaphoreGive(stateMutex);

    enqueueTelemetryEvent();
    showPrompt();
  } else if (button == 3 && stage == WAIT_CALIBRATE) {
    double factor = (tenRaw - emptyRaw) / 10.0;
    if (!isfinite(factor) || factor == 0) {
      showMessage("Calibration failed", "Invalid raw factor", "Press NEW to retry");
      return;
    }

    xSemaphoreTake(stateMutex, portMAX_DELAY);
    unitsPerPiece = factor;
    currentRaw = tenRaw;
    currentEstimatedCount = 10;
    hasCurrentEstimate = true;
    currentStable = true;
    profileApplied = false;
    appliedProfileId = -1;
    appliedProfileVersion = -1;
    stage = COUNTING;
    bool canAssociateCapture = networkConnected && webSynchronized &&
                               activeCountingSessionId[0] != '\0';
    xSemaphoreGive(stateMutex);

    // A capture is associated only if a staff session was already active.
    enqueueTelemetryEvent(canAssociateCapture);
  }
}

bool beginHttp(HTTPClient &http, const String &url) {
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
#if DEVICE_USE_HTTPS
  networkClient.setCACert(DEVICE_ROOT_CA);
#endif
  return http.begin(networkClient, url);
}

void addDeviceHeaders(HTTPClient &http, bool jsonBody = false) {
  http.addHeader("Accept", "application/json");
  http.addHeader("X-Device-ID", DEVICE_ID);
  http.addHeader("Authorization", String("Bearer ") + DEVICE_CREDENTIAL);
  if (jsonBody) http.addHeader("Content-Type", "application/json");
}

bool postCalibrationCapture(const TelemetrySnapshot &snapshot, int64_t sequence) {
  if (!snapshot.hasCalibrationCapture) return true;

  JsonDocument document;
  document["device_id"] = DEVICE_ID;
  document["counting_session_id"] = snapshot.countingSessionId;
  document["boot_id"] = bootId;
  document["sequence"] = sequence;
  document["calibration_session_id"] = snapshot.calibrationSessionId;
  document["tare_raw"] = snapshot.captureTareRaw;
  document["one_piece_raw"] = snapshot.captureOneRaw;
  document["ten_piece_raw"] = snapshot.captureTenRaw;

  String body;
  serializeJson(document, body);

  HTTPClient http;
  if (!beginHttp(http, String(DEVICE_API_BASE) + "/calibrations")) return false;
  addDeviceHeaders(http, true);
  int status = http.POST(body);
  String response = http.getString();
  http.end();

  if (status != 200) {
    Serial.printf("Calibration capture rejected: %d %s\n", status, response.c_str());
    return false;
  }

  return true;
}

bool postReading(const TelemetrySnapshot &snapshot) {
  const int64_t nextSequence = readingSequence + 1;

  // Reserve every sequence before transport. A read timeout is ambiguous: the
  // server may have committed the reading even though its response was lost.
  // Reusing that sequence would then be rejected as a duplicate and strand the
  // device at NEW. Gaps are safe; reused sequence numbers are not.
  readingSequence = nextSequence;

  JsonDocument document;
  document["device_id"] = DEVICE_ID;
  document["boot_id"] = bootId;
  document["sequence"] = nextSequence;
  document["calibration_session_id"] = snapshot.calibrationSessionId;
  if (snapshot.hasProfile) {
    document["applied_profile_id"] = snapshot.profileId;
    document["applied_profile_version"] = snapshot.profileVersion;
  } else {
    document["applied_profile_id"] = nullptr;
    document["applied_profile_version"] = nullptr;
  }
  if (snapshot.hasTare) {
    document["tare_revision"] = snapshot.tareRevision;
    document["current_tare_raw"] = snapshot.currentTareRaw;
  } else {
    document["tare_revision"] = nullptr;
    document["current_tare_raw"] = nullptr;
  }
  document["calibration_stage"] = snapshot.calibrationStage;
  document["current_raw"] = snapshot.currentRaw;
  if (snapshot.hasEstimate) {
    document["estimated_count"] = snapshot.estimatedCount;
  } else {
    document["estimated_count"] = nullptr;
  }
  document["stable"] = snapshot.stable;
  document["calibrated"] = snapshot.calibrated;
  document["firmware_version"] = "medicine-counter-connected/1.0.1";

  String body;
  serializeJson(document, body);

  HTTPClient http;
  if (!beginHttp(http, String(DEVICE_API_BASE) + "/readings")) return false;
  addDeviceHeaders(http, true);
  int status = http.POST(body);
  String response = http.getString();
  http.end();

  if (status != 200) {
    Serial.printf("Reading rejected: %d %s\n", status, response.c_str());
    return false;
  }

  if (!postCalibrationCapture(snapshot, nextSequence)) {
    // The reading was accepted, but this calibration must be repeated rather
    // than attached later with a different sequence.
    return false;
  }

  return true;
}

bool postCommandAck(const CommandAck &ack) {
  JsonDocument document;
  document["device_id"] = DEVICE_ID;
  document["boot_id"] = ack.bootId;
  document["profile_id"] = ack.profileId;
  document["profile_version"] = ack.profileVersion;
  document["calibration_session_id"] = ack.calibrationSessionId;
  document["success"] = ack.success;
  if (ack.success) {
    document["error"] = nullptr;
  } else {
    document["error"] = ack.error;
  }

  String body;
  serializeJson(document, body);

  HTTPClient http;
  String url = String(DEVICE_API_BASE) + "/commands/" + ack.commandId + "/ack";
  if (!beginHttp(http, url)) return false;
  addDeviceHeaders(http, true);
  int status = http.POST(body);
  String response = http.getString();
  http.end();

  if (status != 200) {
    Serial.printf("Command ack rejected: %d %s\n", status, response.c_str());
    return false;
  }

  if (ack.success) {
    xSemaphoreTake(stateMutex, portMAX_DELAY);
    waitingProfileAck = false;
    xSemaphoreGive(stateMutex);
    profileAckNotice = true;
    webSynchronized = true;

    // The first post-ack reading explicitly reports that a new tare is needed.
    TelemetrySnapshot snapshot = makeSnapshot();
    if (!postReading(snapshot)) return false;
  }

  return true;
}

void setActiveSession(const char *sessionId) {
  xSemaphoreTake(stateMutex, portMAX_DELAY);
  safeCopy(activeCountingSessionId, sessionId, ID_SIZE);
  xSemaphoreGive(stateMutex);
}

void offerProfileCommand(JsonObject command) {
  const char *commandId = command["command_id"] | "";
  const char *targetBootId = command["boot_id"] | "";
  const char *expectedEpoch = command["expected_calibration_session_id"] | "";
  const char *countingSessionId = command["counting_session_id"] | "";
  int profileId = command["profile_id"] | -1;
  int profileVersion = command["profile_version"] | -1;
  double factor = command["raw_units_per_piece"] | 0.0;

  if (commandId[0] == '\0') return;

  PendingProfile profile = {};
  safeCopy(profile.commandId, commandId, ID_SIZE);
  safeCopy(profile.countingSessionId, countingSessionId, ID_SIZE);
  safeCopy(profile.expectedCalibrationSessionId, expectedEpoch, ID_SIZE);
  safeCopy(profile.bootId, targetBootId, ID_SIZE);
  profile.profileId = profileId;
  profile.profileVersion = profileVersion;
  profile.unitsPerPiece = factor;

  xSemaphoreTake(stateMutex, portMAX_DELAY);
  bool duplicate = sameId(commandId, lastAppliedCommandId);
  bool validContext = sameId(targetBootId, bootId) &&
                      sameId(expectedEpoch, calibrationSessionId) &&
                      sameId(countingSessionId, activeCountingSessionId);
  bool slotAvailable = !pendingProfile.available;
  if (!duplicate && validContext && slotAvailable &&
      profileId > 0 && profileVersion > 0 &&
      isfinite(factor) && factor != 0) {
    pendingProfile = profile;
    pendingProfile.available = true;
  }
  xSemaphoreGive(stateMutex);

  if (!duplicate && !validContext) {
    queueFailureAck(profile, "Command context mismatch");
  }
}

bool pollCommands() {
  HTTPClient http;
  if (!beginHttp(http, String(DEVICE_API_BASE) + "/commands")) return false;
  addDeviceHeaders(http);
  int status = http.GET();
  String response = http.getString();
  http.end();

  if (status != 200) {
    Serial.printf("Command poll rejected: %d %s\n", status, response.c_str());
    return false;
  }

  JsonDocument document;
  DeserializationError error = deserializeJson(document, response);
  if (error) {
    Serial.print("Command JSON error: ");
    Serial.println(error.c_str());
    return false;
  }

  if (document["active_session"].is<JsonObject>()) {
    setActiveSession(document["active_session"]["id"] | "");
  } else {
    setActiveSession("");
  }

  JsonArray commands = document["commands"].as<JsonArray>();
  for (JsonObject command : commands) {
    if (strcmp(command["type"] | "", "apply_profile") == 0) {
      offerProfileCommand(command);
      break;  // The server exposes at most the current applicable profile.
    }
  }

  return true;
}

bool stateCanStartWebEpoch() {
  xSemaphoreTake(stateMutex, portMAX_DELAY);
  bool canStart = !profileApplied &&
                  (stage == WAIT_NEW || stage == WAIT_TARE);
  xSemaphoreGive(stateMutex);
  return canStart;
}

void connectWiFiBounded() {
  WiFi.disconnect(false, false);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  unsigned long started = millis();

  while (WiFi.status() != WL_CONNECTED &&
         millis() - started < WIFI_ATTEMPT_MS) {
    vTaskDelay(pdMS_TO_TICKS(250));
  }
}

void networkTask(void *parameter) {
  (void)parameter;
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(false);

  unsigned long lastWifiAttempt = 0;
  unsigned long lastReadingSent = 0;
  unsigned long lastCommandPoll = 0;
  unsigned long lastAckAttempt = 0;
  bool wasConnected = false;
  bool hasRetryAck = false;
  CommandAck retryAck = {};

  for (;;) {
    bool connected = WiFi.status() == WL_CONNECTED;

    if (!connected && millis() - lastWifiAttempt >= WIFI_BACKOFF_MS) {
      lastWifiAttempt = millis();
      connectWiFiBounded();
      connected = WiFi.status() == WL_CONNECTED;
    }

    networkConnected = connected;

    if (!connected) {
      if (wasConnected) {
        loseWebSynchronization();
        setActiveSession("");
      }
      wasConnected = false;
      vTaskDelay(pdMS_TO_TICKS(100));
      continue;
    }

    if (!wasConnected) {
      Serial.print("Wi-Fi connected: ");
      Serial.println(WiFi.localIP());
      if (stateCanStartWebEpoch()) {
        webSynchronized = true;
        TelemetrySnapshot initial = makeSnapshot();
        if (!postReading(initial)) loseWebSynchronization();
      }
      wasConnected = true;
      lastReadingSent = millis();
      lastCommandPoll = 0;
    }

    // Retrying is safe only while the physical workflow is still at NEW. Once
    // sampling advances, a failed chain requires another physical NEW.
    if (!webSynchronized && stateCanStartWebEpoch() &&
        millis() - lastReadingSent >= READING_INTERVAL_MS) {
      lastReadingSent = millis();
      TelemetrySnapshot initial = makeSnapshot();
      if (postReading(initial)) webSynchronized = true;
    }

    if (hasRetryAck && millis() - lastAckAttempt >= 1000) {
      lastAckAttempt = millis();
      if (postCommandAck(retryAck)) hasRetryAck = false;
    } else if (!hasRetryAck && xQueueReceive(ackQueue, &retryAck, 0) == pdTRUE) {
      hasRetryAck = true;
      lastAckAttempt = 0;
    }

    if (millis() - lastCommandPoll >= COMMAND_INTERVAL_MS) {
      lastCommandPoll = millis();
      if (!pollCommands()) {
        // Authentication/server errors should not stop local weighing.
        Serial.println("Web command poll unavailable; local scale continues.");
      }
    }

    TelemetrySnapshot event;
    if (webSynchronized && xQueueReceive(telemetryQueue, &event, 0) == pdTRUE) {
      if (!postReading(event)) loseWebSynchronization();
      lastReadingSent = millis();
    } else if (webSynchronized &&
               millis() - lastReadingSent >= READING_INTERVAL_MS) {
      lastReadingSent = millis();
      TelemetrySnapshot latest = makeSnapshot();
      if (!postReading(latest)) loseWebSynchronization();
    }

    vTaskDelay(pdMS_TO_TICKS(25));
  }
}

void setup() {
  Serial.begin(115200);

  stateMutex = xSemaphoreCreateMutex();
  telemetryQueue = xQueueCreate(8, sizeof(TelemetrySnapshot));
  ackQueue = xQueueCreate(4, sizeof(CommandAck));
  if (stateMutex == nullptr || telemetryQueue == nullptr || ackQueue == nullptr) {
    Serial.println("Unable to allocate firmware synchronization objects.");
    while (true) delay(100);
  }

  for (int i = 0; i < 4; i++) {
    pinMode(buttonPins[i], INPUT_PULLUP);
  }

  Wire.begin(OLED_SDA_PIN, OLED_SCL_PIN);
  uint8_t address = 0;
  const uint8_t addresses[] = {0x3C, 0x3D};
  for (uint8_t candidate : addresses) {
    Wire.beginTransmission(candidate);
    if (Wire.endTransmission() == 0) {
      address = candidate;
      break;
    }
  }

  if (address == 0 || !display.begin(SSD1306_SWITCHCAPVCC, address)) {
    Serial.println("OLED initialization failed.");
    while (true) delay(100);
  }

  scale.begin(HX711_DT_PIN, HX711_SCK_PIN);
  if (scale.wait_ready_timeout(1000)) currentRaw = scale.read();

  generateUuid(bootId);
  generateUuid(calibrationSessionId);
  stage = WAIT_NEW;
  showPrompt();

  // Arduino loop runs on core 1 on ESP32; HTTP/Wi-Fi is isolated on core 0.
  xTaskCreatePinnedToCore(networkTask, "medicine-network", 12288,
                         nullptr, 1, nullptr, 0);
}

void loop() {
  if (profileAckNotice) {
    profileAckNotice = false;
    showPrompt();
  }

  applyPendingProfile();

  unsigned long now = millis();
  for (int i = 0; i < 4; i++) {
    bool reading = digitalRead(buttonPins[i]);
    if (reading != lastReading[i]) {
      lastReading[i] = reading;
      changedAt[i] = now;
    }

    if (now - changedAt[i] >= 40 && reading != stableButton[i]) {
      stableButton[i] = reading;
      if (reading == LOW) {
        handleButton(i);
        return;
      }
    }
  }

  if (stage == COUNTING && millis() - lastUpdate >= 800) {
    lastUpdate = millis();
    double reading;
    long spread;
    if (!capture(reading, spread)) return;

    double count = (reading - emptyRaw) / unitsPerPiece;
    bool stable = spread <= fabs(unitsPerPiece) * 0.25;

    xSemaphoreTake(stateMutex, portMAX_DELAY);
    currentRaw = reading;
    currentSpread = spread;
    currentStable = stable;
    xSemaphoreGive(stateMutex);

    if (!stable) {
      hasCurrentEstimate = false;
      showMessage("Count uncertain", "Check scale / load");
      return;
    }

    if (!isfinite(count) || count < -0.5) {
      hasCurrentEstimate = false;
      showMessage("Check tray position", "Or press NEW");
      return;
    }

    long estimatedCount = lround(fmax(0.0, count));
    xSemaphoreTake(stateMutex, portMAX_DELAY);
    currentEstimatedCount = estimatedCount;
    hasCurrentEstimate = true;
    xSemaphoreGive(stateMutex);

    display.clearDisplay();
    display.setTextColor(SSD1306_WHITE);
    display.setTextSize(1);
    display.setCursor(0, 0);
    display.print("ESTIMATED COUNT");
    display.setCursor(106, 0);
    display.print(connectionLabel());
    display.setTextSize(2);
    display.setCursor(0, 22);
    display.print(estimatedCount);
    display.println(" pcs");
    display.setTextSize(1);
    display.setCursor(0, 52);
    display.print("NEW=recal ");
    display.print(connectionLabel());
    display.display();

    Serial.print("Estimated count: ");
    Serial.println(estimatedCount);
  }
}
