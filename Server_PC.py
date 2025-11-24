import threading
import requests
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
 - command_queue        : queue of commands to be sent to RPI (prevents command loss)
 - persistent_alert     : stores ALERT/WATCHDOG state that persists across sensor updates
This prevents control strings from overwriting the sensor dict.
"""
last_message = {"rpi": {"WQI": 0, "PH": 0, "TURB": 0, "TEMP": 0, "AMMO": 0, "DO": 0}, "pc": ""}
command_queue = []  # Queue to store multiple commands for RPI to process
persistent_alert = {}  # Stores ALERT, UNIT_ID, WATCHDOG status that persists until cleared

@app.route("/")
def index():
    # Optional: if you keep a Flask-served test page (sensors.html) in the "dashboard" folder
    return render_template("sensors.html")

# PC-originated message (optional utility endpoint)
@app.route("/send_from_pc", methods=["POST"])
def send_from_pc():
    global last_message, command_queue
    data = request.get_json(force=True) or {}
    msg = data.get("message", "")
    
    if msg:
        # Store in both places: last_message['pc'] for backward compatibility and queue for reliability
        last_message['pc'] = msg
        command_queue.append(msg)
        print(f"[PC → RPi] ✓ Command queued: '{msg}' (Queue size: {len(command_queue)})")
        print(f"[PC → RPi] Current queue: {command_queue}")
    
    return jsonify({"status": "ok", "command": msg, "queue_size": len(command_queue)})

# RPi posts the latest sensor readings here
@app.route("/send", methods=["POST"])
def receive_message():
    global last_message, persistent_alert
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

    # Handle persistent alert fields (ALERT, WATCHDOG, INFO)
    if isinstance(msg, dict):
        # Check for ALERT message - store persistently
        if 'ALERT' in msg or 'alert' in msg:
            alert_val = msg.get('ALERT') or msg.get('alert')
            persistent_alert['ALERT'] = alert_val
            if 'UNIT_ID' in msg:
                persistent_alert['UNIT_ID'] = msg.get('UNIT_ID')
            print(f"[WATCHDOG] ⚠️ ALERT STORED: {alert_val}")
            
            # Log to notification system in admin dashboard
            try:
                log_data = {
                    'log_to_event_log': '1',
                    'user': 'SYSTEM',
                    'desc': 'Watchdog Triggered',
                    'status': 'ALARM'
                }
                response = requests.post('http://localhost/wave_project/ad_dashboard.php', data=log_data, timeout=5)
                print(f"[WATCHDOG] ✓ Notification POST response: {response.status_code}")
                if response.status_code == 200:
                    print(f"[WATCHDOG] ✓✓ Successfully logged to notification system")
                    print(f"[WATCHDOG] Response: {response.text[:200]}")
                else:
                    print(f"[WATCHDOG] ⚠️ Unexpected status code: {response.status_code}")
            except Exception as e:
                print(f"[WATCHDOG] ⚠️ Failed to log notification: {e}")
                import traceback
                traceback.print_exc()
        
        # Check for WATCHDOG: OK or INFO: Reset - clear persistent alert
        watchdog_val = msg.get('WATCHDOG') or msg.get('watchdog')
        info_val = msg.get('INFO') or msg.get('info')
        status_val = msg.get('STATUS') or msg.get('status')
        
        if watchdog_val and str(watchdog_val).upper() == 'OK':
            print(f"[WATCHDOG] ✓ RESET DETECTED - Clearing persistent alert")
            persistent_alert.clear()
        elif info_val and any(word in str(info_val).upper() for word in ['RESET', 'CLEAR', 'RESTORE']):
            print(f"[WATCHDOG] ✓ RESET DETECTED - Clearing persistent alert")
            persistent_alert.clear()
        elif status_val and any(word in str(status_val).upper() for word in ['SECURE', 'OK']):
            if msg.get('UNIT_ID') and 'WATCHDOG' in str(msg.get('UNIT_ID')).upper():
                print(f"[WATCHDOG] ✓ RESET DETECTED - Clearing persistent alert")
                persistent_alert.clear()

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

    # Only print sensor payload if it contains important fields (not just heading updates)
    if isinstance(last_message['rpi'], dict):
        important_keys = ['ALERT', 'alert', 'WATCHDOG', 'watchdog', 'WQI', 'PH', 'DO', 'TURB', 'NH3_PPM']
        has_important = any(k in last_message['rpi'] for k in important_keys)
        if has_important:
            print("[RPi → PC] Received sensor payload:")
            for k, v in last_message['rpi'].items():
                print(f"  - {k}: {v}")

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
    global command_queue, persistent_alert
    # Returns both rpi sensors and pc last message
    # Return the normalized shape expected by controller.php and fetch_sensors.php
    # { "from": "rpi", "message": {...sensor dict...}, "pc": "...", "commands": [...] }
    
    # Pop the oldest command from queue if available (FIFO)
    next_command = ""
    if command_queue:
        next_command = command_queue.pop(0)
        print(f"[PC → RPi] ✓ Command retrieved: '{next_command}' (Remaining: {len(command_queue)})")
    
    # Merge persistent alert data with current sensor data
    merged_message = last_message.get('rpi', {}).copy()
    if persistent_alert:
        merged_message.update(persistent_alert)
    
    response = {
        "from": "rpi",
        "message": merged_message,
        "pc": next_command if next_command else last_message.get('pc', ""),
        "commands": command_queue.copy()  # Include remaining queue for debugging
    }
    return jsonify(response)

def console_sender():
    """Optional console thread to send manual messages from PC."""
    global last_message, command_queue
    try:
        while True:
            msg = input("PC> ").strip()
            if msg.lower() == "exit":
                print("Shutting down console sender...")
                break
            if msg:
                # Add to queue just like web commands
                last_message['pc'] = msg
                command_queue.append(msg)
                print(f"[PC → RPi] ✓ Command queued: '{msg}' (Queue size: {len(command_queue)})")
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
