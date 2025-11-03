import threading
from flask import Flask, request, jsonify, render_template
from flask_cors import CORS

app = Flask(__name__, template_folder="dashboard")
# Allow requests from any origin (you can restrict this later if you want)
CORS(app)

# Optional: endpoint to push incoming RPi payloads to a Node realtime server
import os
NODE_PUSH_ENDPOINT = os.environ.get('NODE_PUSH_ENDPOINT', 'http://192.168.0.2:3000/rpi/push')

# Store last message (what the RPi sends)
# Expected structure:
# {"from": "rpi", "message": {"WQI": ..., "PH": ..., "TURB": ..., "TEMP": ..., "AMMO": ..., "DO": ..., "last_updated": "..."}}
"""
Store two slots separately:
 - last_message['rpi']  : the latest sensor dict posted by the RPi
 - last_message['pc']   : the last PC-originated string message (controls)
This prevents control strings from overwriting the sensor dict.
"""
last_message = {"rpi": {"WQI": 0, "PH": 0, "TURB": 0, "TEMP": 0, "AMMO": 0, "DO": 0}, "pc": ""}

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
    # store PC-originated string separately so it doesn't clobber sensor dict
    last_message['pc'] = msg
    print(f"[PC → RPi] {msg}")
    return jsonify({"status": "ok"})

# RPi posts the latest sensor readings here
@app.route("/send", methods=["POST"])
def receive_message():
    global last_message
    # Accept JSON payloads or plain text payloads from RPi terminals
    import json
    msg = {}
    # Try JSON first (no exceptions thrown on bad JSON)
    data = request.get_json(silent=True)
    if isinstance(data, dict) and 'message' in data:
        msg = data.get('message') or {}
    elif isinstance(data, dict) and data:
        # If RPi posted a dict directly, treat it as the message
        msg = data
    else:
        # Try raw text body (could be JSON string or key:value pairs)
        raw = request.get_data(as_text=True).strip()
        if raw:
            # Try parse raw as JSON
            try:
                parsed = json.loads(raw)
                if isinstance(parsed, dict):
                    msg = parsed
                else:
                    # not a dict -> wrap
                    msg = {"message": parsed}
            except Exception:
                # Try simple key:value; pairs separated by ; , or newline
                try:
                    parts = [p.strip() for p in raw.replace('\n', ';').split(';') if p.strip()]
                    kv = {}
                    for part in parts:
                        if ':' in part:
                            k, v = part.split(':', 1)
                            kv[k.strip()] = v.strip()
                        elif '=' in part:
                            k, v = part.split('=', 1)
                            kv[k.strip()] = v.strip()
                    if kv:
                        msg = kv
                    else:
                        # fallback: store raw under 'raw'
                        msg = {"raw": raw}
                except Exception:
                    msg = {"raw": raw}

    # update only the rpi slot with the latest sensor dict
    if isinstance(msg, dict):
        # attach a UTC timestamp for frontend watchdog/last-updated display
        try:
            from datetime import datetime
            msg['last_updated'] = datetime.utcnow().isoformat() + 'Z'
        except Exception:
            pass
        last_message['rpi'] = msg
    else:
        try:
            from datetime import datetime
            last_message['rpi'] = {"message": msg, 'last_updated': datetime.utcnow().isoformat() + 'Z'}
        except Exception:
            last_message['rpi'] = {"message": msg}

    print("[RPi → PC] Received sensor payload:")
    if isinstance(last_message['rpi'], dict):
        for k, v in last_message['rpi'].items():
            print(f"  - {k}: {v}")
    else:
        print(f"  - message: {last_message['rpi']}")

    # Best-effort: forward this payload to a Node realtime HTTP endpoint so the Node server can immediately
    # emit to connected socket.io clients without waiting for a poll. Fail silently on errors.
    try:
        if NODE_PUSH_ENDPOINT:
            try:
                import requests, json
                headers = {'Content-Type': 'application/json'}
                payload = {'from': 'rpi', 'message': last_message.get('rpi', {}), 'ts': last_message.get('rpi', {}).get('last_updated')}
                requests.post(NODE_PUSH_ENDPOINT, data=json.dumps(payload), headers=headers, timeout=0.8)
            except Exception:
                pass
    except Exception:
        pass

    # Return the normalized shape expected by the PHP frontend
    return jsonify({"status": "ok", "received": last_message, "from": "rpi", "message": last_message.get('rpi', {})})

# PHP dashboard fetches here
@app.route("/get", methods=["GET"])
def get_message():
    # Returns both rpi sensors and pc last message
    # Return the normalized shape expected by controller.php and fetch_sensors.php
    # { "from": "rpi", "message": {...sensor dict...}, "pc": "..." }
    response = {
        "from": "rpi",
        "message": last_message.get('rpi', {}),
        "pc": last_message.get('pc', "")
    }
    return jsonify(response)

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
                # keep pc slot separate and avoid clobbering rpi dict
                last_message['pc'] = msg
                print(f"[PC → RPi] {msg}")
    except Exception:
        pass

if __name__ == "__main__":
    print("Server running at http://192.168.0.2:5000")

    # Reduce werkzeug logs (optional)
    import logging
    log = logging.getLogger('werkzeug')
    log.setLevel(logging.ERROR)

    threading.Thread(target=console_sender, daemon=True).start()
    app.run(host="0.0.0.0", port=5000, debug=False)
