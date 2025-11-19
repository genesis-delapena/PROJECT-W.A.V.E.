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
USB_LABEL = "UFA_Code"

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

def find_usb_drive(label):
    for p in psutil.disk_partitions(all=False):
        if 'removable' in p.opts.lower() or 'media' in p.mountpoint.lower():
            return p.mountpoint
    return None

# -------------------- GUI IMPLEMENTATION --------------------
class FACodeGUI:
    def __init__(self, root):
        self.root = root
        self.root.title("2-Factor Authentication Code Generator")
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

        with open(ENCRYPTED_FILENAME, "wb") as f:
            f.write(file_data)

        self.log(f"Saved encrypted file: {ENCRYPTED_FILENAME}")

        # 4. USB copy
        usb = find_usb_drive(USB_LABEL)
        if usb:
            dest = os.path.join(usb, ENCRYPTED_FILENAME)
            try:
                shutil.copyfile(ENCRYPTED_FILENAME, dest)
                self.log(f"Copied to USB: {dest}")
            except Exception as e:
                self.log(f"USB Copy Error: {e}")
        else:
            self.log("No USB detected. Skipped USB copy.")

        messagebox.showinfo("Success", "Authentication Code Generated Successfully!")

# -------------------- RUN GUI --------------------
if __name__ == "__main__":
    root = tk.Tk()
    FACodeGUI(root)
    root.mainloop()