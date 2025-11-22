#!/usr/bin/env python3
"""
USV WiFi Connectivity Diagnostic Suite (Cross-Platform)
--------------------------------------
Features:
 - ICMP connectivity test (latency, loss)
 - ARP reachability
 - WiFi RSSI & link quality (Uses iwconfig on Linux, netsh on Windows)
 - Link Quality Scoring System
 - JSON report export
 - CSV report export
 - Presentable Console Output
"""

from scapy.all import sr1, srp, sr, Ether, ARP, IP, ICMP
import subprocess
import json
import statistics
import time
import platform # Required for cross-platform detection
import pandas as pd # Required for CSV export
import re           # Required for parsing raw output and alignment

# ------------------------------
# CONFIG
# ------------------------------
USV_INTERFACE = "wlan0"
TARGET_IP     = "192.168.0.3"
COUNT         = 5
TIMEOUT       = 2
REPORT_FILE   = "usv_network_report.json"
CSV_FILE      = "usv_network_report.csv"


# ------------------------------
# ICMP Connectivity
# ------------------------------
def icmp_test(target, count=5, timeout=2):
    print(f"\n[+] ICMP Test → {target}")
    rtts = []
    received = 0

    for _ in range(count):
        # Note: scapy will use the appropriate transport layer for the OS
        pkt = IP(dst=target)/ICMP()
        start = time.time()
        # sr1 sends a packet and receives the first reply
        reply = sr1(pkt, timeout=timeout, verbose=0) 
        end = time.time()

        if reply:
            rtt = (end - start) * 1000
            rtts.append(rtt)
            received += 1
            print(f"  Reply from {reply.src}   RTT={rtt:.2f} ms")
        else:
            print("  Timeout.")

        time.sleep(0.25)

    loss = ((count - received) / count) * 100
    avg_rtt = statistics.mean(rtts) if rtts else None

    return {
        "sent": count,
        "received": received,
        "loss_percent": loss,
        "avg_rtt_ms": avg_rtt,
        "individual_rtt_ms": rtts
    }


# ------------------------------
# ARP Reachability
# ------------------------------
def arp_check(target_ip, iface):
    print(f"\n[+] ARP Reachability Check on {iface}")
    # Use srp with an Ether broadcast for ARP requests (Layer 2)
    try:
        pkt = Ether(dst="ff:ff:ff:ff:ff:ff") / ARP(pdst=target_ip)
        ans, _ = srp(pkt, iface=iface, timeout=2, verbose=0)
        if ans and len(ans) > 0:
            # ans is a list of (sent, received) pairs — take first reply
            mac = ans[0][1].hwsrc
            print(f"  ARP Reply → MAC: {mac}")
            return {"reachable": True, "mac": mac}
        return {"reachable": False, "mac": None}
    except Exception as e:
        print(f"  [!] ARP check failed: {e}")
        return {"reachable": False, "mac": None}


# ------------------------------
# WiFi RSSI & Link Quality (Cross-Platform)
# ------------------------------
def get_wifi_metrics(interface):
    print(f"\n[+] Reading WiFi metrics from OS CLI")
    
    system = platform.system()
    out = ""
    signal = None # RSSI in dBm
    lq = None
    lq_max = None
    wifi_details = {} # To store all parsed netsh/iwconfig details

    if system == "Windows":
        cmd = "netsh wlan show interfaces"
        shell = True
        print("  Running: netsh wlan show interfaces (Windows)")
    else: # Assumes Linux/other Unix-like systems (like the USV)
        cmd = ["iwconfig", interface]
        shell = False
        print(f"  Running: iwconfig {interface} (Linux)")

    try:
        out = subprocess.check_output(cmd, stderr=subprocess.STDOUT, shell=shell).decode('utf-8')
    except Exception as e:
        print(f"  [!] Cannot read WiFi metrics: {e}")
        return {
            "signal_dbm": None,
            "link_quality": None,
            "link_quality_max": None,
            "raw": f"ERROR: {e}",
            "details": {}
        }
    
    # --- Parsing Logic ---
    if system == "Windows":
        # Parsing netsh output for key metrics
        for line in out.split('\n'):
            if ":" in line and '---' not in line: # Avoid separator lines
                try:
                    key, val = line.split(":", 1)
                    key = key.strip()
                    val = val.strip()
                    wifi_details[key] = val
                    
                    if "Signal" in key and "%" in val:
                        signal_percent = int(val.replace('%', '').strip())
                        # Approximate conversion from Windows % to dBm for score calculation
                        signal = int((signal_percent / 2) - 100)
                        
                except ValueError:
                    continue 

        # Windows netsh doesn't provide Link Quality ratio. Assume perfect if signal exists.
        if signal is not None:
             lq = 70
             lq_max = 70
        
        print(f"  Signal: {wifi_details.get('Signal', 'N/A')} (approx. {signal} dBm)")
        print(f"  Link Quality: {lq}/{lq_max} (Windows approximation)")

    else: # Linux Parsing (Original iwconfig logic)
        for line in out.split("\n"):
            if "Signal level" in line:
                try:
                    signal = int(line.split("Signal level=")[1].split(" dBm")[0])
                    wifi_details['Rssi'] = signal 
                except:
                    pass
            if "Link Quality" in line:
                try:
                    lq_raw = line.split("Link Quality=")[1].split(" ")[0]
                    lq, lq_max = map(int, lq_raw.split("/"))
                except:
                    pass
            
            # Store all key details (less structured in iwconfig)
            if ":" in line:
                try:
                    key, val = line.split(":", 1)
                    wifi_details[key.strip()] = val.strip()
                except:
                    pass
            
        print(f"  Signal: {signal} dBm")
        print(f"  Link Quality: {lq}/{lq_max}")
        
    
    final_metrics = {
        # Critical values for scoring
        "signal_dbm": signal,
        "link_quality": lq,
        "link_quality_max": lq_max,
        
        # Raw data for detailed report
        "raw": out, 
        "details": wifi_details # Parsed data for structured output
    }
    
    return final_metrics


# ------------------------------
# Link Quality Scoring System
# ------------------------------
def compute_link_score(rssi_dbm, link_quality, link_quality_max, loss_percent, avg_rtt):
    score = 100

    # RSSI scoring (Penalty)
    if rssi_dbm:
        if rssi_dbm > -50: score -= 0
        elif rssi_dbm > -60: score -= 5
        elif rssi_dbm > -70: score -= 15
        elif rssi_dbm > -80: score -= 30
        else: score -= 50

    # Link quality ratio (Penalty)
    if link_quality and link_quality_max:
        ratio = link_quality / link_quality_max
        score -= int((1 - ratio) * 30)

    # Packet loss penalty
    score -= int(loss_percent * 0.7)

    # Latency penalty
    if avg_rtt:
        if avg_rtt > 200: score -= 20
        elif avg_rtt > 100: score -= 10

    score = max(0, min(100, score))
    return score


# ------------------------------
# JSON Report Generator
# ------------------------------
def generate_report(icmp, arp, wifi, link_score, output_file):
    report = {
        "timestamp": time.time(),
        "target": TARGET_IP,
        "interface": USV_INTERFACE,
        "icmp_test": icmp,
        "arp_reachability": arp,
        "wifi_metrics": wifi,
        "link_quality_score": link_score
    }
    
    # Inline the 'details' into the main wifi_metrics for a flatter JSON
    if 'details' in report["wifi_metrics"]:
        report["wifi_metrics"].update(report["wifi_metrics"].pop('details'))
    
    # Clean up the raw string before writing JSON
    if 'raw' in report["wifi_metrics"]:
        report["wifi_metrics"]["raw"] = report["wifi_metrics"]["raw"].replace('\r\n', '\n').strip()
    

    with open(output_file, "w") as f:
        json.dump(report, f, indent=2)

    print(f"\n[+] JSON dashboard report saved → {output_file}")
    return report

# ------------------------------
# CSV Report Generator
# ------------------------------
def save_report_as_csv(report, output_file):
    """Flattens the report dictionary and saves it as a CSV file."""
    flat_data = []

    # 1. ARP Data
    for key, value in report["arp_reachability"].items():
        flat_data.append({"Metric": f"ARP_{key}", "Value": value})

    # 2. ICMP Data
    for key, value in report["icmp_test"].items():
        if key == "individual_rtt_ms":
            flat_data.append({"Metric": "ICMP_individual_rtt_ms", "Value": str(value)})
        else:
            flat_data.append({"Metric": f"ICMP_{key}", "Value": value})

    # 3. WiFi Data (Flattening keys)
    def flatten_dict(d, flat_data_list, parent_key=''):
        for k, v in d.items():
            new_key = parent_key + k
            if isinstance(v, dict):
                flatten_dict(v, flat_data_list, new_key + '_')
            elif k != "raw":
                 flat_data_list.append({"Metric": f"WiFi_{new_key}", "Value": v})
            elif k == "raw":
                # Store raw output, replacing internal newlines with a delimiter for single-cell storage
                flat_data_list.append({"Metric": "WiFi_raw_output", "Value": str(v).replace('\r\n', ' | ').replace('\n', ' | ')})

    # Prepare a copy of wifi_metrics for flattening
    wifi_metrics_copy = report["wifi_metrics"].copy()
    if 'details' in wifi_metrics_copy:
        wifi_metrics_copy.update(wifi_metrics_copy.pop('details'))
    
    flatten_dict(wifi_metrics_copy, flat_data)


    # 4. Overall Score
    flat_data.append({"Metric": "OVERALL_Link_Quality_Score", "Value": report["link_quality_score"]})
    
    # 5. Config/Metadata
    flat_data.append({"Metric": "META_Timestamp", "Value": report["timestamp"]})
    flat_data.append({"Metric": "META_Target_IP", "Value": report["target"]})
    flat_data.append({"Metric": "META_Interface", "Value": report["interface"]})


    df = pd.DataFrame(flat_data)
    df.to_csv(output_file, index=False)
    
    print(f"[+] CSV dashboard report saved → {output_file}")
    return df

# ------------------------------
# PRESENTABLE PRINTER
# ------------------------------
def print_presentable_output(report):
    """Prints the diagnostic report in the user's requested format."""
    print("\n=== Structured Diagnostic Output ===\n")

    # ARP
    print("ARP:")
    print(f"  reachable: {report['arp_reachability']['reachable']}")
    print(f"  mac: {report['arp_reachability']['mac']}")

    # ICMP
    print("\nICMP:")
    print(f"  sent: {report['icmp_test']['sent']}")
    print(f"  received: {report['icmp_test']['received']}")
    print(f"  loss_percent: {report['icmp_test']['loss_percent']}")
    print(f"  avg_rtt_ms: {report['icmp_test']['avg_rtt_ms']}")
    
    # WiFi Metrics
    print("\nWiFi metrics:")
    
    wifi_data = report['wifi_metrics']
    # Some code paths inline 'details' into wifi_metrics (flattened). Support both shapes.
    details = wifi_data.get('details', {}) if isinstance(wifi_data.get('details', {}), dict) else {}
    if not details:
        # fallback: take all non-meta keys from wifi_data as details
        details = { k:v for k,v in wifi_data.items() if k not in ('signal_dbm','link_quality','link_quality_max','raw') }
    
    system = platform.system()
    
    # Determine which keys to prioritize based on OS and print the signal percentage/placeholder
    if system == "Windows":
        # Windows reports 'Signal' as a percentage
        print(f"  signal_percent: {details.get('Signal', 'N/A')}")
        # Fields for Windows netsh output
        field_map = {
            'Name': 'Name', 'Description': 'Description', 'GUID': 'GUID', 'Physical address': 'Physical address',
            'Interface type': 'Type', 'State': 'State', 'SSID': 'SSID', 'AP BSSID': 'BSSID',
            'Band': 'Radio type', 'Channel': 'Channel', 'Network type': 'Network type', 'Authentication': 'Authentication',
            'Connection mode': 'Connection mode', 'Profile': 'Profile'
        }
    else:
        # Linux does not easily report a percentage, only Rssi in dBm
        print(f"  signal_percent: N/A (Linux Rssi is dBm)")
        # Fields for Linux iwconfig output
        field_map = {
            'Name': 'Name', 'Description': 'Description', 'GUID': 'GUID', 'Physical address': 'Physical address',
            'Interface type': 'Interface type', 'State': 'State', 'SSID': 'SSID', 'AP BSSID': 'AP BSSID',
            'Band': 'Band', 'Channel': 'Channel', 'Network type': 'Network type', 'Radio type': 'Radio type',
            'Authentication': 'Authentication', 'Connection mode': 'Connection mode', 'Receive rate (Mbps)': 'Receive rate (Mbps)',
            'Transmit rate (Mbps)': 'Transmit rate (Mbps)', 'Signal': 'Signal', 'Profile': 'Profile',
            'QoS MSCS Configured': 'QoS MSCS Configured', 'QoS Map Configured': 'QoS Map Configured', 
            'QoS Map Allowed by Policy': 'QoS Map Allowed by Policy'
        }

    # Print all detailed fields
    for requested_key, raw_key in field_map.items():
        val = details.get(raw_key, 'N/A')
        if val == "": val = "N/A"
        
        print(f"  {requested_key:24} : {val}")


    # Rssi (Use the standardized signal_dbm for the scoring)
    print(f"  Rssi                     : {wifi_data.get('signal_dbm', 'N/A')}")
    
    # Overall Score
    print("\nOverall Link Quality Score:")
    print(f"  Score: {report['link_quality_score']}/100")


# ------------------------------
# MAIN
# ------------------------------
if __name__ == "__main__":
    print("\n=== USV–Server Network Diagnostic Suite ===")

    arp_result = arp_check(TARGET_IP, USV_INTERFACE)
    icmp_result = icmp_test(TARGET_IP, COUNT, TIMEOUT)
    
    wifi_result = get_wifi_metrics(USV_INTERFACE)

    link_score = compute_link_score(
        wifi_result["signal_dbm"],
        wifi_result["link_quality"],
        wifi_result["link_quality_max"],
        icmp_result["loss_percent"],
        icmp_result["avg_rtt_ms"]
    )

    print(f"\n[+] Overall Link Quality Score: {link_score}/100")

    # 1. Generate JSON report
    report = generate_report(icmp_result, arp_result, wifi_result, link_score, REPORT_FILE)
    
    # 2. Print presentable output (The Arranged Output)
    print_presentable_output(report)
    
    # 3. Generate CSV report
    save_report_as_csv(report, CSV_FILE)

    print("\n=== Diagnostic Complete ===\n")