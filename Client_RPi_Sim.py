import requests
import socket
import time
import threading
import serial
import math             
import smbus            
from mpu6050 import mpu6050     # For interface with an MPU6050 sensor in Python
import pynmea2                  # For parse and handle GPS data that comes in NMEA 0183 format
import subprocess               # For running system commands
import re                       # For parsing command output
import RPi.GPIO as GPIO         # For Watchdog Tamper Detection
import sys                      # Required for WD_CleanSlate_setup exit and general use
import random                   # [ADDED] Required for pH/DO Simulation
from datetime import datetime, timedelta # Required for WD time tracking

# --- HMC5883L Digital Compass Driver (Integrated from HMC5883L_Compass.py) ---
class HMC5883L:
    """
    HMC5883L 3-Axis Digital Compass Driver for Raspberry Pi
    """
    # I2C address
    ADDRESS = 0x1E
    
    # Register addresses
    CONFIG_A = 0x00
    CONFIG_B = 0x01
    MODE = 0x02
    DATA_X_MSB = 0x03
    DATA_X_LSB = 0x04
    DATA_Z_MSB = 0x05
    DATA_Z_LSB = 0x06
    DATA_Y_MSB = 0x07
    DATA_Y_LSB = 0x08
    STATUS = 0x09
    
    # Configuration values
    SAMPLES_8 = 0b11
    DATA_RATE_15HZ = 0b100
    MEASUREMENT_MODE_NORMAL = 0b00
    GAIN_1090 = 0b001  # ±1.3 Ga, LSb/Gauss: 1090
    MODE_CONTINUOUS = 0b00
    
    def __init__(self, bus): # Takes the already-initialized smbus object
        """Initialize the HMC5883L sensor"""
        self.bus = bus # Use the passed-in bus object
        self.scale = 0.92  # Scale factor for gain 1090
        
        # Configure the sensor
        self._configure()
        
    def _configure(self):
        """Configure the HMC5883L registers"""
        # Config A: 8 samples average, 15Hz output rate, normal measurement
        config_a = (self.SAMPLES_8 << 5) | (self.DATA_RATE_15HZ << 2) | self.MEASUREMENT_MODE_NORMAL
        self.bus.write_byte_data(self.ADDRESS, self.CONFIG_A, config_a)
        
        # Config B: Gain
        config_b = self.GAIN_1090 << 5
        self.bus.write_byte_data(self.ADDRESS, self.CONFIG_B, config_b)
        
        # Mode: Continuous measurement mode
        self.bus.write_byte_data(self.ADDRESS, self.MODE, self.MODE_CONTINUOUS)
        
        time.sleep(0.1)  # Wait for sensor to be ready
        
    def _read_word_2c(self, register):
        """Read a 2's complement 16-bit word from the sensor"""
        high = self.bus.read_byte_data(self.ADDRESS, register)
        low = self.bus.read_byte_data(self.ADDRESS, register + 1)
        
        value = (high << 8) + low
        
        # Convert to signed value
        if value >= 0x8000:
            return -((65535 - value) + 1)
        else:
            return value
    
    def read_raw(self):
        """Read raw magnetometer data (x, y, z)"""
        x = self._read_word_2c(self.DATA_X_MSB)
        z = self._read_word_2c(self.DATA_Z_MSB)
        y = self._read_word_2c(self.DATA_Y_MSB)
        
        return (x, y, z)
    
    def read_scaled(self):
        """Read scaled magnetometer data in Gauss"""
        x, y, z = self.read_raw()
        return (x * self.scale, y * self.scale, z * self.scale)
    
    def get_heading(self):
        """Calculate compass heading in degrees (0-360)"""
        x, y, z = self.read_scaled()
        
        # Calculate heading (assuming sensor is level)
        heading_rad = math.atan2(y, x)
        
        # Convert to degrees
        heading_deg = math.degrees(heading_rad)
        
        # Normalize to 0-360
        if heading_deg < 0:
            heading_deg += 360
            
        return heading_deg
    
    def get_direction(self, heading):
        """Convert heading to cardinal direction"""
        directions = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"]
        index = round(heading / 45) % 8
        return directions[index]

# --- New Kalman Filter Class for Sensor Fusion ---
class KalmanFilterYaw:
    """
    A simple 1D Kalman Filter for combining Gyro rate (prediction) 
    with Magnetometer/Accelerometer data (correction) to estimate Yaw.
    """
    def __init__(self, Q, R):
        # Q: Process Noise Covariance (Gyro drift trust)
        self.Q = Q
        # R: Measurement Noise Covariance (Mag/Accel noise trust)
        self.R = R
        # P: Error Covariance Matrix
        self.P = 1.0
        # X: State Estimate (Estimated yaw angle)
        self.X = 0.0

    def predict(self, dt, gyro_rate):
        """Prediction step: Use the gyro to predict the next state."""
        self.X += gyro_rate * dt
        self.P += self.Q
        return self.X

    def update(self, measurement):
        """Correction step: Use the Magnetometer/Accelerometer measurement to correct the state."""
        # 1. Kalman Gain (K)
        K = self.P / (self.P + self.R)
        
        # 2. Update Estimate (X)
        # Use short-angle difference for proper 0/360 wrap-around: (measurement - X_k)
        error = measurement - self.X
        # Normalize error to [-180, 180) degrees
        if error > 180.0:
            error -= 360.0
        elif error < -180.0:
            error += 360.0
            
        self.X += K * error
        
        # 3. Update Error Covariance (P)
        self.P *= (1 - K)
        
        # Normalize the final yaw estimate to [0, 360)
        self.X = math.fmod(self.X, 360.0)
        if self.X < 0:
            self.X += 360.0
            
        return self.X

# ------------------ CONFIG ------------------
SERVER_URL = "http://192.168.0.2:5000"  
SERIAL_PORT = "/dev/ttyUSB0"  # /dev/ttyUSB1 or /dev/ttyACM0          
BAUD_RATE = 9600

# ----------- WATCHDOG CONFIG  ---------------
TAMPER_PIN = 26                 # BCM GPIO
TAMPER_ID = "WATCHDOG_TAMPER"   # Unit ID for telemetry
TAMPER_WARNING_MSG = "WAVE Tampered"

# Timer configuration
CLEAN_SLATE_DELAY_MINUTES = 3
STATUS_REPORT_INTERVAL_HOURS = 4

# Global tracking variables
tamper_start_time = None
last_status_report_time = None
# NEW FLAG: Tracks if the initial warning message has been printed
initial_warning_printed = False

#---------------------------------------------

# --- IMU CONFIG ---
MPU6050_ADDRESS = 0x68 
I2C_BUS = 1           
TEMP_OUT_H = 0x41     
GYRO_DRIFT_THRESHOLD = 0.5 
CALIBRATION_TIME = 5.0     

# --- TELEMETRY FILTER CONFIG (Existing) ---
YAW_SEND_THRESHOLD = 1.0    
TEMP_SEND_THRESHOLD = 1.0   
GPS_CHANGE_THRESHOLD = 0.00001 
GPS_FORCED_SEND_INTERVAL = 30.0 

# --- HMC5883L CONFIG (From previous step) ---
HEADING_SEND_THRESHOLD = 2.0  # Degrees: Send if absolute heading changes by this amount or more
HEADING_FORCED_SEND_INTERVAL = 10.0 # Seconds: Force send periodically

# --- SENSOR FUSION CONFIG (NEW) ---
FUSION_RATE_HZ = 20.0
FUSION_SEND_THRESHOLD = 5.0 # Degrees: Send if fused heading changes by this amount (increased from 0.5)
FUSION_FORCED_SEND_INTERVAL = 30.0 # Seconds: Force send periodically (increased from 5.0)
# Kalman Filter Tuning Parameters
KF_Q_PROCESS_NOISE = 0.001  # Low for smooth, slow-drift Gyro
KF_R_MEAS_NOISE = 0.1       # Higher for noisy Mag/Accel
# --- End SENSOR FUSION CONFIG ---

# --- GPS CONFIG ---
GPS_SERIAL_PORT = "/dev/ttyAMA0"      
GPS_BAUD_RATE = 9600
GPS_TIMEOUT_SECONDS = 0.5
KNOTS_TO_KMH = 1.852                  

# --- WIFI CONFIG (NEW) ---
WIFI_INTERFACE = "wlan0"
WIFI_CHANGE_THRESHOLD = 5.0     # dBm: Send if RSSI changes by this amount or more
WIFI_FORCED_SEND_INTERVAL = 240.0 # Seconds (4 minutes): Force send periodically
# --------------------------------------------

# --- WATER QUALITY (SIMULATION) CONFIG ---
PH_SEND_THRESHOLD = 0.05        # Send if pH changes by this amount
DO_SEND_THRESHOLD = 0.05        # Send if DO changes by this amount
WQ_FORCED_SEND_INTERVAL = 60.0  # Force send every 60 seconds
# --------------------------------------------

# Try to connect to Arduino
try:
    arduino = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=1)
    time.sleep(2)  
    print(f"Connected to Arduino on {SERIAL_PORT} at {BAUD_RATE} baud.")
except Exception as e:
    print("Could not open serial port:", e)
    arduino = None

# --- IMU Initialization ---
sensor = None
bus = None
gyro_z_bias = 0.0
# --- HMC5883L Initialization (Existing) ---
compass = None
# --- NEW: Kalman Filter Instance ---
kf_yaw = None 

try:
    bus = smbus.SMBus(I2C_BUS)
    sensor = mpu6050(MPU6050_ADDRESS)
    print("MPU-6050 Initialized successfully.")
    
    # --- HMC5883L Initialization (NEW) ---
    # The Magnetometer is wired parallel and uses the same bus object
    compass = HMC5883L(bus) 
    print("HMC5883L Compass Initialized successfully.")
    
    # --- NEW: Initialize Kalman Filter ---
    kf_yaw = KalmanFilterYaw(Q=KF_Q_PROCESS_NOISE, R=KF_R_MEAS_NOISE)
    print("Kalman Filter Initialized.")
    
except Exception as e:
    print(f"Error initializing I2C devices: {e}")

# --- GPS Initialization ---
gps_ser = None
try:
    gps_ser = serial.Serial(GPS_SERIAL_PORT, GPS_BAUD_RATE, timeout=GPS_TIMEOUT_SECONDS)
    print(f"GPS Serial port {GPS_SERIAL_PORT} opened successfully.")
except serial.SerialException as e:
    print(f"Error opening GPS serial port: {e}. Check wiring or permissions.")
# --------------------------

#--- Watchdog Initialization ---

def WD_CleanSlate_setup():
    """Initializes the GPIO settings for the Tamper Detection Watchdog."""
    global last_status_report_time
    try:
        GPIO.setmode(GPIO.BCM)
        
        # Configure the pin as an INPUT with an EXTERNAL pull-up resistor.
        GPIO.setup(TAMPER_PIN, GPIO.IN)
        
        # Set the initial status report time to now, so a report is posted immediately.
        last_status_report_time = datetime.now()
        
        print(f"--- Watchdog System Online ---")
        print(f"Monitoring TAMPER_PIN (GPIO {TAMPER_PIN}). Status: SECURE (HIGH).")
        print(f"Clean Slate initiation delay: {CLEAN_SLATE_DELAY_MINUTES} minutes.")
        
    except Exception as e:
        print(f"Error during setup: {e}")
        GPIO.cleanup()
        sys.exit(1)

#---------------------------------------

def send_message_to_pc(msg):
    """Send text or JSON string to PC"""
    try:
        if isinstance(msg, dict):
            res = requests.post(f"{SERVER_URL}/send", json={"message": msg}, timeout=5)
        else:
            res = requests.post(f"{SERVER_URL}/send", json={"message": msg}, timeout=5)
            print("[RPi → PC] Sent:", msg)
    except Exception as e:
        print(f"\nError sending message: {e}")


# =========================================================================
# CORE COMMUNICATION THREADS (EXISTING CODE UNCHANGED)
# =========================================================================

def listen_for_pc_messages():
    """Background thread: poll PC for new messages and forward commands to Arduino."""
    last_seen_id = None
    while True:
        try:
            res = requests.get(f"{SERVER_URL}/get", timeout=5)
            data = res.json()
            
            current_msg = data.get("message")
            current_source = data.get("from")
            
            if current_source == "pc" and data != last_seen_id:
                
                print(f"\n[PC → RPi] Received command: {current_msg}")
                
                if arduino:
                    command_to_send = str(current_msg).strip() + '\n' 
                    try:
                        arduino.write(command_to_send.encode('utf-8'))
                        print(f"[RPi → Arduino] Sent: {command_to_send.strip()}")
                    except Exception as e:
                        print(f" Error writing command to Arduino: {e}")
                else:
                    print(" Cannot forward command: Arduino not connected.")
                
                last_seen_id = data 
                print("\nRPi> ", end="", flush=True) 
            
        except Exception as e:
            pass 
        
        time.sleep(1) 

def read_from_arduino():
    """Background thread: read and send Arduino data/responses/statuses automatically."""
    
    # These IDs are defined elsewhere in the file, but are included here for clarity
    PROPULSION_ID = 10
    F_HOPPER_ID = 13
    
    while arduino:
        try:
            line = arduino.readline().decode("utf-8").strip()
            if not line:
                time.sleep(0.1)
                continue

            first_colon_pos = line.find(':')
            
            if first_colon_pos > 0 and line[:first_colon_pos].isdigit():
                
                slave_id_str = line[:first_colon_pos]
                slave_id = int(slave_id_str)
                data_payload = line[first_colon_pos + 1:] 
                
                # --- Block 1: Handle simple Propulsion ACKs/Errors (ID 10) ---
                if slave_id == PROPULSION_ID and (data_payload.startswith("OK:") or data_payload.startswith("ERROR:")): 
                    print(f"\n[Arduino ID {slave_id} → RPi] Command Response: {data_payload}\nRPi> ", end="", flush=True)

                # --- Block 2: DEDICATED HANDLER FOR FEEDER (ID 13) [THE FIX] ---
                elif slave_id == F_HOPPER_ID: 
                    try:
                        # Check for the full sensor data string (Key:Value,Key:Value)
                        if "," in data_payload and ":" in data_payload:
                            sensor_data = {}
                            parts = data_payload.split(",")
                            is_valid_data = False
                            
                            for part in parts:
                                if ":" in part:
                                    is_valid_data = True
                                    key, value = part.split(":", 1)
                                    
                                    # **[CRITICAL FIX]** Add unit cleanup before float conversion
                                    cleaned_value = value.strip().replace('cm', '').replace('g', '')
                                    
                                    try:
                                        sensor_data[key.strip()] = float(cleaned_value)
                                    except ValueError:
                                        sensor_data[key.strip()] = value.strip()
                            
                            if is_valid_data:
                                sensor_data["slave_id"] = F_HOPPER_ID
                                sensor_data["last_updated"] = time.strftime("%Y-%m-%d %H:%M:%S")
                                
                                send_message_to_pc(sensor_data)
                                
                                print(f"\n[Arduino ID {F_HOPPER_ID} → RPi] Data Parsed & Forwarded: Feed={sensor_data.get('Feed Level', 'N/A')}cm, Weight={sensor_data.get('Total Weight', 'N/A')}g\nRPi> ", end="", flush=True)
                        else:
                            # Handle simple messages like "13:OK" that were previously missed
                            print(f"\n[Arduino ID {F_HOPPER_ID} → RPi] Command Response: {data_payload}\nRPi> ", end="", flush=True)

                    except Exception as e:
                        print(f"[{F_HOPPER_ID}] CRITICAL PARSING ERROR: {e} | Line: {line.strip()}")
                
                # --- Block 3: Generic Sensor Data (Now handles ID 11 and others) ---
                elif "," in data_payload and ":" in data_payload:
                    sensor_data = {}
                    parts = data_payload.split(",")
                    
                    is_valid_data = False
                    for part in parts:
                        if ":" in part:
                            is_valid_data = True
                            key, value = part.split(":", 1)
                            try:
                                # This is fine for WQI (ID 11) as it doesn't send units
                                sensor_data[key.strip()] = float(value.strip())
                            except ValueError:
                                sensor_data[key.strip()] = value.strip()
                    
                    if is_valid_data:
                        sensor_data["slave_id"] = slave_id
                        sensor_data["last_updated"] = time.strftime("%Y-%m-%d %H:%M:%S")

                        send_message_to_pc(sensor_data)
                        
                        print(f"\n[Arduino ID {slave_id} → RPi] Sensor Data Parsed & Forwarded.\nRPi> ", end="", flush=True)
                        continue 
                
                # --- Block 4: Fallback for all other messages ---
                else:
                    print(f"\n[Arduino ID {slave_id} → RPi] Unrecognized RS485 Message: {data_payload}\nRPi> ", end="", flush=True)

            else:
                print(f"\n[Arduino → RPi] Unformatted Serial: {line}\nRPi> ", end="", flush=True)

        except Exception as e:
            print(f"⚠️ Error reading Arduino: {e}")
            
        time.sleep(0.1)


# =========================================================================
# IMU FUNCTIONS (EXISTING CODE UNCHANGED)
# =========================================================================

def read_raw_temp_bits():
    """Reads 16-bit raw temperature data from MPU-6050 (2 bytes)."""
    if bus is None: return 0
    high = bus.read_byte_data(MPU6050_ADDRESS, TEMP_OUT_H)
    low = bus.read_byte_data(MPU6050_ADDRESS, TEMP_OUT_H+1)

    value = ((high << 8) | low)
    
    if(value > 32768):
        value -= 65536
    return value

def calibrate_yaw():
    """Reads gyroscope Z-axis data over a period to find the average bias (drift)."""
    if sensor is None: return 0.0
    print(f"\nCalibrating Gyroscope Z-axis for {CALIBRATION_TIME} seconds...")
    readings = 0
    sum_gz = 0.0
    start_time = time.time()
    
    while (time.time() - start_time) < CALIBRATION_TIME:
        try:
            gyro_data = sensor.get_gyro_data()
            sum_gz += gyro_data['z']
            readings += 1
            time.sleep(0.05)
        except:
            time.sleep(1)

    gyro_z_bias = sum_gz / readings if readings > 0 else 0.0
    print(f"Calibration complete. Z-axis Gyro Bias: {gyro_z_bias:.3f} deg/s")
    return gyro_z_bias

def monitor_imu_and_send_to_pc():
    """Monitors IMU data, tracks Yaw, and sends telemetry to PC only on change (Data-Driven)."""
    global gyro_z_bias
    if sensor is None:
        print("IMU sensor not available. IMU thread exiting.")
        return

    # Ensure calibration is done only once when the thread starts
    if gyro_z_bias == 0.0:
        gyro_z_bias = calibrate_yaw()
        
    current_yaw = 0.0
    last_time = time.time()
    
    last_sent_yaw = None
    last_sent_temp = None
    
    print("\n--- Starting IMU Monitoring Thread with Data Filtering ---")

    while True:
        current_time = time.time()
        dt = current_time - last_time

        try:
            # 1. Read Temperature
            raw_t_val = read_raw_temp_bits() 
            temp = ((raw_t_val) / 333.87) + 21.0 
            temp_rounded = round(temp, 2)

            # 2. Gyroscope Z-axis (Yaw Rate)
            gyro_data = sensor.get_gyro_data()
            raw_gz = gyro_data['z']
            corrected_gz = raw_gz - gyro_z_bias

            # 3. Yaw Tracking Logic
            if abs(corrected_gz) > GYRO_DRIFT_THRESHOLD:
                current_yaw += corrected_gz * dt
                current_yaw = math.fmod(current_yaw, 360.0)
                if current_yaw < 0:
                    current_yaw += 360.0
            
            yaw_rounded = round(current_yaw, 2)
            
            # --- TELEMETRY FILTERING CHECK ---
            should_send = False
            
            if last_sent_yaw is None or abs(yaw_rounded - last_sent_yaw) >= YAW_SEND_THRESHOLD:
                should_send = True
            
            elif last_sent_temp is None or abs(temp_rounded - last_sent_temp) >= TEMP_SEND_THRESHOLD:
                should_send = True

            # 4. Send Telemetry and Update State
            if should_send:
                imu_data = {
                    "IMU_TEMP_C": temp_rounded,
                    "YAW_REL_DEG": yaw_rounded,
                    "GYRO_Z_DPS": round(corrected_gz, 2),
                    "UNIT_ID": "IMU_6050" 
                }
                send_message_to_pc(imu_data)
                
                last_sent_yaw = yaw_rounded
                last_sent_temp = temp_rounded
            
        except Exception as e:
            pass
        
        last_time = current_time
        time.sleep(0.5) 

# =========================================================================
# HMC5883L FUNCTIONS (Existing, but fusion is now preferred)
# =========================================================================
# Kept for completeness, but fusion thread will be used for heading
def monitor_compass_and_send_to_pc():
    """Monitors HMC5883L compass data and sends absolute heading telemetry on change or periodically."""
    global compass
    if compass is None:
        print("HMC5883L compass not available. Compass thread exiting.")
        return

    last_sent_heading = None
    last_forced_send_time = time.time() - HEADING_FORCED_SEND_INTERVAL 
    
    print("\n--- Starting HMC5883L Compass Monitoring Thread with Data Filtering ---")

    while True:
        current_time = time.time()
        
        try:
            heading = compass.get_heading()
            heading_rounded = round(heading, 2)
            
            # --- TELEMETRY FILTERING CHECK ---
            should_send = False
            
            # 1. First time sending data
            if last_sent_heading is None:
                should_send = True
                
            # 2. Heading changed significantly (Data-Driven Telemetry)
            elif abs(math.fmod(heading_rounded - last_sent_heading + 180.0, 360.0) - 180.0) >= HEADING_SEND_THRESHOLD:
                should_send = True
                
            # 3. Forced Periodic Send (Fallback)
            elif (current_time - last_forced_send_time) >= HEADING_FORCED_SEND_INTERVAL:
                should_send = True
                
            if should_send:
                compass_data = {
                    "HEADING_ABS_DEG": heading_rounded,
                    "DIRECTION": compass.get_direction(heading), # Send cardinal direction too
                    "UNIT_ID": "HMC5883L"
                }
                send_message_to_pc(compass_data)
                
                last_sent_heading = heading_rounded
                last_forced_send_time = current_time 

        except Exception as e:
            print(f"⚠️ Compass Monitoring Error: {e}")
            
        time.sleep(0.5) # Check compass every 0.5 seconds


# =========================================================================
# NEW FUSION THREAD FUNCTIONS
# =========================================================================

def calculate_accelerometer_heading(accel_data):
    """
    Calculates Pitch and Roll from Accelerometer data. 
    (Pitch: Rotation about X, Roll: Rotation about Y).
    """
    try:
        roll_rad = math.atan2(accel_data['y'], accel_data['z'])
        pitch_rad = math.atan2(-accel_data['x'], math.sqrt(accel_data['y']**2 + accel_data['z']**2))
        return math.degrees(pitch_rad), math.degrees(roll_rad)
    except:
        return 0.0, 0.0


def calculate_magnetometer_heading_corrected(mag_scaled, pitch_deg, roll_deg):
    """
    Calculates Yaw/Heading from the Magnetometer.
    For this simplified 1D Kalman Filter, we use the simple atan2(y, x) 
    measurement as the correction input.
    """
    # Simplified Yaw calculation (ignores tilt compensation for a 1D KF)
    heading_rad = math.atan2(mag_scaled[1], mag_scaled[0]) # atan2(y, x)
    heading_deg = math.degrees(heading_rad)
    
    # Normalize to 0-360
    if heading_deg < 0:
        heading_deg += 360
        
    return heading_deg


def monitor_fused_heading_and_send_to_pc():
    """
    Background thread: Read data from all three sensors (Gyro, Accel, Mag), 
    apply the Kalman Filter, and send the precise fused heading to the PC.
    """
    global sensor, compass, kf_yaw, gyro_z_bias
    if sensor is None or compass is None or kf_yaw is None:
        print("Required sensors or Kalman Filter not available. Fusion thread exiting.")
        return

    last_time = time.time()
    last_sent_heading = None
    last_forced_send_time = time.time() - FUSION_FORCED_SEND_INTERVAL
    
    dt = 1.0 / FUSION_RATE_HZ
    
    print(f"\n--- Starting Sensor Fusion (Kalman Filter) Monitoring Thread at {FUSION_RATE_HZ}Hz ---")

    while True:
        current_time = time.time()
        
        try:
            # 1. Read All Sensor Data
            accel_data = sensor.get_accel_data()
            gyro_data = sensor.get_gyro_data()
            mag_scaled = compass.read_scaled()

            # 2. Process Gyro (Prediction Input)
            raw_gz = gyro_data['z']
            corrected_gz = raw_gz - gyro_z_bias 
            
            # 3. Process Accel/Mag (Correction Measurement Input)
            pitch_deg, roll_deg = calculate_accelerometer_heading(accel_data)
            measurement_heading = calculate_magnetometer_heading_corrected(mag_scaled, pitch_deg, roll_deg)
            
            # 4. Kalman Filter Calculation
            # Prediction
            kf_yaw.predict(dt, corrected_gz)
            
            # Correction/Update
            fused_heading = kf_yaw.update(measurement_heading)
            
            fused_heading_rounded = round(fused_heading, 2)
            
            # 5. Telemetry Filtering Check
            should_send = False
            
            # 1. First time sending data
            if last_sent_heading is None:
                should_send = True
                
            # 2. Heading changed significantly (Data-Driven Telemetry)
            # Shortest-angle difference for wrap-around
            elif abs(math.fmod(fused_heading_rounded - last_sent_heading + 180.0, 360.0) - 180.0) >= FUSION_SEND_THRESHOLD:
                should_send = True
                
            # 3. Forced Periodic Send (Fallback)
            elif (current_time - last_forced_send_time) >= FUSION_FORCED_SEND_INTERVAL:
                should_send = True
                
            if should_send:
                fused_data = {
                    "HEADING_FUSED_DEG": fused_heading_rounded,
                    "ROLL_ACCEL_DEG": round(roll_deg, 2),
                    "PITCH_ACCEL_DEG": round(pitch_deg, 2),
                    "UNIT_ID": "FUSION_KF"
                }
                send_message_to_pc(fused_data)
                
                last_sent_heading = fused_heading_rounded
                last_forced_send_time = current_time 

        except Exception as e:
            print(f"⚠️ Sensor Fusion Error: {e}")
            
        last_time = current_time
        # Wait until the next iteration time (to maintain FUSION_RATE_HZ)
        time_to_wait = last_time + dt - time.time()
        if time_to_wait > 0:
            time.sleep(time_to_wait)


# =========================================================================
# GPS FUNCTIONS (EXISTING CODE UNCHANGED)
# =========================================================================

def monitor_gps_and_send_to_pc():
    """Reads NMEA data, parses GPRMC, and sends location telemetry only on significant position change or periodically."""
    if gps_ser is None:
        print("GPS serial port not available. GPS thread exiting.")
        return

    print("\n--- Starting GPS Monitoring Thread with Data Filtering and Fallback ---")
    
    last_sent_lat = None
    last_sent_lon = None
    last_forced_send_time = time.time() - GPS_FORCED_SEND_INTERVAL 
    
    while True:
        current_time = time.time()
        
        try:
            line = gps_ser.readline().decode('latin-1', errors='ignore').strip()
            
            if line.startswith('$GPRMC'):
                msg = pynmea2.parse(line)

                if msg.status == 'A':
                    
                    latitude = round(msg.latitude, 6)
                    longitude = round(msg.longitude, 6)
                    
                    speed_knots = msg.spd_over_grnd
                    speed_kmh = float(speed_knots) * KNOTS_TO_KMH
                    
                    # --- GPS Telemetry Filtering Check ---
                    should_send = False
                    
                    # 1. First time sending data
                    if last_sent_lat is None:
                        should_send = True
                        
                    # 2. Position changed significantly (Data-Driven Telemetry)
                    elif abs(latitude - last_sent_lat) >= GPS_CHANGE_THRESHOLD or \
                         abs(longitude - last_sent_lon) >= GPS_CHANGE_THRESHOLD:
                        should_send = True
                        
                    # 3. Forced Periodic Send (Fallback)
                    elif (current_time - last_forced_send_time) >= GPS_FORCED_SEND_INTERVAL:
                        should_send = True
                        
                    if should_send:
                        gps_data = {
                            "LAT": latitude,
                            "LON": longitude,
                            "SPEED_KNOTS": round(float(speed_knots), 2),
                            "SPEED_KMH": round(speed_kmh, 2),
                            "GPS_TIME_UTC": str(msg.timestamp),
                            "STATUS": "Valid Fix (A)",
                            "UNIT_ID": "GPS"
                        }
                        send_message_to_pc(gps_data)
                        
                        last_sent_lat = latitude
                        last_sent_lon = longitude
                        last_forced_send_time = current_time 

                elif msg.status == 'V' and last_sent_lat is not None:
                    send_message_to_pc({"STATUS": "No Valid GPS Fix (V)", "UNIT_ID": "GPS"})
                    last_sent_lat = None
                    last_sent_lon = None
                    
            time.sleep(1.0) 

        except serial.SerialException as e:
            print(f"\nGPS Serial Error: {e}")
            time.sleep(5)
        except pynmea2.ParseError:
            pass 
        except Exception as e:
            print(f"\nGPS Monitoring Error: {e}")
            time.sleep(5)

# =========================================================================
# WIFI FUNCTIONS (EXISTING CODE UNCHANGED)
# =========================================================================

def get_wifi_signal_strength():
    """Reads the WiFi signal strength (RSSI) from the system using iwconfig."""
    try:
        cmd = f"iwconfig {WIFI_INTERFACE}"
        result = subprocess.run(
            cmd,
            shell=True,
            capture_output=True,
            text=True,
            check=False 
        )
        output = result.stdout
        
        # Pattern to match: Signal level=-XX dBm
        match = re.search(r"Signal level=(-?\d+)\s*dBm", output)
        if match:
            return int(match.group(1))
        
        return None 
    
    except Exception:
        return None

def get_wifi_quality_status(rssi):
    """Categorizes the RSSI into quality levels based on user's specification."""
    if rssi is None:
        return "Disconnected/Unknown"
        
    # Excellent: -50dBm or better
    if rssi >= -50:
        return "Excellent"
    # Good: -65dBm to -70dBm (Using a continuous tier: -51 to -70)
    elif rssi >= -70: 
        return "Good"
    # Fair: -70dBm to -80dBm (Using a continuous tier: -71 to -80)
    elif rssi >= -80: 
        return "Fair"
    # Poor: Below -80dBm
    else: 
        return "Poor"

def monitor_wifi_and_send_to_pc():
    """Monitors WiFi RSSI and sends telemetry to PC on change or periodically (4 mins)."""
    
    last_sent_rssi = None
    # Ensures first send happens immediately or shortly after startup
    last_forced_send_time = time.time() - WIFI_FORCED_SEND_INTERVAL 
    
    print("\n--- Starting WiFi Monitoring Thread with Data Filtering and Fallback (4 min) ---")

    while True:
        current_time = time.time()
        
        rssi = get_wifi_signal_strength()
        
        if rssi is not None:
            quality = get_wifi_quality_status(rssi)
            
            should_send = False
            
            # 1. First time sending data
            if last_sent_rssi is None:
                should_send = True
                
            # 2. RSSI changed significantly (Data-Driven Telemetry)
            elif abs(rssi - last_sent_rssi) >= WIFI_CHANGE_THRESHOLD:
                should_send = True
                
            # 3. Forced Periodic Send (Fallback every 4 minutes)
            elif (current_time - last_forced_send_time) >= WIFI_FORCED_SEND_INTERVAL:
                should_send = True
                
            if should_send:
                wifi_data = {
                    "RSSI_DBM": rssi,
                    "WIFI_QUALITY": quality,
                    "UNIT_ID": "WIFI_WLAN0"
                }
                send_message_to_pc(wifi_data)
                
                last_sent_rssi = rssi
                last_forced_send_time = current_time 
                
        # Check WiFi status every 5 seconds
        time.sleep(5.0) 

# =========================================================================
# WATER QUALITY FUNCTIONS (SIMULATED - pH and DO)
# =========================================================================

def get_do_status_text(do_val):
    """Returns status text for Dissolved Oxygen based on value."""
    if do_val < 3.0:
        return "Lethal (Fish Kill)"
    elif do_val < 5.0:
        return "Tolerance (Survival only)"
    else:
        return "Optimal for Growth"

def get_ph_status_text(ph_val):
    """Returns status text for pH based on value."""
    if ph_val < 5.0 or ph_val > 11.0:
        return "Stress Point (Critical)"
    elif 6.5 <= ph_val <= 9.0:
        return "Optimal Range"
    else:
        return "Warning (Sub-optimal)"

def monitor_water_quality_and_send_to_pc():
    """
    Monitors pH and Dissolved Oxygen (DO) data.
    Currently running in SIMULATION mode.
    """
    print("\n--- Starting Water Quality Monitoring Thread (SIMULATION) ---")

    last_sent_ph = None
    last_sent_do = None
    last_forced_send_time = time.time() - WQ_FORCED_SEND_INTERVAL

    while True:
        current_time = time.time()

        try:
            # ### SIMULATION START - TODO: REPLACE WITH REAL SENSOR READ ###
            # Replace this block with actual Serial read or I2C read logic later.
            # Example: line = serial_ph.readline()...
            
            # Simulate pH fluctuating around 7.0
            current_ph = round(random.uniform(6.5, 7.5), 2)
            
            # Simulate DO fluctuating around 8.0 mg/L
            current_do = round(random.uniform(5, 6.5), 2)
            
            # ### SIMULATION END ###

            # --- TELEMETRY FILTERING CHECK ---
            should_send = False
            
            # 1. First time sending data
            if last_sent_ph is None or last_sent_do is None:
                should_send = True
            
            # 2. Value changed significantly (Data-Driven Telemetry)
            elif abs(current_ph - last_sent_ph) >= PH_SEND_THRESHOLD:
                should_send = True
            elif abs(current_do - last_sent_do) >= DO_SEND_THRESHOLD:
                should_send = True
                
            # 3. Forced Periodic Send (Fallback)
            elif (current_time - last_forced_send_time) >= WQ_FORCED_SEND_INTERVAL:
                should_send = True
            
            if should_send:
                wq_data = {
                    "PH_VAL": current_ph,
                    "DO_MGL": current_do,
                    "PH_STATUS": get_ph_status_text(current_ph), # [ADDED]
                    "DO_STATUS": get_do_status_text(current_do), # [ADDED]
                    "UNIT_ID": "WATER_SENSORS_SIM" # TODO: Change ID when real sensors attached
                }
                send_message_to_pc(wq_data)
                
                last_sent_ph = current_ph
                last_sent_do = current_do
                last_forced_send_time = current_time

        except Exception as e:
            print(f"⚠️ Water Quality Sensor Error: {e}")
        
        time.sleep(1.0) # Read sensors every second


# =========================================================================
# WATCHDOG FUNCTIONS (EXISTING CODE UNCHANGED)
# =========================================================================

# --- Clean Slate Watchdog Protocol ---
def WD_CleanSlate_main():
    """Continuously monitors the tamper pin and executes the clean slate action upon trigger."""
    global tamper_start_time, last_status_report_time, initial_warning_printed
    
    # Run setup first before the main loop starts
    WD_CleanSlate_setup()
    
    try:
        while True:
            current_time = datetime.now()
            # Assuming LOW is tampered based on external pull-up resistor setup
            tamper_detected = GPIO.input(TAMPER_PIN) == GPIO.HIGH
            
            # --- State 1: Tamper Detected (Initiate Warning/Delay Timer) ---
            if tamper_detected:
                
                # Check for first detection and print the initial, verbose warning ONCE
                if tamper_start_time is None:
                    tamper_start_time = current_time
                    
                    # Ensure the initial warning prints only once per event
                    if not initial_warning_printed:
                        print("\n" + "*"*50)
                        print("WARNING: CHASSIS TAMPER IN PROGRESS!")
                        print(f"Initial breach detected at: {tamper_start_time.strftime('%Y-%m-%d %H:%M:%S')}")
                        print(f"Clean Slate will initiate in {CLEAN_SLATE_DELAY_MINUTES} minutes unless reset.")
                        # Additionally send a telemetry message to the PC immediately
                        send_message_to_pc({"ALERT": TAMPER_WARNING_MSG, "UNIT_ID": TAMPER_ID, "TIME": tamper_start_time.strftime('%Y-%m-%d %H:%M:%S')})
                        print("Please intervene immediately to prevent data wipe.")
                        print("*"*50)
                        initial_warning_printed = True  # Set the flag to true after printing

                
                # Check if the delay has expired
                delay_expired_time = tamper_start_time + timedelta(minutes=CLEAN_SLATE_DELAY_MINUTES)
                
                if current_time >= delay_expired_time:
                    
                    # *** SECURITY WATCHDOG TRIGGERED (Clean Slate Action) ***
                    print("\n" + "="*50)
                    print("CRITICAL SECURITY ALERT: CLEAN SLATE PROTOCOL INITIATED!")
                    print(f"Tampering persisted for over {CLEAN_SLATE_DELAY_MINUTES} minutes.")
                    print("Executing countermeasures (e.g., Halt Mission, Secure Data Lock, Erase Keys)...")
                    # Send final telemetry message
                    send_message_to_pc({"ALERT": "CLEAN SLATE EXECUTION", "UNIT_ID": TAMPER_ID, "TIME": current_time.strftime('%Y-%m-%d %H:%M:%S')})
                    print("="*50)
                    
                    break # Halt the Python loop upon final action
            
            # --- State 2: System Secure (Reset Timer) ---
            else:
                # If the system was tampered, but the switch is now secure, reset the timer and flag
                if tamper_start_time is not None:
                    print("\n--- Tamper Reset ---")
                    print("Tamper condition cleared. Resetting Clean Slate timer.")
                    print("----------------------")
                    send_message_to_pc({"INFO": "Tamper Reset", "UNIT_ID": TAMPER_ID, "TIME": current_time.strftime('%Y-%m-%d %H:%M:%S')})
                    # Send OK status immediately after reset
                    send_message_to_pc({"WATCHDOG": "OK", "UNIT_ID": TAMPER_ID, "STATUS": "Secure", "TIME": current_time.strftime('%Y-%m-%d %H:%M:%S')})
                    tamper_start_time = None
                    initial_warning_printed = False  # Reset the flag so the warning can print on the next tamper
                    
                # Post system status every four hours (14400 seconds)
                report_interval = timedelta(hours=STATUS_REPORT_INTERVAL_HOURS)
                if current_time >= last_status_report_time + report_interval:
                    status_msg = f"USV Status: Secure. All systems nominal."
                    print(f"[{current_time.strftime('%H:%M:%S')}] {status_msg}  (4-Hour Report)")
                    # Send a telemetry message for the 4-hour report
                    send_message_to_pc({"STATUS": status_msg, "UNIT_ID": "SYSTEM_HEALTH"})
                    # Send watchdog OK status with the 4-hour report
                    send_message_to_pc({"WATCHDOG": "OK", "UNIT_ID": TAMPER_ID, "STATUS": "Secure", "TIME": current_time.strftime('%Y-%m-%d %H:%M:%S')})
                    last_status_report_time = current_time
                
            
            # Short delay for monitoring cycle
            time.sleep(1) 

    # Handle a keyboard interrupt (Ctrl+C)
    except KeyboardInterrupt:
        print("\nManually interrupted watchdog monitoring.")
        
    # --- Cleanup ---
    finally:
        print("\nWatchdog shutdown. Cleaning up GPIO...")
        GPIO.cleanup()


# =========================================================================
# MAIN EXECUTION (UPDATED TO ADD FUSION THREAD)
# =========================================================================

if __name__ == "__main__":
    hostname = socket.gethostname()
    local_ip = socket.gethostbyname(hostname)
    print(f"RPi running as {hostname} ({local_ip})")

    # Start listener for PC messages
    threading.Thread(target=listen_for_pc_messages, daemon=True).start()

    # Start Arduino reader if available
    if arduino:
        threading.Thread(target=read_from_arduino, daemon=True).start()

    # Start IMU sensor thread (Yaw and Temperature)
    if sensor:
        threading.Thread(target=monitor_imu_and_send_to_pc, daemon=True).start()
        
    # Start Sensor Fusion Thread (Magnetometer + Accelerometer + Gyrometer)
    # This thread provides the most accurate heading and supersedes the HMC5883L-only thread.
    if sensor and compass and kf_yaw:
        threading.Thread(target=monitor_fused_heading_and_send_to_pc, daemon=True).start()
        
    # Start GPS sensor thread (Position and Speed)
    if gps_ser:
        threading.Thread(target=monitor_gps_and_send_to_pc, daemon=True).start()
        
    # Start WiFi signal thread 
    threading.Thread(target=monitor_wifi_and_send_to_pc, daemon=True).start()
    
    # Start Water Quality Simulation Thread (pH & DO)
    threading.Thread(target=monitor_water_quality_and_send_to_pc, daemon=True).start()
    
    # Start Watchdog Tamper thread
    threading.Thread(target=WD_CleanSlate_main, daemon=True).start()

        
    # CLI loop for manual chat
    while True:
        msg = input("RPi> ").strip()
        if msg.lower() == "exit":
            break
        if msg:
            send_message_to_pc(msg)

    # app.run(host='0.0.0.0', port=5000, debug=False)