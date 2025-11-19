#!/usr/bin/env python3
"""
Non-interactive FA Code Generator
- Reads JSON from stdin:
  { "passphrase": "...", "duration": "weekly|monthly|annually|hour", "usb_copy": true|false }
- Outputs plain text logs to stdout.
- Exits with non-zero code on fatal errors.
"""

import sys
import os
import json
import random
import string
import time
import shutil
import psutil
from datetime import datetime
import mysql.connector

# cryptography imports
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC

# --- CONFIG: adjust to your environment ---
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "MGW4V3J0LL1B33",
    "database": "wave_db"
}

ENCRYPTED_FILENAME = "encrypted_ufa_code.bin"
# fixed salt used by original consumer (keeps compatibility)
FIXED_SALT = b'\x12\x34\x56\x78\x90\xab\xcd\xef\xfe\xdc\xba\x98\x76\x54\x32\x10'

# Time constants
TIME_WEEK = 7 * 24 * 60 * 60
TIME_MONTH = 30 * 24 * 60 * 60
TIME_YEAR = 365 * 24 * 60 * 60
TIME_HOUR = 1 * 60 * 60

# -----------------------
def log(msg):
    print(msg)
    sys.stdout.flush()

def safe_read_stdin():
    try:
        raw = sys.stdin.read()
        if not raw:
            return None
        return json.loads(raw)
    except Exception as e:
        log(f"ERROR: Failed to parse JSON from stdin: {e}")
        return None

def generate_factor_code():
    code = ''.join(random.choices(string.digits, k=6))
    ts = time.time()
    log(f"Generated Factor Authorization Code: {code}")
    return code, ts

def duration_to_seconds(choice):
    choice = (choice or "").lower()
    if choice in ("weekly", "week", "1"):
        return TIME_WEEK, "Weekly"
    if choice in ("monthly", "month", "2"):
        return TIME_MONTH, "Monthly"
    if choice in ("annually", "annual", "year", "3"):
        return TIME_YEAR, "Annually"
    if choice in ("hour", "test", "1hour", "4"):
        return TIME_HOUR, "1 Hour (Test)"
    # default fallback:
    return TIME_HOUR, "1 Hour (Test)"

def insert_code_to_db(auth_code, valid_until_timestamp):
    expiration_date_time = datetime.fromtimestamp(valid_until_timestamp).strftime('%Y-%m-%d %H:%M:%S')
    try:
        db = mysql.connector.connect(**DB_CONFIG)
        cursor = db.cursor()
        sql = "UPDATE auth_table SET USB_OFA = %s, USB_FATS = %s"
        cursor.execute(sql, (auth_code, expiration_date_time))
        db.commit()
        log(f"✅ Saved code {auth_code} with expiration {expiration_date_time} to DB.")
    except Exception as e:
        log(f"❌ DB error: {e}")
    finally:
        try:
            if 'db' in locals() and db.is_connected():
                cursor.close()
                db.close()
        except Exception:
            pass

def derive_key_iv(passphrase_bytes, salt=FIXED_SALT):
    # PBKDF2 -> AES-256 key, use random IV
    kdf = PBKDF2HMAC(
        algorithm=hashes.SHA256(),
        length=32,
        salt=salt,
        iterations=100000,
        backend=default_backend()
    )
    key = kdf.derive(passphrase_bytes)
    iv = os.urandom(16)
    return key, iv, salt

def aes_encrypt(data_bytes, key, iv):
    cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
    encryptor = cipher.encryptor()
    pad_len = 16 - (len(data_bytes) % 16)
    padded = data_bytes + (b' ' * pad_len)
    return encryptor.update(padded) + encryptor.finalize()

def find_removable_drives(timeout_seconds=2):
    # Try to quickly enumerate removable drives; return list of mountpoints
    drives = []
    try:
        for part in psutil.disk_partitions(all=False):
            opts = (part.opts or "").lower()
            mount = part.mountpoint
            # heuristics: removable media often have 'removable' in opts or are on Windows with drive letters and removable media
            if 'removable' in opts or 'cdrom' in opts or mount.startswith('/media') or mount.startswith('/mnt') or (os.name == 'nt' and mount.endswith(':\\')):
                drives.append(mount)
    except Exception as e:
        log(f"Warning: error enumerating drives: {e}")
    return drives

def try_copy_to_usb(local_file, drives):
    results = []
    for d in drives:
        try:
            dest = os.path.join(d, os.path.basename(local_file))
            shutil.copyfile(local_file, dest)
            results.append((d, True, dest))
            log(f"Copied encrypted file to {dest}")
        except Exception as e:
            results.append((d, False, str(e)))
            log(f"Failed to copy to {d}: {e}")
    return results

def main():
    # Read input JSON from stdin
    input_json = safe_read_stdin()
    if not input_json:
        log("ERROR: No input JSON detected. Expected JSON on stdin with keys: passphrase, duration, usb_copy.")
        sys.exit(2)

    passphrase = input_json.get("passphrase", "")
    duration_choice = input_json.get("duration", "hour")  # weekly/monthly/annually/hour
    usb_copy_requested = bool(input_json.get("usb_copy", False))
    usb_label = input_json.get("usb_label", "")  # optional: not used in heuristics, kept for compatibility

    if not passphrase:
        log("ERROR: Empty passphrase provided.")
        sys.exit(3)

    try:
        pass_bytes = passphrase.encode("utf-8")
    except Exception as e:
        log(f"ERROR: Failed to encode passphrase: {e}")
        sys.exit(4)

    # 1) Generate code and compute expiration
    auth_code, ts = generate_factor_code()
    duration_seconds, duration_label = duration_to_seconds(duration_choice)
    valid_until = ts + duration_seconds
    code_data = {
        "code": auth_code,
        "timestamp": ts,
        "valid_until": valid_until,
        "duration_label": duration_label
    }
    log(f"Code valid until: {time.ctime(valid_until)} ({duration_label})")

    # 2) Insert to DB (best-effort)
    insert_code_to_db(auth_code, valid_until)

    # 3) Encrypt data
    plaintext = json.dumps(code_data).encode("utf-8")
    key, iv, salt = derive_key_iv(pass_bytes, salt=FIXED_SALT)
    encrypted = aes_encrypt(plaintext, key, iv)
    file_content = salt + iv + encrypted

    try:
        with open(ENCRYPTED_FILENAME, "wb") as f:
            f.write(file_content)
        log(f"Saved encrypted file: {ENCRYPTED_FILENAME}")
    except Exception as e:
        log(f"ERROR: Could not write encrypted file: {e}")
        sys.exit(5)

    # 4) Optional USB copy (best-effort, non-blocking)
    if usb_copy_requested:
        log("USB copy requested. Scanning for removable drives (best-effort)...")
        drives = find_removable_drives()
        if not drives:
            log("No removable drives detected (skipping USB copy).")
        else:
            try_copy_to_usb(ENCRYPTED_FILENAME, drives)
    else:
        log("USB copy not requested; skipping.")

    log("DONE")
    sys.exit(0)

if __name__ == "__main__":
    main()