/*
 * ALDIUM SISTEM - Kode ESP8266 (NodeMCU / Wemos D1 mini)
 *
 * Library yang perlu di-install (Arduino IDE > Tools > Manage Libraries):
 *   - OneWire
 *   - DallasTemperature
 *   - Adafruit ADS1X15
 *   - Board: "esp8266 by ESP8266 Community" (Boards Manager)
 *
 * PENTING: ESP8266 hanya punya 1 pin analog (A0). Karena pH dan kekeruhan
 * sama-sama analog, kode ini memakai modul ADS1115 (I2C) untuk membaca keduanya.
 *   ADS1115: SDA -> D2, SCL -> D1, VDD -> 3.3V, GND -> GND
 *            A0 -> keluaran sensor pH, A1 -> keluaran sensor kekeruhan
 * Sensor yang keluarannya sampai 5V WAJIB diturunkan dulu dengan pembagi tegangan,
 * jangan langsung ke ADS1115 yang dayanya 3.3V.
 *
 * Wiring lain:
 *   DS18B20 (suhu)   : data -> D4 (+ resistor 4.7k ke 3.3V)
 *   HC-SR04 (level)  : TRIG -> D6, ECHO -> D7 (ECHO 5V harus lewat pembagi tegangan)
 *   Relay lampu      : IN -> D5
 *   Relay pompa      : IN -> D0
 *   Servo pemberi pakan : sinyal -> D8 (daya servo dari 5V terpisah, GND disatukan)
 */
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <Wire.h>
#include <Adafruit_ADS1X15.h>
#include <Servo.h>

// ================== EDIT BAGIAN INI ==================
const char* WIFI_SSID  = "NAMA_WIFI_ANDA";
const char* WIFI_PASS  = "PASSWORD_WIFI_ANDA";
const char* SERVER_URL = "http://192.168.1.10/aldium/api.php";   // lihat menu Pengaturan di website
const char* API_KEY    = "SAMAKAN-DENGAN-config.php";
const unsigned long INTERVAL_KIRIM_MS = 10000;                    // kirim data tiap 10 detik

// Pin
#define PIN_DS18B20 D4
#define PIN_TRIG    D6
#define PIN_ECHO    D7
#define PIN_LAMPU   D5
#define PIN_POMPA   D0
#define PIN_SERVO   D8
const bool RELAY_AKTIF_LOW = true;   // kebanyakan modul relay aktif saat sinyal LOW

// Kalibrasi tinggi air (ukur jarak sensor ke permukaan air, dalam cm)
const float JARAK_PENUH_CM  = 5.0;   // jarak saat air penuh
const float JARAK_KOSONG_CM = 30.0;  // jarak saat air kosong/batas bawah

// Kalibrasi pH (WAJIB dikalibrasi dengan larutan buffer pH 7 dan pH 4/10)
const float PH_TEGANGAN_NETRAL = 2.50;  // tegangan (V) saat dicelup larutan pH 7.00
const float PH_VOLT_PER_PH     = -0.18; // perubahan tegangan per 1 pH (hasil kalibrasi)

// Kalibrasi kekeruhan (V turun saat air makin keruh)
const float KERUH_V_JERNIH = 3.00;   // tegangan di air jernih (0 NTU)
const float KERUH_V_KERUH  = 2.00;   // tegangan di air sangat keruh
const float KERUH_NTU_MAX  = 300.0;  // NTU yang dianggap pada tegangan KERUH_V_KERUH

// Pemberi pakan (servo)
const int SERVO_DIAM  = 0;     // sudut posisi tertutup
const int SERVO_BUKA  = 90;    // sudut posisi menjatuhkan pakan
const int SERVO_TAHAN_MS = 800;
// =====================================================

OneWire oneWire(PIN_DS18B20);
DallasTemperature suhuSensor(&oneWire);
Adafruit_ADS1115 ads;
Servo servoPakan;
bool adsOk = false;
unsigned long terakhirKirim = 0;

void setRelay(uint8_t pin, bool hidup) {
  digitalWrite(pin, (hidup ^ RELAY_AKTIF_LOW) ? HIGH : LOW);
}

float bacaSuhu() {
  suhuSensor.requestTemperatures();
  float t = suhuSensor.getTempCByIndex(0);
  return (t == DEVICE_DISCONNECTED_C) ? NAN : t;
}

float bacaLevel() {
  digitalWrite(PIN_TRIG, LOW);  delayMicroseconds(3);
  digitalWrite(PIN_TRIG, HIGH); delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  long durasi = pulseIn(PIN_ECHO, HIGH, 30000UL);
  if (durasi == 0) return NAN;
  float jarak = durasi * 0.0343f / 2.0f;
  float persen = (JARAK_KOSONG_CM - jarak) / (JARAK_KOSONG_CM - JARAK_PENUH_CM) * 100.0f;
  return constrain(persen, 0.0f, 100.0f);
}

float bacaPH() {
  if (!adsOk) return NAN;
  float v = ads.computeVolts(ads.readADC_SingleEnded(0));
  return 7.0f + (v - PH_TEGANGAN_NETRAL) / PH_VOLT_PER_PH;
}

float bacaKeruh() {
  if (!adsOk) return NAN;
  float v = ads.computeVolts(ads.readADC_SingleEnded(1));
  float ntu = (KERUH_V_JERNIH - v) / (KERUH_V_JERNIH - KERUH_V_KERUH) * KERUH_NTU_MAX;
  return constrain(ntu, 0.0f, 3000.0f);
}

void beriPakan() {
  Serial.println("Memberi pakan...");
  servoPakan.attach(PIN_SERVO);
  servoPakan.write(SERVO_BUKA);
  delay(SERVO_TAHAN_MS);
  servoPakan.write(SERVO_DIAM);
  delay(500);
  servoPakan.detach();
}

// Ambil angka dari balasan, contoh: ambil("OK|lamp=1|pump=0", "lamp") -> 1
int ambil(const String& teks, const String& nama) {
  int i = teks.indexOf(nama + "=");
  if (i < 0) return -1;
  return teks.charAt(i + nama.length() + 1) == '1' ? 1 : 0;
}

void tambah(String& url, const char* nama, float nilai) {
  if (!isnan(nilai)) {
    url += "&"; url += nama; url += "="; url += String(nilai, 2);
  }
}

void kirimDanTerima() {
  if (WiFi.status() != WL_CONNECTED) return;

  String url = String(SERVER_URL) + "?key=" + API_KEY;
  tambah(url, "temp", bacaSuhu());
  tambah(url, "level", bacaLevel());
  tambah(url, "ph", bacaPH());
  tambah(url, "turb", bacaKeruh());

  WiFiClient client;
  HTTPClient http;
  http.setTimeout(5000);
  if (!http.begin(client, url)) return;
  int kode = http.GET();
  if (kode == 200) {
    String balasan = http.getString();
    Serial.println(balasan);
    int lampu = ambil(balasan, "lamp");
    int pompa = ambil(balasan, "pump");
    int pakan = ambil(balasan, "feed");
    if (lampu >= 0) setRelay(PIN_LAMPU, lampu == 1);
    if (pompa >= 0) setRelay(PIN_POMPA, pompa == 1);
    if (pakan == 1) beriPakan();
  } else {
    Serial.printf("Gagal, kode HTTP: %d\n", kode);
    if (kode > 0) Serial.println(http.getString());
  }
  http.end();
}

void setup() {
  Serial.begin(115200);
  pinMode(PIN_LAMPU, OUTPUT);
  pinMode(PIN_POMPA, OUTPUT);
  setRelay(PIN_LAMPU, false);
  setRelay(PIN_POMPA, false);
  pinMode(PIN_TRIG, OUTPUT);
  pinMode(PIN_ECHO, INPUT);

  suhuSensor.begin();
  Wire.begin(D2, D1);
  adsOk = ads.begin();
  if (!adsOk) Serial.println("ADS1115 tidak ditemukan - pH & kekeruhan tidak dikirim");

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("Menghubungkan WiFi");
  while (WiFi.status() != WL_CONNECTED) { delay(500); Serial.print("."); }
  Serial.println("\nTerhubung, IP: " + WiFi.localIP().toString());
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect();
    delay(3000);
    return;
  }
  if (millis() - terakhirKirim >= INTERVAL_KIRIM_MS) {
    terakhirKirim = millis();
    kirimDanTerima();
  }
}
