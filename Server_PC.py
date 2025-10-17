import threading
from flask import Flask, request, jsonify, render_template
from flask_cors import CORS

app = Flask(__name__, template_folder="dashboard")
# Allow requests from any origin (you can restrict this later if you want)
CORS(app)

# Store last message (what the RPi sends)
# Expected structure:
# {"from": "rpi", "message": {"WQI": ..., "PH": ..., "TURB": ..., "TEMP": ..., "AMMO": ..., "DO": ..., "last_updated": "..."}}
last_message = {"from": "server", "message": {"WQI": 0, "PH": 0, "TURB": 0, "TEMP": 0, "AMMO": 0, "DO": 0}}

@app.route("/")
def index():
    # Optional: if you keep a Flask-served test page (sensors.html) in the "dashboard" folder
    return render_template("sensors.html")

# PC-originated message (optional utility endpoint)
@app.route("/send_from_pc", methods=["POST"])
def send_from_pc():
    global last_message
    data = request.get_json(force=True) or {}
    msg = data.get("message", "")
    last_message = {"from": "pc", "message": msg}
    print(f"[PC → RPi] {msg}")
    return jsonify({"status": "ok"})

# RPi posts the latest sensor readings here
@app.route("/send", methods=["POST"])
def receive_message():
    global last_message
    data = request.get_json(force=True) or {}
    msg = data.get("message", {})

    # keep structure stable: {"from":"rpi","message":{...sensor dict...}}
    last_message = {"from": "rpi", "message": msg}
    print("[RPi → PC] Received sensor payload:")
    if isinstance(msg, dict):
        for k, v in msg.items():
            print(f"  - {k}: {v}")
    else:
        print(f"  - message: {msg}")
    return jsonify({"status": "ok", "received": last_message})

# PHP dashboard fetches here
@app.route("/get", methods=["GET"])
def get_message():
    # Returns the whole wrapper with "from" and "message"
    return jsonify(last_message)

def console_sender():
    """Optional console thread to send manual messages from PC."""
    global last_message
    try:
        while True:
            msg = input("PC> ").strip()
            if msg.lower() == "exit":
                print("Shutting down console sender...")
                break
            if msg:
                last_message = {"from": "pc", "message": msg}
                print(f"[PC → RPi] {msg}")
    except Exception:
        pass

if __name__ == "__main__":
    print("Server running at http://192.168.1.3:5000")

    # Reduce werkzeug logs (optional)
    import logging
    log = logging.getLogger('werkzeug')
    log.setLevel(logging.ERROR)

    threading.Thread(target=console_sender, daemon=True).start()
    app.run(host="0.0.0.0", port=5000, debug=False)
