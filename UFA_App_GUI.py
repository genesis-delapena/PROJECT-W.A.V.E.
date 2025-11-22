import os
import json
import time
import psutil
import random 
import string 
import getpass 
import tkinter as tk
from tkinter import messagebox, ttk
import ctypes

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC

# --- Configuration (MUST match FA_Code_Generator.py) ---
ENCRYPTED_FILENAME = "encrypted_ufa_code.bin"
USB_LABEL = "UFA_Code"  # Label of the USB drive


# ------------------------------------------------------------------
# 🔑 Original Functions (NO MODIFICATIONS)
# ------------------------------------------------------------------

def generate_key_from_salt(password_bytes, salt):
    kdf = PBKDF2HMAC(
        algorithm=hashes.SHA256(),
        length=32,
        salt=salt,
        iterations=100000,
        backend=default_backend()
    )
    return kdf.derive(password_bytes)

def aes_decrypt(ciphertext, key, iv):
    cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
    decryptor = cipher.decryptor()
    
    decrypted_padded_data = decryptor.update(ciphertext) + decryptor.finalize()
    return decrypted_padded_data.rstrip()

def find_usb_drive(label):
    for partition in psutil.disk_partitions(all=False):
        if 'removable' in partition.opts.lower() or 'media' in partition.mountpoint.lower():
            return partition.mountpoint
    return None


# ------------------------------------------------------------------
# 🎨 GUI APPLICATION
# ------------------------------------------------------------------

class UFA_GUI:
    def __init__(self, root):
        self.root = root
        self.root.title("UFA Authorization App")
        self.root.geometry("500x430")
        self.root.resizable(False, False)

        title = tk.Label(root, text="W.A.V.E. UFA Authorization Checker", 
                         font=("Times New Roman", 18, "bold"),)
        title.pack(pady=15)

        # Frame for password
        frame = tk.Frame(root)
        frame.pack(pady=10)

        tk.Label(frame, text="Master Passphrase:", 
                 font=("Times New Roman", 12),).grid(row=0, column=0, sticky="w")

        self.pass_entry = tk.Entry(frame, width=30, show="•", font=("Arial", 12))
        self.pass_entry.grid(row=1, column=0, padx=5, pady=5)

        # Validate Button
        self.validate_btn = tk.Button(
            root,
            text="Validate Code",
            font=("Arial", 13, "bold"),
            bg="#007acc",
            fg="white",
            width=20,
            command=self.validate_code
        )
        self.validate_btn.pack(pady=20)

        # Output Box
        self.output_box = tk.Text(root, width=58, height=10, font=("Courier", 10))
        self.output_box.pack(pady=10)
        self.output_box.config(state=tk.DISABLED)


    # ------------------------------------------------------------------
    # 🧠 GUI Logic Wrapping the Existing Flow
    # ------------------------------------------------------------------
    def validate_code(self):
        self.output_box.config(state=tk.NORMAL)
        self.output_box.delete("1.0", tk.END)

        passphrase = self.pass_entry.get().strip()
        if not passphrase:
            messagebox.showwarning("Missing Input", "Please enter the master passphrase.")
            return

        password_bytes = passphrase.encode("utf-8")

        # Step 1: detect USB drive
        usb_path = find_usb_drive(USB_LABEL)
        if not usb_path:
            messagebox.showerror("USB Not Found", 
                                 "UFA_Code USB stick not detected.\nInsert the USB and try again.")
            return

        file_path = os.path.join(usb_path, ENCRYPTED_FILENAME)
        if not os.path.exists(file_path):
            messagebox.showerror("File Not Found", 
                                 f"Encrypted file '{ENCRYPTED_FILENAME}' not found on USB.")
            return

        try:
            # Read encrypted data
            with open(file_path, "rb") as f:
                file_content = f.read()

            salt = file_content[:16]
            iv = file_content[16:32]
            encrypted_data = file_content[32:]

            key = generate_key_from_salt(password_bytes, salt)

            plaintext_bytes = aes_decrypt(encrypted_data, key, iv)
            code_data = json.loads(plaintext_bytes.decode("utf-8"))

            auth_code = code_data.get("code")
            valid_until = code_data.get("valid_until", 0)
            duration = code_data.get("duration_label", "Unknown")

            if valid_until and time.time() > valid_until:
                messagebox.showerror("Expired Code", 
                                     f"The Authorization Code ({duration}) has EXPIRED. Kindly request a new one from the administrators")
                return

            # Build output text
            result_text = (
                f"FACTOR AUTHORIZATION CODE FOUND!\n"
                f"Code: {auth_code}\n"
                f"Duration: {duration}\n"
                f"Valid Until: {time.ctime(valid_until)}\n"
                f"\nCode validated successfully!"
            )

            # Show results in the main output box only
            self.display_output(result_text)

        except Exception as e:
            messagebox.showerror("Decryption Error", f"Error: {e}")

    def display_output(self, text):
        self.output_box.config(state=tk.NORMAL)
        self.output_box.insert(tk.END, text)
        self.output_box.config(state=tk.DISABLED)

    # (Previously a Toplevel result window was shown here. The UI now displays
    # validation output in the main window's text box only.)


# ------------------------------------------------------------------
# Run GUI App
# ------------------------------------------------------------------

if __name__ == "__main__":
    # Try to hide the Python console window on Windows so only the Tk window shows.
    def hide_console_window():
        if os.name == 'nt':
            try:
                kernel32 = ctypes.WinDLL('kernel32')
                user32 = ctypes.WinDLL('user32')
                handle = kernel32.GetConsoleWindow()
                if handle:
                    SW_HIDE = 0
                    user32.ShowWindow(handle, SW_HIDE)
            except Exception:
                pass

    hide_console_window()
    root = tk.Tk()
    app = UFA_GUI(root)
    root.mainloop()
