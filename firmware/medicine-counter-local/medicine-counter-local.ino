#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <HX711.h>
#include <math.h>

HX711 scale;
Adafruit_SSD1306 display(128, 64, &Wire, -1);

const int buttonPins[] = {32, 33, 18, 19};

enum Stage {
  WAIT_NEW,
  WAIT_TARE,
  WAIT_ONE,
  WAIT_TEN,
  WAIT_CALIBRATE,
  COUNTING
};

Stage stage = WAIT_NEW;

bool lastReading[4] = {HIGH, HIGH, HIGH, HIGH};
bool stableButton[4] = {HIGH, HIGH, HIGH, HIGH};
unsigned long changedAt[4] = {0, 0, 0, 0};

double emptyRaw = 0;
double oneRaw = 0;
double tenRaw = 0;
double unitsPerPiece = 0;

unsigned long lastUpdate = 0;

// Initial trial threshold in RAW sensor units.
// This needs checking against your real sensor noise.
const long MAX_CAPTURE_SPREAD = 1000;

void showMessage(const char* line1,
                 const char* line2 = "",
                 const char* line3 = "") {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println("MEDICINE COUNTER");
  display.setCursor(0, 20);
  display.println(line1);
  display.setCursor(0, 35);
  display.println(line2);
  display.setCursor(0, 50);
  display.println(line3);
  display.display();
}

void showPrompt() {
  switch (stage) {
    case WAIT_NEW:
      showMessage("Press NEW to begin");
      break;
    case WAIT_TARE:
      showMessage("Empty the tray", "Then press TARE");
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

// Captures a short batch and checks its spread.
// A stable batch is not a guarantee of counting accuracy.
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

void handleButton(int button) {
  if (button == 0) {
    stage = WAIT_TARE;
    unitsPerPiece = 0;
    showPrompt();
    return;
  }

  double reading;
  long spread;

  if (button == 1 && stage == WAIT_TARE) {
    showMessage("Capturing empty tray", "Do not touch scale");
    if (capture(reading, spread)) {
      emptyRaw = reading;
      stage = WAIT_ONE;
      showPrompt();
    }
  } else if (button == 2 && stage == WAIT_ONE) {
    showMessage("Capturing ONE piece", "Do not touch scale");
    if (!capture(reading, spread)) return;

    double minimumSignal = fmax(1000.0, spread * 5.0);
    if (fabs(reading - emptyRaw) < minimumSignal) {
      showMessage("One piece too small", "Check setup, then", "release and retry");
      return;
    }

    oneRaw = reading;
    stage = WAIT_TEN;
    showPrompt();
  } else if (button == 2 && stage == WAIT_TEN) {
    showMessage("Capturing 10 pieces", "Do not touch scale");
    if (!capture(reading, spread)) return;

    double oneSignal = oneRaw - emptyRaw;
    double tenSignal = reading - emptyRaw;
    double ratio = tenSignal / oneSignal;

    if (ratio < 7.0 || ratio > 13.0) {
      showMessage("Check sample count", "Need 10 TOTAL", "Release and retry");
      return;
    }

    tenRaw = reading;
    stage = WAIT_CALIBRATE;
    showPrompt();
  } else if (button == 3 && stage == WAIT_CALIBRATE) {
    unitsPerPiece = (tenRaw - emptyRaw) / 10.0;
    stage = COUNTING;
  }
}

void setup() {
  Serial.begin(115200);

  for (int i = 0; i < 4; i++) {
    pinMode(buttonPins[i], INPUT_PULLUP);
  }

  Wire.begin(21, 22);

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

  scale.begin(25, 26);
  showPrompt();
}

void loop() {
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
    if (spread > fabs(unitsPerPiece) * 0.25) {
      showMessage("Count uncertain", "Check scale / load");
      return;
    }

    if (count < -0.5) {
      showMessage("Check tray position", "Or press NEW");
      return;
    }

    long estimatedCount = lround(fmax(0.0, count));
    display.clearDisplay();
    display.setTextColor(SSD1306_WHITE);
    display.setTextSize(1);
    display.setCursor(0, 0);
    display.println("ESTIMATED COUNT");
    display.setTextSize(2);
    display.setCursor(0, 22);
    display.print(estimatedCount);
    display.println(" pcs");
    display.setTextSize(1);
    display.setCursor(0, 52);
    display.println("NEW = recalibrate");
    display.display();

    Serial.print("Estimated count: ");
    Serial.println(estimatedCount);
  }
}
