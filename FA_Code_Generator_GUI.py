import os
import json
import random
import string
import time
import shutil
import psutil
import getpass
import mysql.connector
from datetime import datetime
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC

import tkinter as tk
from tkinter import ttk, messagebox, filedialog

# -------------------- ORIGINAL CONFIG --------------------
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "MGW4V3J0LL1B33",
    "database": "wave_db"
}

ENCRYPTED_FILENAME = "encrypted_ufa_code.bin"
# Expected exact volume label of the authorized USB (case-insensitive)
USB_LABEL = "UFA_CODE"

TIME_WEEK = 7 * 24 * 60 * 60
TIME_MONTH = 30 * 24 * 60 * 60
TIME_YEAR = 365 * 24 * 60 * 60
TIME_HOUR = 1 * 60 * 60

# -------------------- ORIGINAL FUNCTIONS --------------------
def insert_code_to_db(auth_code, valid_until_timestamp):
    try:
        expiration_dt = datetime.fromtimestamp(valid_until_timestamp).strftime('%Y-%m-%d %H:%M:%S')
        db = mysql.connector.connect(**DB_CONFIG)
        cursor = db.cursor()

        sql = """UPDATE auth_table SET USB_OFA = %s, USB_FATS = %s"""
        cursor.execute(sql, (auth_code, expiration_dt))
        db.commit()
        cursor.close()
        db.close()
        return True, f"Saved to DB. Expires: {expiration_dt}"
    except mysql.connector.Error as err:
        return False, str(err)

def generate_key_and_iv(password, salt=None):
    if salt is None:
        salt = os.urandom(16)

    kdf = PBKDF2HMAC(
        algorithm=hashes.SHA256(),
        length=32,
        salt=salt,
        iterations=100000,
        backend=default_backend()
    )
    key = kdf.derive(password)
    iv = os.urandom(16)
    return key, iv, salt

def aes_encrypt(data, key, iv):
    cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
    encryptor = cipher.encryptor()
    padding_len = 16 - (len(data) % 16)
    padded = data + b" " * padding_len
    return encryptor.update(padded) + encryptor.finalize()

def generate_factor_code():
    return ''.join(random.choices(string.digits, k=6)), time.time()

def _get_volume_label_windows(path):
    try:
        import ctypes
        # buffers for volume name and filesystem name
        volname_buf = ctypes.create_unicode_buffer(1024)
        fsname_buf = ctypes.create_unicode_buffer(1024)
        res = ctypes.windll.kernel32.GetVolumeInformationW(
            ctypes.c_wchar_p(path), volname_buf, ctypes.sizeof(volname_buf), None, None, None, fsname_buf, ctypes.sizeof(fsname_buf)
        )
        if res:
            return volname_buf.value
    except Exception:
        pass
    return None

def find_usb_drive(expected_label=None):
    """
    Find a mounted drive whose volume label matches `expected_label` (case-insensitive).
    On Windows this uses GetVolumeInformation to read the volume label. On other
    platforms it falls back to matching the mountpoint name containing the label.
    """
    expected = (expected_label or '').strip().casefold()
    for p in psutil.disk_partitions(all=False):
        # Try Windows-specific volume label check first
        try:
            if os.name == 'nt':
                vol = _get_volume_label_windows(p.mountpoint)
                if vol and vol.strip().casefold() == expected and expected != '':
                    return p.mountpoint
            else:
                # On Unix-like systems, check last path component (e.g., /media/USER/UFA_CODE)
                tail = os.path.basename(os.path.normpath(p.mountpoint)).casefold()
                if expected != '' and expected == tail:
                    return p.mountpoint
        except Exception:
            continue
    return None

# -------------------- GUI IMPLEMENTATION --------------------
class FACodeGUI:
    def __init__(self, root):
        self.root = root
        self.root.title("Two-Factor Authentication Code Generator")
        self.root.geometry("500x420")
        self.root.resizable(False, False)

        ttk.Label(root, text="W.A.V.E. AUTHENTICATION CODE GENERATOR", font=("Times New Roman", 16, "bold")).pack(pady=10)


        # Passphrase
        ttk.Label(root, text="Master Passphrase:").pack()
        self.pass_entry = ttk.Entry(root, show="*", width=40)
        self.pass_entry.pack(pady=5)

        # Expiration
        ttk.Label(root, text="Expiration Duration:").pack(pady=5)
        self.duration_var = tk.StringVar(value="Weekly")
        durations = ["Weekly", "Monthly", "Annually", "1 Hour (Test)"]

        self.duration_menu = ttk.OptionMenu(root, self.duration_var, durations[0], *durations)
        self.duration_menu.pack()

        # Status box
        ttk.Label(root, text="Log Output:").pack(pady=5)
        self.output = tk.Text(root, height=10, width=60)
        self.output.pack()

        ttk.Button(root, text="Generate Code", command=self.generate).pack(pady=15)

    def log(self, text):
        self.output.insert(tk.END, text + "\n")
        self.output.see(tk.END)

    def get_duration_seconds(self):
        choice = self.duration_var.get()
        if choice == "Weekly":
            return TIME_WEEK
        if choice == "Monthly":
            return TIME_MONTH
        if choice == "Annually":
            return TIME_YEAR
        return TIME_HOUR

    def generate(self):
        passwd = self.pass_entry.get().strip()
        if not passwd:
            messagebox.showerror("Error", "Master passphrase cannot be empty.")
            return

        # SECURITY CHECK: Verify USB drive is present BEFORE generating any code
        usb = find_usb_drive(USB_LABEL)
        if not usb:
            self.log("ERROR: USB drive 'UFA_CODE' not detected. Code generation aborted.")
            messagebox.showerror("USB Required", 
                f"USB drive labeled '{USB_LABEL}' must be inserted before generating a code.\n\n"
                "Please insert the authorized USB drive and try again.")
            return

        PASSWORD_BYTES = passwd.encode()

        # 1. Generate code
        code, ts = generate_factor_code()
        duration_sec = self.get_duration_seconds()
        valid_until = ts + duration_sec

        code_data = {
            "code": code,
            "timestamp": ts,
            "valid_until": valid_until,
            "duration_label": self.duration_var.get()
        }

        self.log(f"Generated Code: {code}")
        self.log(f"Valid Until: {time.ctime(valid_until)}")

        # 2. Insert into DB
        ok, msg = insert_code_to_db(code, valid_until)
        if ok:
            self.log("DB: " + msg)
        else:
            self.log("DB ERROR: " + msg)

        # 3. Encrypt
        salt = b'\x12\x34\x56\x78\x90\xab\xcd\xef\xfe\xdc\xba\x98\x76\x54\x32\x10'
        key, iv, _ = generate_key_and_iv(PASSWORD_BYTES, salt)

        encrypted = aes_encrypt(json.dumps(code_data).encode(), key, iv)
        file_data = salt + iv + encrypted

        # Write encrypted file directly to USB (USB already verified at start of generate())
        dest = os.path.join(usb, ENCRYPTED_FILENAME)
        try:
            # write directly to the USB destination
            with open(dest, "wb") as f:
                f.write(file_data)
            self.log(f"Copied to USB: {dest}")
        except Exception as e:
            self.log(f"USB Write Error: {e}")
            messagebox.showerror("Write Error", f"Failed to write file to USB: {e}")
            return

        messagebox.showinfo("Success", f"Authentication Code Generated and saved to USB:\n{dest}")

# -------------------- RUN GUI --------------------
if __name__ == "__main__":
    root = tk.Tk()
    FACodeGUI(root)
    root.mainloop()