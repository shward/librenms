# LibreNMS SNMP vs API/WinRM — Datapoint Gap Analysis

**Date:** 2026-05-30
**Author:** Josh Thomas-Ward (with Claude Code)
**Status:** Research deliverable (no LibreNMS code changes). Feeds the multi-transport roadmap (`2026-05-30-librenms-transport-architecture.md`).
**Method:** 14 parallel research agents — 6 mining LibreNMS's actual SNMP coverage from the repo (`os_discovery` YAML, modules, OS classes, `includes/`), 8 researching public vendor API/WinRM docs — then a join/validation pass cross-checking every API "gap" claim against what LibreNMS already polls via SNMP. Raw findings: `/tmp/gap/*.md`.

---

## 1. The honest headline

**LibreNMS's SNMP coverage is deep, and most "the API can do X" claims are *not* real gaps** — LibreNMS already polls X. The defensible, high-value gaps cluster in four places, and they line up exactly with your intuition:

1. **Windows** — SNMP gives you a process *count*, an aggregate CPU %, total memory, and disk *size*. Everything operational (per-process, per-service, PerfMon, event log, patch/cert state, IIS/SQL/Hyper-V counters) is **WinRM-only**. This is the single largest gap. Microsoft is right.
2. **VRF / EVPN-VXLAN overlay** — identity (name/RD) is covered; **route-targets, per-VRF route-count trends, EVPN/NVE peer + VNI state, and overlay MAC-IP/FDB are not** (no MIB on NX-OS).
3. **Firewall operational state (Palo Alto / Fortinet)** — counters are covered; the **per-flow session table, per-rule hit counts, threat/URL/WildFire logs, VPN *user* tables, dataplane per-core CPU, and license/signature/cert expiry are API-only**.
4. **Multi-VRF / VXLAN FDB enrichment** — SNMP sees per-VLAN *local* MACs; the **overlay (remote) MAC-IP bindings with VNI/VTEP/VRF correlation are API-only**.

Plus two infrastructure gaps that are nearly total: **VMware per-VM performance** (SNMP gives only VM inventory) and **cloud-managed wireless** (Meraki / Aruba Central — SNMP barely functions; the cloud API is the only real source).

Everything else (interface counters, CPU/mem/storage, BGP/OSPF/ISIS adjacency state, environmental sensors, firewall session *counts*, SD-WAN link SLA on Fortigate, VM inventory) **SNMP already collects** — we should *not* duplicate those via API.

### The three tiers (how to read the rest of this doc)
- **Tier 1 — Pure gap (SNMP has nothing):** populate these via API/WinRM; they are net-new datapoints.
- **Tier 2 — Depth gap (SNMP has the aggregate, API has the breakdown):** API adds per-entity/per-process/per-rule granularity. Populate selectively where the detail matters.
- **Tier 0 — Not a gap (SNMP already covers it):** do **not** re-collect via API. Listed per platform so we stay honest.

---

## 2. What LibreNMS already collects via SNMP (the baseline — Tier 0)

So the gaps below are credible, here is the verified baseline (repo-cited):

- **Generic (every SNMP device):** system identity/uptime (multi-source max-wins), IF-MIB interface counters (octets/ucast/nucast/errors/discards/status/HC), HOST-RESOURCES/UCD CPU+mem+storage, ENTITY-MIB physical inventory, ENTITY-SENSOR-MIB + the 30+ generic sensor classes (temp/volt/current/power/fan/dbm/humidity/state/…), availability %.
- **Routing/L3 (VRF-context-aware):** BGP peer state + admin/remoteAS/FSM-time + per-AFI/SAFI prefix counts (Cisco `cbgpPeer2*`, Junos, Arista, Nokia, Huawei, Cumulus, Dell-OS10, AOS7, Firebrick); OSPFv2/v3 instances/areas/ports/neighbors; IS-IS adjacencies; **VRF identity (name/RD/description) + `ports.ifVrf`** for Cisco/Junos/Arista/Nokia/Cumulus/NX-OS; routes (discovery-only); IPv4/IPv6 addresses; ARP (`ipv4_mac`) + ND (`ipv6_nd`); **FDB (`ports_fdb`, per-VLAN MAC→port)** via Q-BRIDGE + Cisco VTP context + per-OS overrides; IP/ICMP/TCP/UDP netstats; MPLS (Nokia only); Cisco CEF.
- **Firewalls — already substantial via SNMP:** **PAN-OS** — session counts (global + per-vsys), session utilization %, per-vsys CPS, GlobalProtect tunnel *count*, HA mode/local/peer state, Panorama connectivity, 27 DoS-protection RRDs. **Fortigate** — session count, per-core CPU, memory, IPsec phase1/2 tunnel state + up-count, SSL-VPN state + user count, **SD-WAN/link-monitor latency/jitter/loss/state**, IPS intrusions by severity, web-filter blocks, vdom HA, LTE RSSI.
- **F5 BIG-IP:** HA failover + config-sync state, client/server cur+total connections, SSL TPS, APM session count, LTM virtual-server/pool-member bytes/pkts/conns/state (numeric OIDs), SSL cert expiry.
- **VMware ESXi:** VM inventory (name/guest-OS/RAM/state/vCPU) via VMWARE-VMINFO-MIB. *(That's essentially all — no per-VM performance.)*
- **Windows:** process count, user count, aggregate CPU/mem/disk (HOST-RESOURCES), Microsoft DHCP pool utilization, server hardware model (Dell/HP/Supermicro).
- **Aruba:** controller/instant AP+client counts, per-IAP-radio channel/noise/power/util, ArubaOS-CX NAC/transceiver-DDM/PSU/VSX/VSF.

---

## 3. Per-platform gap (the join)

### 3.1 Cisco NX-OS — *the VRF/EVPN/VXLAN + FDB story* ⭐ (your hint)
**API:** NX-API (`ins-api` JSON-RPC `POST /ins`; NX-API REST/DME `GET /api/mo/<dn>.json`), NETCONF/RESTCONF, gNMI streaming. NX-OS 9.2+/10.x. Auth: local/AAA over HTTPS.

| Tier | Datapoint | API access | Why SNMP can't |
|---|---|---|---|
| 1 | **EVPN/NVE peer table** (peer IP, state, uptime, router-MAC, learn-type) | `show nve peers` | No NVE/EVPN MIB on NX-OS |
| 1 | **VNI table** (VNI, L2/L3, BD/VRF map, state, mode) + per-VNI TX/RX pkt/byte counters | `show nve vni` / `show nve vni <id> counters` | No MIB |
| 1 | **Overlay MAC-IP / FDB** (remote MACs, VNI, VTEP, seq#, associated VRF) ⭐ | `show l2route evpn mac-ip all` / `show mac address-table` / `show bgp l2vpn evpn` | `ports_fdb` (Q-BRIDGE) sees only **local** per-VLAN MACs; overlay/remote bindings have no MIB |
| 1 | **VRF route-targets (import/export RT)** | `show vrf detail` / DME `sys/inst-items/` | RD is covered; **RT has no OID anywhere** |
| 1 | **BGP per-AF EVPN/VPNv4/VPNv6/IPv6-unicast** neighbor + per-neighbor prefix counts | `show bgp l2vpn evpn summary` | BGP4-MIB = IPv4-unicast/default-VRF only |
| 1 | **vPC** domain/peer-keepalive/consistency-check results | `show vpc` / `show vpc consistency-parameters` | CISCO-VPC-MIB absent on most images |
| 1 | **TCAM region utilization** + iCAM predicted exhaustion | `show hardware capacity` / `sys/icam` | No MIB |
| 1 | **MACsec session state + encrypted byte/pkt counters** | `show macsec mka summary` / `statistics` | No MIB |
| 2 | **Per-VRF route count (trendable)** ⭐ | `show ip route summary vrf <v>` | LibreNMS reads it only as a discovery max-guard, never stored as a series |
| 2 | Per-process CPU/mem (PID-level) | `show processes cpu/memory` | hrSWRunPerf unreliable on NX-OS |
| 2 | Per-queue QoS drops / **microburst** (ns-precision buffer events) | `show queuing interface` / `show queuing burst-detect` (gNMI) | cbQoS incomplete; no burst MIB |

**Build note:** NX-API JSON fits the committed `\ApiQuery` (Family A). VRF route-counts are numeric → `poller_type='http'` sensors. EVPN/NVE/MAC-IP are structured → new tables (extends the M1 VRF pattern). This platform is the proof case for M1→M4.

### 3.2 Windows — *use WinRM* ⭐ (your hint; biggest gap)
**API:** WinRM/WS-Man (SOAP over 5985/5986), WQL/`Get-CimInstance`, `Get-Counter` (PerfMon), `Get-WinEvent`. Auth: Kerberos/NTLM/CredSSP. **Family B** (sibling transport via pywinrm helper — no viable PHP WS-Man lib).

| Tier | Datapoint | API access | Why SNMP can't |
|---|---|---|---|
| 1 | **Per-process** CPU(user/kernel)/working-set/IO/handles/owner | `Win32_Process`, `\Process(*)\% Processor Time` | hrSWRunPerf = single aggregate only |
| 1 | **Per-service** state/start-mode/run-as/dependency | `Win32_Service` | No service concept in SNMP |
| 1 | **PerfMon counter sets** — IIS (`\Web Service`), ASP.NET, **SQL Server** (buffer cache hit, PLE, locks, batch req/s), **.NET CLR** (GC, exceptions) | `Get-Counter` | No MIB for any of these |
| 1 | **Disk latency / queue depth / IOPS** (`\PhysicalDisk\Avg Disk sec/Read`, queue length) | `Get-Counter` | hrStorage = size only |
| 1 | **Patch/QFE inventory + pending-reboot** | `Win32_QuickFixEngineering` | None |
| 1 | **TLS cert expiry** (LocalMachine store) | `Cert:\LocalMachine\My` | None |
| 1 | **Hyper-V per-VM** CPU/mem/net/disk metering | `Measure-VM` / `root\virtualization\v2` | None |
| 1 | **Event log** (System/App/Security by ID/severity) | `Get-WinEvent` | None |
| 1 | **Defender/AV state, firewall profile, scheduled-task health, logged-on users, TCP conn→PID** | `root\SecurityCenter2`, `MSFT_NetFirewallProfile`, `MSFT_ScheduledTask`, `Get-NetTCPConnection` | None |
| 2 | OS build/install-date, BIOS/firmware, DIMM detail, socket/core split | `Win32_OperatingSystem/_BIOS/_PhysicalMemory/_Processor` | sysDescr string only |

**Build note:** This is M6 (Family B / pywinrm). Numeric PerfMon counters → `poller_type='wsman'` sensors; cert/patch/service → structured. Highest operational ROI of the whole effort.

### 3.3 Palo Alto PAN-OS — *firewall operational state* ⭐ (your hint)
**API:** XML API (`/api/?type=op|report|log&key=`), REST (`/restapi`). PAN-OS 10.x/11.x. Auth: API key. **Family A.**

| Tier | Datapoint | API access | Why SNMP can't |
|---|---|---|---|
| 1 | **Full session table** (5-tuple, App-ID, zone-pair, threat match, NAT, bytes, age) | `op show sessions all` | SNMP has session *count* only |
| 1 | **GlobalProtect user table** (user, device, public+tunnel IP, login time) | `op show global-protect-gateway current-user` | None (tunnel *count* via SNMP) |
| 1 | **Security-rule hit counters** (per-rule, unused-rule detection) | `op show running rule-use` | None |
| 1 | **Threat / URL / WildFire aggregates + logs** (signature, severity, verdict, file hash) | `type=report` / `type=log` | No security-event MIB |
| 1 | **Content/AV/Threat/URL/WildFire signature versions** | `op show system info` | sysDescr = SW version only |
| 1 | **License expiry per feature** | `op show license info` | None |
| 1 | **HA detail** (sync state, failover reason, heartbeat) | `op show high-availability state` | None (basic HA state *is* in SNMP) |
| 2 | **Dataplane per-core CPU** (sec/min/hour rings) | `op show running resource-monitor` | SNMP = one aggregate CPU |
| 2 | `show counter global` (hundreds of named drop/buffer counters), zone-pair traffic | `op` / `type=report` | No MIB |

*Tier 0 (already in SNMP — skip): session counts, session utilization, per-vsys CPS, HA state, Panorama connectivity, DoS counters.*

### 3.4 Fortinet FortiGate — *firewall operational state* (your hint)
**API:** FortiOS REST (`/api/v2/monitor/`, `/api/v2/cmdb/`, `?vdom=`). FortiOS 7.x. Auth: API token. **Family A.**

| Tier | Datapoint | API access | Why SNMP can't |
|---|---|---|---|
| 1 | **Full session table** (5-tuple, app, policy, UTM verdict, NAT, shaper) | `GET firewall/session` | None |
| 1 | **Per-policy hit counts + bytes/pkts/active-sessions** | `GET firewall/policy` | None |
| 1 | **Per-VDOM CPU/mem/session** (true per-tenant) | `GET system/vdom-resource` | SNMP per-vdom is a single integer |
| 1 | **AV / AppCtl / IPS-anomaly stats; FortiGuard signature versions + last-update** | `GET utm/antivirus/stats`, `system/fortiguard` | None (IPS *intrusion* counts are in SNMP; AV/AppCtl are not) |
| 1 | **Per-tunnel IPsec/SSL-VPN identity + bytes + user bandwidth** | `GET vpn/ipsec`, `vpn/ssl` | SNMP = tunnel state/count only |
| 1 | **LLDP neighbors, SFP transceiver DDM** | `GET network/lldp/neighbors`, `system/interface/transceivers` | FortiGate doesn't populate LLDP-MIB |
| 1 | **Config revision history + diff (drift)** | `GET system/config-revision` | None |
| 1 | FortiView top-N (talkers/apps/threats), botnet hits, NAT-pool utilization | `GET fortiview/realtime-statistics` | None |
| 2 | Running-process per-PID CPU/mem | `GET system/running-processes` | None |

*Tier 0 (already in SNMP — skip): session count, CPU/mem, IPsec/SSL tunnel state+count, **SD-WAN/link SLA latency/jitter/loss**, IPS intrusions by severity, web-filter blocks, vdom HA, LTE.*

### 3.5 F5 BIG-IP
**API:** iControl REST (`/mgmt/tm/.../stats`). TMOS 15–17. Auth: token (`X-F5-Auth-Token`). **Family A.**

| Tier | Datapoint | API access | Why SNMP can't |
|---|---|---|---|
| 1 | **iRule execution/abort/failure counts** | `/ltm/rule/<n>/stats` | None |
| 1 | **ASM/WAF security events** (violation, attack type, URI, src) | `/asm/events/requests` | None |
| 1 | **APM per-session detail** (VPN/SSO, ACL) | `/apm/sessions/stats` | SNMP = APM session *count* |
| 1 | **Pool-member health *reason* strings** | `.../members/stats status.statusReason` | SNMP = numeric state code |
| 2 | Per-virtual-server / per-pool-member conns/bytes/requests | `/ltm/virtual/<n>/stats` | SNMP LTM coverage exists but is coarser; REST is per-object + L7 requests |
| 2 | Per-client-SSL-profile handshake/TPS/cipher; GTM datacenter/wideIP detail | `/ltm/profile/client-ssl/<n>/stats`, `/gtm/...` | None / partial |

*Tier 0: HA state, global conns, SSL TPS, APM session count, basic LTM VS/pool stats, cert expiry.*

### 3.6 VMware vSphere — *per-VM performance (near-total gap)*
**API:** vСenter SOAP `PerfManager.QueryPerf` (400+ counters) + REST `/api/vcenter/`. vSphere 7/8. Auth: session token. **Family A (note: perf counters are SOAP-only).**

| Tier | Datapoint | API access | Why SNMP can't |
|---|---|---|---|
| 1 | **Per-VM CPU contention** (`cpu.ready`, `costop`, `latency`) | `PerfManager` group `cpu` | The definitive overcommit signal; no MIB |
| 1 | **Memory virtualization** (balloon, swapIn/Out, compressed, overhead) | group `mem` | Early OOM warning; no MIB |
| 1 | **Per-VM disk latency/queue + datastore IOPS/latency** | groups `disk`/`datastore` | No VMFS/NFS datastore abstraction in SNMP |
| 1 | **vMotion telemetry, DRS score, HA admission control, snapshot age, vSAN IOPS/latency** | `PerfManager` + REST inventory | All vCenter-computed; no MIB |
| 1 | VMware Tools state, guest OS identity/IP, license expiry, per-vNIC drops | REST `/vcenter/vm/{vm}/...` | None |

*Tier 0: VM inventory (name/OS/RAM/state/vCPU) — already via VMWARE-VMINFO-MIB.*

### 3.7 Cisco IOS-XE / IOS-XR
**API:** RESTCONF + native/OpenConfig YANG + gNMI. IOS-XE 16.8+/17.x, IOS-XR 7.x. **Family A** (RESTCONF) / streaming.

Tier-1 (no MIB): EVPN EVI/ESI/DF/MAC-IP; per-class QoS queue/drop/WRED; NPU/ASIC dataplane drop counters + fabric health; MPLS-TE auto-bandwidth + FIB/CEF table; BGP FlowSpec; LISP; full config retrieval (drift). Tier-2 (deeper than MIB): per-process CPU/mem, IP-SLA jitter/MOS, per-lane optic DOM, sub-second streaming interface counters, VRRP/HSRP/PIM detail.

### 3.8 Cloud-managed — Meraki / Aruba Central (*SNMP barely applies*)
**API:** Meraki Dashboard API v1 (`X-Cisco-Meraki-API-Key`, 10 req/s/org); Aruba Central REST + Streaming (OAuth2). **Family A** (cloud, not per-device).

Tier-1 (cloud-only): per-client RSSI/SNR history, L7 application attribution per client, 802.11-vs-non-802.11 channel interference, **MX/WAN uplink loss/latency/jitter/goodput @60s**, IDS/malware events (rule ID, file hash), AP health/connection-success/latency stats, PoE per-port mW, cellular/modem stats, WIDS rogue events, presence/location analytics. Almost nothing here is reachable by SNMP-polling the device — the telemetry lives in the vendor cloud.

---

## 4. Cross-cutting themes (elevated, mapped to your hints)

- **Windows-without-SNMP (WinRM):** the biggest single ROI. PerfMon → numeric sensors; service/patch/cert/event-log → structured + alerting. → roadmap **M6**.
- **VRF & EVPN/VXLAN overlay:** route-targets, per-VRF route-count trends, NVE peers, VNI state+counters, EVPN per-AF BGP. Extends the committed NX-OS VRF work. → **M1 (identity) → M2/M4 (counts/peers)**.
- **Multi-VRF / VXLAN FDB enrichment:** an *overlay FDB* table populated from `show l2route evpn mac-ip` / `show bgp l2vpn evpn` — remote MACs with VNI/VTEP/VRF that `ports_fdb` can never show. New structured table; high diagnostic value in fabrics. → **M4+**.
- **Firewall operational state:** session tables, per-rule hit counts, VPN user tables, threat/URL/WildFire logs, DP per-core CPU, license/signature/cert expiry — across Palo + Forti, all Family A. → **M2–M4**.
- **Config-drift, license expiry, certificate expiry** appear on *every* platform with an API and *no* platform via SNMP. A generic "expiry/inventory via API" pattern is broadly reusable.
- **Per-process CPU/mem** is a recurring Tier-2 across NX-OS, IOS-XE, Forti, and Windows — a single "top processes" abstraction would serve all four.

---

## 5. API + version catalog (the transports we'd target)

| Platform | API / product | Versions | Transport | Auth | Family |
|---|---|---|---|---|---|
| Cisco NX-OS | NX-API (ins-api + DME REST), NETCONF/RESTCONF, gNMI | NX-OS 9.2+ / 10.x | REST-JSON `POST /ins`; gRPC | local/AAA over HTTPS | A |
| Cisco IOS-XE/XR | RESTCONF + OpenConfig/native YANG, gNMI | XE 16.8+/17.x; XR 7.x | REST-JSON (RFC 8040); gRPC | Basic/token; gNMI certs | A |
| Windows | WinRM / WS-Man + WQL/CIM + PerfMon | WinRM 3.0 (Server 2012+) | SOAP/WS-Man 5985/5986 | Kerberos/NTLM/CredSSP | **B (pywinrm helper)** |
| Palo Alto | PAN-OS XML API (+ REST) | PAN-OS 10.x/11.x | XML over HTTPS | API key | A |
| Fortinet | FortiOS REST (monitor + cmdb) | FortiOS 7.x | REST-JSON `/api/v2/` | API token (Bearer) | A |
| F5 BIG-IP | iControl REST | TMOS 15/16/17 | REST-JSON `/mgmt/tm/` | token (`X-F5-Auth-Token`) | A |
| VMware vSphere | vCenter PerfManager (SOAP) + Automation REST | vSphere 7/8 | SOAP (perf) + REST-JSON (inventory) | session token | A (SOAP caveat) |
| Cisco Meraki | Dashboard API v1 | v1 | REST-JSON (cloud) | `X-Cisco-Meraki-API-Key` | A (cloud) |
| HPE Aruba Central | Central REST + Streaming | v2.5.x | REST-JSON + protobuf stream | OAuth2 Bearer | A (cloud) |

All Family-A platforms ride the **committed `\ApiQuery`/`HttpApiQuery`** transport. Family-B (WinRM, later gNMI/NETCONF) needs the `Ipmitool`-style helper sibling (M6).

---

## 6. Recommended "populate first" shortlist (highest value / lowest effort)

Ordered by ROI given the committed M1 foundation and your fleet hints:

1. **NX-OS per-VRF route counts** → `poller_type='http'` numeric sensor; trivial once M1 lands. *(your VRF hint, numeric, graphs free)*
2. **NX-OS EVPN/VXLAN: NVE peers + VNI state/counters + overlay MAC-IP FDB** → new structured tables, same `syncModels` pattern as VRF. *(your FDB + VRF hints)*
3. **Windows WinRM PerfMon pack** (CPU/mem/disk-latency/IIS/SQL where present) → `poller_type='wsman'` sensors. *(your Windows hint; biggest ROI; needs M6 helper)*
4. **Firewall license + signature + cert expiry** (PAN + Forti) → generic "API expiry" sensor; tiny, high-signal.
5. **Firewall per-policy/per-rule hit counts** (PAN + Forti) → numeric; capacity + unused-rule detection.
6. **GlobalProtect / SSL-VPN user tables** (PAN + Forti) → structured; remote-access visibility.
7. **VMware per-VM `cpu.ready` + balloon/swap + datastore latency** → numeric sensors (SOAP PerfManager). *(near-total gap; high value)*

Items 1, 4, 5, 7 are numeric and fall straight into the existing sensor→RRD→graph pipeline with **zero new graphing code** (transport-transparency, verified). Items 2, 3, 6 are structured/new-table work.

---

## 7. Caveats & honesty notes
- This is a *capability* gap analysis from public docs + repo mining, not a per-device probe. Exact field/table names (e.g. NX-API `TABLE_vrf`/`ROW_vrf`, WQL class properties) must be confirmed against live gear before building — flagged per platform.
- "SNMP parity: none" means **no MIB**, which is the binding constraint regardless of LibreNMS; "partial" means LibreNMS has an aggregate/identity but the API adds breakdown.
- Tier-0 lists are deliberately explicit so we never build an API poller for something SNMP already graphs.
- Cloud platforms (Meraki/Aruba Central) are org-scoped APIs with rate limits — they fit a per-org poller, not the per-device discovery path.

## 8. Provenance
6 SNMP-coverage miners (repo, file:line-cited): `/tmp/gap/snmp-*.md`. 8 API researchers (public docs, URL-cited): `/tmp/gap/api-*.md`. Join/validation by the main session against the SNMP baseline. Feeds `docs/superpowers/specs/2026-05-30-librenms-transport-architecture.md` milestones M1–M6.

---

# Appendix A — Wave 2: Systems, Hardware, Storage, Cloud, Data Stores (validated)

Same tiering (Tier 1 = no SNMP; Tier 2 = SNMP has aggregate, API has depth; Tier 0 = already covered, skip). The Wave-2 baseline miners surfaced **important corrections** — LibreNMS already covers more server-hardware than expected, and *less* storage-array depth than its OS classes suggest.

## A.1 Honest corrections from the baseline pass
- **Server hardware is largely SNMP-covered already.** LibreNMS ships a `drac` OS (iDRAC via IDRAC-MIB/DELL-RAC-MIB), HPE iLO (CPQ* MIBs), Lenovo/IBM, Cisco UCS, Supermicro — polling fan/temp/voltage/current/power/PSU/CPU/DIMM/disk-state/RAID/SSD-endurance. **Do not** re-collect those via Redfish.
- **Storage-array SNMP is shallow.** LibreNMS collects NetApp = only `dfTable` capacity; Pure = 6 OIDs (bw/IOPS/latency); Nimble = only volume capacity — even though the *MIBs* expose far more (NetApp aggregate/disk/snapshot/latency; Pure capacity/drive-wear/per-volume/hw; Nimble I/O/disk/RAID). So part of the "gap" is closeable in SNMP, and the rest needs REST.
- **DB/middleware partially covered by the `applications` module** (postgres/mysql/redis/opensearch via agent) — but **MongoDB, Oracle, RabbitMQ, inbound Kafka, Cassandra, Zookeeper, HAProxy, JMX, Vault/Consul, etcd, Jenkins have zero coverage.**

## A.2 Server BMC / Redfish (iDRAC, iLO, XCC, Supermicro) — *surgical gap*
**API:** DMTF Redfish (`/redfish/v1/`), iDRAC9/10, iLO5/6, Lenovo XCC, Supermicro. Auth: Basic/session. **Family A.**
- **Tier 1 (Redfish-only):** per-DIMM **ECC correctable/uncorrectable error counts**; **predictive disk failure** (`FailurePredicted`) + SSD wear (`PredictedMediaLifeLeftPercent`); **RAID rebuild progress %** (`Operations[].PercentageComplete`); **SEL / Lifecycle / IML event-log history** (paginated, `$filter` by severity); **full firmware inventory** (BIOS/BMC/NIC/storage/backplane); per-PSU **live input/output watts**; chassis live power (PUE); thermal **margins** (reading vs threshold); pre-OS NIC port health.
- **Tier 0 (skip — already SNMP):** fan/temp/voltage/current/PSU-state/CPU/DIMM/disk-state/RAID-state/SSD-endurance, hardware model/serial.
- **Bonus:** LibreNMS already runs `ipmitool sdr` but **does not parse `ipmitool sel`** — IPMI SEL parsing is a cheap, agentless win even before Redfish.

## A.3 Enterprise storage arrays (NetApp ONTAP, Pure, Dell PowerStore/Unity, HPE Nimble/Alletra) — *big gap*
**API:** ONTAP REST `/api/`, Pure FlashArray REST (+ **native Prometheus `/metrics/array`**, Purity 6.7+), Dell PowerStore/Unisphere, Nimble/Alletra REST (:5392). **Family A.**
- **Tier 1:** **per-volume/LUN/aggregate IOPS + latency (read/write split) + throughput**; **dedup/compression/data-reduction ratios**; **snapshot space per volume**; **replication/SnapMirror lag (sec) + bytes-remaining**; **QoS limits + enforcement**; **SSD endurance %** (PowerStore `life_remaining_percent`); latency decomposition (Pure service/queue/SAN µs); per-protocol NFS/CIFS/iSCSI/NVMe-oF stats; FabricPool cloud-tier capacity; appliance/node CPU.
- **Quick win:** Pure's native Prometheus endpoint = zero-parse LibreNMS ingest. **Also deepen existing SNMP** (NetApp aggregate/disk/snapshot; Pure capacity/drive; Nimble I/O) — cheaper than REST for those fields.

## A.4 IPAM / DNS / DHCP appliances (EfficientIP SOLIDserver, Infoblox, BlueCat) — *your EIP example* ⭐
**API:** SOLIDserver REST `/rest/`, Infoblox WAPI, BlueCat REST + **Prometheus exporter (v25.1+)**. **Family A.**
- **Tier 1 (all API-only):** **DHCP scope/range utilization % + exhaustion** (highest value); **IPAM subnet/block utilization %**; **DHCP failover partner state** (NORMAL/PARTNER_DOWN/COMM_INT); **DNS per-zone query rate/QPS**; **DNS zone + RR counts** (your EIP example — SOLIDserver `/rest/dns_zone_list`); DNSSEC signing/rollover state; active lease enumeration; RPZ/threat-protection hit stats; grid capacity report; DNS cache-hit ratio.
- SNMP on these appliances is near-absent for all of the above.

## A.5 Cloud — Azure Monitor (+ AWS CloudWatch) — *100% gap; your primary cloud* ⭐
**API:** Azure Monitor Metrics REST (+ Resource/Service Health, Activity Log); `metrics:getBatch` for scale. AWS CloudWatch `GetMetricData`. Auth: OAuth2/Entra app-reg or managed identity; SigV4. **Family A (cloud/per-resource).**
- **Tier 1 (no SNMP surface at all):** Azure VM host disk-latency/IOPS/burst-credit + availability score; **all PaaS/serverless** (App Service/Functions latency+errors, **Azure SQL DTU/CPU/storage/deadlocks/replication-lag**, AKS, Storage txn/latency); **App Gateway WAF** per-rule matches; ExpressRoute BGP/ARP/optical; **Resource Health + Service Health** (platform-degraded state); AWS EC2 StatusCheck, **EBS BurstBalance / CPU credit balance** (perf-collapse predictors), Direct Connect optical, RDS/Lambda/DynamoDB/SQS/MSK; billing/cost anomaly.
- Needs encrypted credential store + OAuth token refresh (the M2 onboarding/credential work generalizes to this).

## A.6 Kubernetes / Rancher — *100% gap; your platform* ⭐
**API:** kube-apiserver + metrics-server + kube-state-metrics `/metrics`; Rancher `/v3/`. Auth: token/cert. **Family A.**
- **Tier 1 (no SNMP):** node/pod/container CPU+mem working-set; **OOMKills**; **pod restart counts** (crashloop); deployment/STS/DS **desired-vs-ready replicas**; **PVC phase + capacity** (Bound/Pending/Lost); HPA state; **control-plane latency** (apiserver/etcd commit/scheduler queue depth); **node pressure conditions** (Disk/Memory/PID); Rancher multi-cluster `active/provisioning/error` + per-node roles + k8s version.

## A.7 Linux/Unix advanced performance — *gaps below SNMP + agent*
**Source:** `/proc`, cgroup v2, node_exporter-style, smartctl/nvme, journald. **Family B-ish (agent/exec).**
- **Tier 1:** **PSI (pressure stall)** cpu/mem/io (the highest-value gap — distinguishes IO-bound vs CPU-bound); **per-cgroup-v2** CPU-throttle/OOM/IO; **systemd per-unit failed/restart** (LibreNMS only does aggregate count); **NVMe wear** (LibreNMS smart script broken for NVMe); TCP retransmit sub-types; **journald err/crit rates**; CPU **steal** (noisy-neighbor on VMs); MemAvailable; per-process IO.

## A.8 Databases & middleware — *partial; Kafka is the headline gap* ⭐
**Source:** native stats / REST / **JMX**; Confluent Cloud Metrics API. **Family A/B (JMX needs a helper).**
- **Kafka/Confluent (NET-NEW — LibreNMS Kafka is outbound-only):** **consumer-group lag** per group/topic/partition; **under-replicated partitions**; ISR shrink/expand; active controller count; request rates. JMX-only (or Confluent Cloud `POST /v2/metrics/cloud/query`).
- **Oracle, MongoDB, RabbitMQ, Cassandra, Zookeeper, HAProxy, etcd, Vault/Consul:** no LibreNMS module at all → all net-new.
- **Depth gaps on covered DBs:** PostgreSQL streaming/logical **replication lag** (write/flush/replay), `pg_stat_statements`, autovacuum staleness, checkpoint time; MySQL `Seconds_Behind_Source` + InnoDB lock waits; Redis fragmentation + replication offset + persistence health; Elasticsearch JVM heap/GC + thread-pool rejections + per-shard disk.

## A.9 Citrix ADC / Nginx Plus / HAProxy
**API:** Citrix NITRO REST, Nginx Plus status API, HAProxy runtime API. **Family A.** Tier 1: per-vserver/service throughput + cur/total conns + health/state, per-member health, SSL TPS + cert expiry, GSLB site/service status, AppFlow HTTP req/response-codes, per-packet-engine CPU. (F5-class operational depth; SNMP weak/absent on NITRO specifics.)

## A.10 Wave-2 "populate first" additions (highest ROI)
1. **DHCP scope + IPAM subnet utilization %** (EIP/Infoblox) — numeric sensors; mission-critical exhaustion alerting. *(your EIP hint)*
2. **Azure Monitor `metrics:getBatch`** for VM + Azure SQL + App Gateway — 100% gap, your cloud; per-resource numeric.
3. **Kafka consumer-group lag + under-replicated partitions** — numeric; your Confluent footprint; today completely unmonitored.
4. **Kubernetes pod restarts/OOMKills + replica divergence + node pressure** — your Rancher platform; 100% gap.
5. **Storage-array per-volume IOPS/latency + dedup + replication lag** (Pure Prometheus endpoint is the cheapest entry).
6. **Redfish ECC error counts + predictive-failure + rebuild % + SEL log** — surgical adds atop existing iDRAC/iLO SNMP.
7. **Linux PSI + systemd per-unit + NVMe wear** — universal, cheap via agent/exec.

Items 1–3, 5 are numeric → existing sensor→RRD→graph pipeline (`poller_type` http). Items 4, 6 (logs/events) + Redfish SEL are structured. Cloud/K8s/Kafka are per-endpoint pollers (org/cluster-scoped), not per-device SNMP discovery.

---

# Appendix B — Wave 3: WLC, UC, Backup, HCI, SAN, SASE, Object Storage, CDN, Facility

Baseline correction: LibreNMS already covers **WLC** (Cisco AireOS via `Ciscowlc.php`, Aruba, Ruckus ZD/SZ/Unleashed/HotZone — controller + per-AP radio), **UPS/power** (full RFC1628 + very detailed APC PowerNet incl. PDU/InRow/NetBotz env), and **Brocade FabricOS** (model/SFP/CPU/mem/temp/fan). Gaps below are net of those.

## B.1 Wireless LAN controllers — *modern/cloud gap*
- **Cisco Catalyst 9800 (RESTCONF/YANG-only):** per-client retry/MIC-error counts, **per-AP CPU/mem**, roaming type + run-latency (ms), **RRM neighbor/DCA/TPC inputs + profile pass/fail** (why RRM chose a channel), CleanAir AQI/interference-device reports. *(C9800 has no good SNMP WLC MIB — LibreNMS covers AireOS, not native 9800.)*
- **Juniper Mist (cloud-only):** **SLE (Service-Level-Experience) scores**, time-to-connect stage breakdown (assoc/auth/DHCP ms), roaming-quality classifier — ML-derived in the Mist cloud, no SNMP/agent possible.
- **Ruckus SmartZone API:** per-AP channel-util/noise/interference, **rogue-AP inventory** (queryable, not just trap), per-client retry%/SNR.

## B.2 Cisco Unified Communications (CUCM / CUBE) — *big gap*
**API:** AXL SOAP + RisPort70 (RIS, 18 req/min cap) + CDR/CMR. **Family A (SOAP).**
- **Tier 1:** **SIP-trunk UP/DOWN** (zero SNMP on CUBE — Cisco-confirmed); **call quality MOS/jitter/loss/latency** (CMR files only); per-device registration state (RIS, more reliable than `ccmPhoneTable`); conference/MTP/transcoder/voicemail-port utilization (PerfMon only); CTI app/route-point status; DB-replication degradation.

## B.3 Backup platforms (Veeam, Rubrik, Cohesity, Commvault) — *100% gap*
**API:** Veeam REST + Enterprise Manager, Rubrik GraphQL/RSC, Cohesity REST, Commvault REST. **Family A.** No backup SNMP MIB exists.
- **Tier 1:** **per-job success/failure/warning + last-run time**; **RPO / last-backup age per workload** (highest value); **SLA/policy compliance rate** (in/out); repository capacity + dedup ratio; **protected-vs-unprotected workload count**; ransomware/anomaly alerts; replication/offsite-copy state; immutability/WORM lock; enterprise rollup.

## B.4 Hyperconverged (Nutanix Prism, Dell VxRail, Cisco HyperFlex) — *big gap*
**API:** Prism v3/v4 REST, VxRail API, HyperFlex API. **Family A.**
- **Tier 1:** per-VM CPU/mem/IOPS/latency/net; **resiliency / fault-tolerance budget** (how many node/disk failures survivable); CVM health; cluster IOPS/latency (read/write split, µs); storage-efficiency savings (dedup/compression/clone/thin); **capacity runway/forecast**; queryable alert list; protection-domain/replication compliance.

## B.5 SAN / Fibre Channel (Cisco MDS NX-API, Brocade FOS REST) — *the buffer-credit gap*
- **Tier 1 (the SAN headline):** **per-port buffer-to-buffer credit-zero transition counts** (definitive congestion/credit-starvation signal — no FC-MGMT-MIB OID anywhere); slow-drain counters (`txwait`, `timeout-discards`, `credit-loss-reco`); **full SFP optics DOM + alarm thresholds** (Brocade `media-rdp`); **active zoneset/zone membership**; **FLOGI/name-server DB** (WWN→FCID→port = host-to-storage mapping); VSAN topology (Cisco); RSCN fabric-event log; Brocade MAPS policy/violation state; remote-end ISL error counters.
- *(LibreNMS has Brocade hardware sensors via SNMP, but none of the FC *fabric/performance* data above; Cisco MDS is largely uncovered.)*

## B.6 Zscaler SASE (ZIA + ZPA + ZDX) — *100% gap; your environment* ⭐
**API:** ZIA + ZPA + ZDX REST. **Family A (cloud).** No on-prem device, no MIB.
- **Tier 1:** **GRE/IPsec tunnel + location status & bandwidth**; **ZPA App-Connector availability** (`controlChannelStatus`); **ZDX Score (0–100 UX)** + CloudPath per-hop latency/loss (incl. Zscaler PoP hops) + **call-quality MOS/jitter** (Teams/Zoom/Webex); DLP incident feed; sandbox quota + verdicts; active-user/web-transaction volumes; SSL-inspection failure rates.

## B.7 NAS / object / SDS storage (Ceph, MinIO, Qumulo, PowerScale, Windows FS)
- **Tier 1:** **Ceph** cluster health-state machine (HEALTH_WARN/ERR + named checks), OSD up/in + nearfull, **PG state breakdown**, recovery velocity, per-RGW-bucket stats; **MinIO** per-bucket object/size/quota + erasure-set fault-tolerance margin + replication lag; **Qumulo** per-protocol (NFS/SMB/S3) IOPS/latency + **node-failure budget** + per-directory bytes; **PowerScale/Isilon** per-protocol op latency + tier utilization + quota; **Windows file server** (WinRM) per-share SMB latency/credit-stalls + DFS-R backlog + FSRM quota.

## B.8 Email security & CDN/edge (Proofpoint, Mimecast, Cloudflare, Akamai)
- **Tier 1 (cloud):** Proofpoint/Mimecast per-message threat scores + malware/phish/spam/impostor + URL-click telemetry + sandbox verdicts; **Cloudflare** zone cache-hit-ratio + WAF rule matches + **DDoS events** + 4xx/5xx per zone (GraphQL); Akamai edge TTFB/transfer-time + `edgeAttempts` + WAF (DataStream push — needs a stream sink, not pollable; use Reporting/SIEM API for pull).

## B.9 Datacenter facility / environmental
Baseline: LibreNMS covers APC NetBotz + basic Liebert + RFC1628 env via SNMP. Gaps: **Vertiv iCOM** compressor/refrigerant state + cooling-capacity-output + economizer + fan-VFD% + filter/runtime timers (Modbus/Environet REST); NetBotz **pollable** (vs trap-only) leak/dew-point/door/battery state; EcoStruxure DCE bulk historical sensor pull. **Printers (HP/Xerox): mostly SNMP-covered (RFC 3805) — low API value; skip per your guidance.**

## B.10 Wave-3 "populate first" additions
1. **Backup RPO / last-backup-age + job failure** (Veeam/Rubrik) — 100% gap, universally valuable, numeric+state.
2. **Zscaler tunnel + ZPA connector + ZDX score** — your env; 100% gap.
3. **SAN buffer-credit-zero + slow-drain** (MDS/Brocade) — the definitive fabric-congestion signal; numeric.
4. **HCI resiliency budget + per-VM perf + capacity runway** (Nutanix) — numeric+state.
5. **CUCM SIP-trunk status + call MOS** — telephony blind spot; state+numeric.
6. **Ceph health/PG/OSD + object-store bucket stats** — numeric+state.
7. **C9800/Mist/SmartZone per-client RF + SLE** — modern WiFi UX.

---

# Appendix C — Wave 4: The Long Tail (PDUs, OOB, time, physical security, power-gen, visibility, OT)

Honest verdicts up front — several of these are **mostly SNMP-covered** (per your guidance to not over-invest in them):

| Category | Verdict | The genuine API-only gap (if any) |
|---|---|---|
| **Intelligent PDUs** (ServerTech/Raritan/Vertiv/Eaton/CyberPower) | **Mostly-SNMP — low priority** | Outlet sequencing/group *config* (an action, not telemetry), THD/crest on weak vendors, RCM on non-Xerus, human-readable outlet labels. Skip unless you want label enrichment or RCM on Eaton. |
| **Serial console / smart-OOB** (Opengear, ZPE) | **HIGH** | **Is the OOB/cellular failover path active *right now*** (SNMP can't poll it); cellular signal richness (RSRP/RSRQ/SINR/band/ICCID vs single RSSI); data-usage/quota burn; active console sessions; RS-232 signal lines (DSR/CTS/DCD); NetOps automation state. SNMP covers ~20%. |
| **KVM-over-IP** (Raritan CC-SG, Vertiv DSView/ACS) | **Medium-high (managed only)** | Active-session roster (who/where/how-long), target-power-via-PDU association, audit-log queries, virtual-media mount. Standalone KVMs (ATEN/Lantronix) = SNMP-light, no API. |
| **Time/GPS/PTP** (Meinberg, Microchip, Safran) | **Moderate — scope tight (Meinberg SNMP is strong)** | **PTP per-port offset/path-delay/clock-class** + servo state (master-but-in-holdover is invisible to SNMP); GNSS jamming/spoofing; per-satellite CNR; oscillator EFC/Allan-dev; per-NTP-client jitter; SyncE QL. |
| **Physical security & video** (Milestone/Genetec/Lenel/ONVIF) | **HIGH** | **Per-camera recording state**, **storage runway (hours of footage left)**, retention policy, **door/reader online + forced/held-open**, access grant/deny counts, live-stream bitrate (frozen-but-"up"), VMS archiver failover. Core security-ops questions, all zero-SNMP. |
| **Power-gen / ATS / branch-circuit** (Cummins/Kohler/Generac/ASCO/Schneider BCPM) | **HIGH — but Modbus, not REST** | Engine telemetry (RPM/fuel/oil-pressure/coolant/run-state/kW/fault-codes), ATS transfer events + source, per-branch-circuit current/power. Cummins/Kohler/ASCO have **no SNMP at all**. Eaton ATS/ePDU already SNMP-covered. |
| **Network visibility / WAN-opt** (Gigamon, cPacket, Riverbed, Aruba EdgeConnect) | **HIGH** | Packet-broker map/GigaSMART stats (dedup/SSL/NetFlow), tool-port counters, microburst/flow latency (cPacket), **WAN-opt per-application reduction ratio**, **EdgeConnect SD-WAN path SLA (total SNMP gap)**. |
| **OT/IoT** (BACnet HVAC, Modbus PLC/meters, solar SMA/Fronius/SolarEdge, weather) | **Plugin-tier — high reach via 2 primitives** | All zero-SNMP. Unlocked not by per-vendor APIs but by **a generic Modbus/TCP datasource** and **a generic BACnet datasource**. Solar (Fronius local JSON is trivial) + weather are bonus. |

## C.1 The Wave-4 structural insight
The long tail does **not** need dozens of bespoke integrations. Two generic primitives cover most of it:
- **A generic Modbus/TCP datasource** (IP + register + type → metric) unlocks generators (Cummins/Kohler/Generac), ATS (ASCO), branch-circuit (Schneider BCPM), solar (SMA SunSpec), industrial meters, and PLCs — *implement once, apply broadly.* (PRTG has this; LibreNMS doesn't.)
- **A generic BACnet datasource** unlocks the entire building-automation world (AHU/VAV/chiller/CRAC) that ships zero SNMP.
Both are better as plugins than core, and both ride the same "non-SNMP transport" abstraction as the API/WinRM work (Family B siblings).

---

# Master ROI ranking (cross-wave capstone)

Distilled across all 4 waves: **what to populate first, by return — honestly excluding everything SNMP already does.**

### Tier S — Biggest return, clear path (do first)
1. **Windows via WinRM** — per-process/service, PerfMon (IIS/SQL/.NET), disk latency, patch/cert/event-log. *The single largest operational gap.* (Family B / pywinrm; numeric→sensors, state→structured.)
2. **Cloud — Azure Monitor (+AWS)** — VM/PaaS/AKS/SQL/AppGW/ResourceHealth. *100% gap; your primary cloud.* (Per-resource poller, `metrics:getBatch`.)
3. **Kubernetes / Rancher** — pod restarts/OOMKills, replica divergence, PVC, node pressure, control-plane, Rancher multi-cluster. *100% gap; your platform.*
4. **Backup RPO / job success / SLA compliance** (Veeam/Rubrik) — *100% gap; universal value.*
5. **NX-OS EVPN/VXLAN: NVE peers, VNI counters, overlay MAC-IP FDB, per-VRF route counts, route-targets** — *your VRF/FDB hints; extends committed M1.*

### Tier A — High return
6. **Firewall operational state** (PAN/Forti): session tables, per-rule hits, GP/VPN user tables, threat/URL/WildFire logs, DP per-core CPU, license/cert/signature expiry.
7. **Storage-array performance** (NetApp/Pure/PowerStore/Nimble): per-volume IOPS/latency, dedup, replication lag, SSD wear. *(Pure's native Prometheus endpoint = cheapest entry.)*
8. **Kafka/Confluent inbound** — consumer-group lag, under-replicated partitions, ISR. *(LibreNMS Kafka is outbound-only today.)*
9. **Zscaler SASE** — tunnel/connector health, ZDX score + CloudPath + call MOS, DLP. *Your env; 100% gap.*
10. **SAN buffer-credit-zero + slow-drain** (MDS/Brocade) — definitive fabric-congestion signal; no MIB.
11. **Physical security/video** — recording state, storage runway, door health/events.
12. **IPAM/DNS — DHCP scope + subnet utilization %, zone/QPS, failover state** (EIP/Infoblox). *Your EIP example.*
13. **HCI resiliency budget + per-VM perf + capacity runway** (Nutanix).
14. **Hardware: Redfish ECC error counts, predictive-failure, RAID rebuild %, SEL/Lifecycle logs, firmware inventory** — surgical adds atop existing iDRAC/iLO SNMP. *(Plus: parse `ipmitool sel` — cheap, agentless.)*
15. **Network visibility / WAN-opt** (Gigamon/Riverbed/EdgeConnect SD-WAN path SLA).
16. **Smart-OOB** (Opengear/ZPE) — OOB/cellular failover *active state* + cellular signal richness.

### Tier B — Worth it, scoped
17. **Linux advanced** — PSI, cgroup-v2 per-unit, systemd per-unit failed/restart, NVMe wear.
18. **CUCM/CUBE** — SIP-trunk status, call MOS/jitter.
19. **DB depth** (Postgres replication lag/`pg_stat_statements`, MySQL `Seconds_Behind_Source`, ES JVM/thread-pool) + net-new (Oracle/Mongo/RabbitMQ).
20. **Object/file storage** (Ceph health/PG/OSD, MinIO buckets, Windows SMB latency via WinRM).
21. **Modern WiFi UX** — C9800/Mist SLE/SmartZone per-client RF.
22. **Cloud-managed wireless** (Meraki/Aruba Central) — per-client RSSI/L7/WAN-health.
23. **Time/PTP** — PTP offset/clock-class/servo + GNSS jamming (scope tight; Meinberg SNMP strong).
24. **OT via generic Modbus + BACnet datasources** — generators/ATS/BCM/solar/HVAC (plugin-tier; one primitive, broad reach).

### Skip / deprioritize (SNMP already covers it well — don't build API pollers)
- Intelligent PDUs (vendor RPDU MIBs are complete), UPS (RFC1628 + APC PowerNet), printers (RFC 3805), Eaton ATS/ePDU, server *hardware health state* (iDRAC/iLO/OMSA MIBs), VM inventory (VMWARE-VMINFO), basic interface/CPU/mem/storage, BGP/OSPF/ISIS adjacency state, VRF *identity* (name/RD), Fortigate SD-WAN link SLA, firewall session *counts* + HA state, WLC controller-level AP/client counts, Brocade FC hardware sensors, Meinberg GPS-lock/stratum.

### How these map to the transport architecture
- **Numeric** (route counts, IOPS, latency, lag, util %, MOS, watts) → `poller_type='http'/'wsman'` sensors → existing RRD/graph pipeline, **zero new graph code**.
- **Structured/relational** (EVPN MAC-IP, sessions, users, VRF/RT, recording state, alerts, inventory) → new Eloquent tables via `SyncsModels` (the M1 VRF pattern).
- **Per-endpoint/org-scoped** (Azure, K8s, Zscaler, Meraki, backup, Kafka) → a per-service poller, not per-device SNMP discovery; needs the encrypted multi-transport credential store (M2).
- **WinRM / Modbus / BACnet / JMX** → Family-B `Process`/helper transports (M6 pattern), siblings of `Ipmitool`.

This capstone is the recommended backlog for the post-M1 milestones; it is intentionally honest about the ~15 categories where SNMP already wins.

> **Capstone amendment (Wave 5):** insert **GPU/AI infrastructure (NVIDIA DCGM)** at **Tier S** — it is a 100% gap (no SNMP, no LibreNMS GPU support) and, in an AI-cluster era, arguably the highest-growth monitoring need. Also promote into **Tier A**: AD/Exchange replication+queue health, VDI logon-phase/ICA-RTT, Vault seal-status + Consul raft/critical-checks + Artifactory storage, SD-WAN overlay path-SLA (Viptela/VeloCloud/Cato), SBC per-call MOS, and **ISC Kea DHCP per-subnet lease utilization** (no LibreNMS app today). API-gateway/mesh and self-hosted DNS-resolver depth land in **Tier B**.

---

# Appendix D — Wave 5: Popular-but-Missed (GPU/AI, SD-WAN overlays, SBC, AD/Exchange/M365, VDI, API-gw/mesh, dev-infra, DNS resolvers)

These are mainstream categories the network-first framing under-weighted. All are high-value; **GPU/AI is the standout.**

## D.1 GPU / AI infrastructure (NVIDIA DCGM) — *Tier-S; 100% gap* ⭐
**Source:** DCGM + dcgm-exporter (Prometheus), NVML, DGX BMC (Redfish), Fabric Manager (NVLink). No SNMP; **LibreNMS has zero GPU support**, and its Prometheus support is *outbound-push only* — it cannot scrape dcgm-exporter (architectural gap).
- **Tier 1 (all DCGM-only):** **clock-throttle reasons** (thermal/power-cap/PSU-brake/HW-slowdown bitmask — the production-health canary); **XID error codes** (48 DBE, 63 row-remap-fail, 74 NVLink, 79 GPU-off-bus, 94 contained-ECC — what on-call pages on); **ECC SBE/DBE aggregate + retired/remapped pages + row-remap-failure** (predictive DRAM failure); **tensor-core (HMMA) + DRAM active ratios** (AI workload efficiency); SM/mem/enc/dec util; per-GPU temp/power/power-cap/energy; framebuffer used/free; **per-MIG-instance** GR-engine-active (standard util is invalid under MIG); **NVLink/NVSwitch per-lane CRC/replay/recovery**; PCIe replay + gen/width; per-process GPU accounting; vGPU license.
- **Build:** ride a Prometheus-scrape datasource (or NVML-via-helper, Family B). Numeric→sensors; XID/throttle→state/events.

## D.2 SD-WAN overlays (Viptela/Catalyst SD-WAN, VeloCloud, Versa, Cato)
**API:** vManage REST, VeloCloud Orchestrator, Versa Director/Analytics, Cato GraphQL. Fortinet/EdgeConnect already in Wave 1/4.
- **Tier 1:** **measured path SLA (loss/latency/jitter)** — CISCO-SDWAN-BFD-MIB has session state/flaps but *explicitly lacks measured values*; VeloCloud/Versa/Cato = total SNMP gap; app-aware-routing decisions + per-app SLA; OMP/control-connection (vSmart/vBond) state; per-TLOC/per-color WAN-link util; VeloCloud QoE composite scores; Cato is 100% GraphQL. *(Versa recommends AMQP streaming over polling; Cato/VeloCloud favor batch API.)*

## D.3 Session Border Controllers (Ribbon, AudioCodes, Oracle ACME) — *Tier-A*
- **Tier 1:** **per-call/per-stream media quality (MOS/jitter/loss/R-factor)**; SIP response-code distribution (487/503/4xx/5xx); DSP/transcoding resource exhaustion; registration failure rates per registrar; **CPS per trunk-group** (not just global); license/session-capacity headroom. *(Oracle ACME SNMP is good for counts/CPS/ASR/R-factor; quality+per-code remain REST-only.)*

## D.4 Microsoft AD / Exchange / M365 — *Tier-A; your env*
**API:** WinRM/PowerShell (AD/Exchange) + Graph (M365). **Family B (WinRM) + A (Graph).**
- **Tier 1:** **AD replication health + lag per-partner + FSMO reachability + SYSVOL consistency + LDAP bind latency**; **Exchange transport queue depth** (back-pressure) + **DAG passive-copy CQL/RQL/ContentIndexState** + RPC latency; **M365 service-health incidents** + license consumption + message-trace + Entra sign-in/MFA/Conditional-Access outcomes. *(Extends the Windows/WinRM Tier-S item with AD/Exchange-specific structured data.)*

## D.5 VDI (Citrix Virtual Apps/DaaS, VMware Horizon, Azure Virtual Desktop) — *Tier-A*
**API:** Citrix Monitor OData, Horizon REST, AVD via Azure Monitor. Auth: OAuth2/session.
- **Tier 1:** **logon-duration phase decomposition** (7 phases: brokering/VM-start/HDX/auth/GPO/profile/scripts — answers "why was logon slow"); **ICA/Blast RTT + frame-delivery quality** (encode/network/decode/render/dropped%); per-delivery-group/pool active-vs-disconnected session counts; connection-failure reasons + exit codes; machine/desktop **registration state** (broker sees "deregistered" while OS is "up"); AVD UDP-Shortpath-vs-TCP-relay transport; license usage.

## D.6 API gateways & service mesh (Kong, Apigee, NGINX Plus, Istio/Envoy) — *Tier-B*
- **Tier 1 (Prometheus/admin REST):** per-route req-rate + latency percentiles + 5xx/4xx; **upstream health + circuit-breaker state** (`cx_open`/`rq_pending_open`); **rate-limit throttle events**; Envoy **outlier-detection ejections** + mTLS handshake failures (`fail_verify_cert/san`); NGINX Plus upstream downtime accumulator; Apigee per-proxy latency (OAuth, ~10-min delay); **Kong AI/LLM token + cost** (v3.8+, novel — no analog).

## D.7 Dev infra (JFrog Artifactory, HashiCorp Vault/Consul, Jenkins, GitLab) — *Tier-A for Vault/Artifactory; your env*
- **Tier 1:** **Artifactory** repo storage + artifact count + download stats + GC history + replication lag + license; **Vault SEAL status** (highest-urgency — sealed = total secrets outage) + client-count + lease-count + audit-device health + clock-skew; **Consul critical-check count + raft-leader presence + serf member state + commit latency**; Jenkins queue depth + executor util + build success/duration; GitLab pipeline success-rate + runner availability.

## D.8 Self-hosted DNS/DHCP resolvers (BIND, PowerDNS, Unbound, ISC Kea) — *Tier-B, but Kea is Tier-A*
Baseline: LibreNMS has BIND/PowerDNS agent apps (partial).
- **Tier 1:** BIND per-zone **NXDOMAIN/SERVFAIL/QPS** + DNSSEC validation failures + RRL drops + QTYPE distribution (in the stats-channel, not the agent); PowerDNS DNSSEC sub-results + latency histogram + RPZ policy-drops; **Unbound — no LibreNMS app at all** (~60 counters via `unbound-control`); **ISC Kea DHCP — no LibreNMS app, HIGH ROI:** per-subnet **lease utilization** (`stat-lease4-get` = DHCP's "interface utilization"), declined leases (IP conflicts), HA pair state. Kea has replaced ISC DHCP nearly everywhere.

## D.9 Wave-5 verdict
Every Wave-5 category is a genuine gap (none are "mostly-SNMP" — unlike PDUs/UPS in Wave 4). **GPU/AI is the highest-value single addition uncovered in the entire analysis** and should be treated as Tier-S alongside Windows/WinRM, cloud, and Kubernetes. AD/Exchange and Vault/Artifactory are directly relevant to your stated environment (Windows + Azure + JFrog + HashiCorp).

---

## Coverage note (where the analysis stands)
Five waves cover ~50 popular platform categories across networking, security, compute, hardware/OOB, storage, cloud, containers, data stores, UC/video, facility, and AI infrastructure — i.e. the categories with the **biggest returns**, per the brief. The Master ROI ranking + this appendix set are the actionable backlog.

---

# Appendix E — Wave 6: Enterprise-but-Missed (mainframe/IBM i, Proxmox/oVirt, registries, observability/SIEM, messaging, tape/VTL, mail, Nomad/OpenShift)

All genuine gaps; several directly relevant to your stack (Proxmox-adjacent, Artifactory/registries, messaging, mail).

## E.1 Mainframe & midrange (IBM z/OS, IBM i) — *High value, very high effort*
**API:** z/OS RMF DDS (**OpenMetrics/Prometheus since z/OS 3.1** — exactly LibreNMS-pollable) + z/OSMF REST; IBM i via IBM i Services SQL views + Navigator REST + XMLSERVICE. **Family A/B.**
- **z/OS (all RMF/SMF-only — SNMP is a TCP/IP-stack monitor):** per-LPAR CPU (GP/zIIP/zAAP), **MSU/MIPS consumption** (maps directly to IBM license cost — highest value), **WLM service-class Performance Index** (the canonical health signal), paging/aux-storage, DASD response time/queue, coupling-facility contention, CICS/Db2/MQ transaction rates.
- **IBM i — ⚠ blocker first:** IBM i doesn't implement SNMPv2c (LibreNMS's default) → **it silently appears offline in LibreNMS today**. Via IBM i Services SQL: CPU, ASP/disk-pool utilization, **job-queue depth**, subsystem state, **temp-storage % (outage precursor)**, QSYSOPR message severity, memory-pool faults, IASP status. *(Unisys ClearPath: closed ecosystem, excluded.)*

## E.2 Hypervisors beyond VMware (Proxmox VE, oVirt/RHV, XCP-ng) — *Tier-A; Proxmox especially*
**API:** Proxmox `/api2/json`, oVirt REST, XCP-ng XAPI. *(LibreNMS Proxmox agent captures only per-VM network today; oVirt = zero.)*
- **Tier 1:** **cluster quorum + HA fence/error state**; per-VM/container CPU/mem/disk-IO + ballooning; storage-pool (ZFS/Ceph/LVM) capacity + type-aware health; **Ceph-in-PVE status**; **backup-job outcomes** (vzdump/PBS — silent 7-day failures); replication lag; oVirt SPM election + datacenter status.

## E.3 Container registries (Harbor, Quay, Nexus, ECR/ACR) — *Tier-B (security-relevant)*
- **Tier 1:** **per-artifact vulnerability-scan CVE counts** (Trivy/Clair — security view); per-project **storage quota vs usage**; replication health/lag; **robot/service-account expiry** (breaks CI silently); GC status; push/pull rate trending; **Nexus 40k-component cap** (CE). Cloud registries (ECR/ACR) route through CloudWatch/Azure Monitor only.

## E.4 Observability & SIEM self-monitoring (Splunk, Elastic, Logstash, Loki) — *Tier-B (meta-monitoring)*
- **Tier 1:** **Splunk license burn-rate** (overage blacks out indexing) + **skipped scheduled searches** (silent failure) + indexer-cluster replication/search-factor; **Logstash queue backpressure + DLQ size**; **ES thread-pool rejections + circuit-breaker trips** (earliest overload warning); Loki flush-queue depth. *(Monitoring the monitoring stack.)*

## E.5 Message brokers beyond Kafka (RabbitMQ, NATS, Pulsar, ActiveMQ) — *Tier-A; Very-High/Med-effort*
- **Tier 1 (zero SNMP, no LibreNMS module):** **RabbitMQ cluster partition/split-brain** + **mem/disk alarms** (halt all publishers) + per-queue depth/redeliver-rate/consumer-utilisation; **NATS slow-consumer + JetStream `num_pending` lag** (the Kafka-lag equivalent); **Pulsar per-topic + cross-DC replication backlog**; ActiveMQ **DLQ depth**. Pairs with the Wave-2 Kafka gap → a unified "message-broker" coverage push.

## E.6 Tape libraries & VTL (IBM TS, Quantum, Spectra, DataDomain) — *Tier-B*
- **Tier 1:** **cartridge/slot inventory + scratch-pool depth**; **cleaning management** (cleans-remaining/overdue — missed cleaning kills drives); robotics/accessor health; **DataDomain cloud-tier capacity + DD-Replicator lag** (DR RPO); Spectra per-tape wear/error history. *(SMI-S/CIM is a parallel collector gap.)*

## E.7 Self-hosted mail & groupware (Postfix/Dovecot/Zimbra/mailcow) — *Tier-B*
Baseline: LibreNMS has a partial Postfix/Exim agent app.
- **Tier 1:** **deferred-queue AGE distribution** (transient wave vs structural failure — the key gap); **Dovecot is fully blind today** (IMAP sessions/auth-failures/quota); reject-reason sub-categories (RBL/HELO/policy); per-domain volumes; Rspamd/ClamAV action counters; **per-mailbox quota exhaustion** (silent delivery failure); auth-failure trending (credential-stuffing).

## E.8 Orchestration beyond vanilla K8s (Nomad, OpenShift, OpenFaaS, Swarm) — *Tier-B*
- **Tier 1 (no SNMP MIBs exist by design):** **Nomad `FailedTGAllocs`** (*why* allocs won't place: resource/quota/constraint) + canary-deployment health; **OpenShift build-pipeline success/duration + route admission status**; OpenFaaS per-function error-rate/latency/cold-start; **Swarm desired-vs-running replica delta** + rollback state.

## E.9 Wave-6 ROI placement
Promote into **Tier A**: Proxmox VE (cluster/HA + per-VM + backup state), message brokers (RabbitMQ/NATS/Pulsar — pairs with Kafka). **Tier B:** registries, observability self-monitoring, tape/VTL, self-hosted mail, Nomad/OpenShift. **Special (Tier-A value, very-high effort):** mainframe z/OS (MSU/WLM-PI) and IBM i — note the IBM i SNMPv2c blocker is itself worth surfacing to users (their IBM i likely shows offline today).

---

# Closing note — analysis complete (6 waves, ~60 categories)

This document now covers the **biggest-return** SNMP-vs-API/WinRM gaps across networking, security/firewall, compute, server/BMC hardware, block + file + object storage, cloud (Azure/AWS), containers/K8s/orchestration, data stores + messaging, UC/video/SASE, facility/environmental, **GPU/AI**, mainframe/midrange, and dev/observability infra — drilling from the most-popular platforms down through the long tail (PDUs, serial/KVM, tape) you flagged. The consistent, honest finding: **LibreNMS's SNMP baseline is genuinely deep** (it already polls ~half the categories well — see the Master ROI "skip" list), and the real value is **operational depth/logs/per-entity/per-flow data, plus the categories with no SNMP surface at all** (Windows/WinRM, cloud PaaS, Kubernetes, GPU/AI, SASE, backup, messaging, mainframe workload).

**Recommended next steps:** (1) treat the **Master ROI ranking (Tier S/A)** as the post-M1 backlog; (2) build the transport plumbing once per *family* (HTTP/REST `\ApiQuery` ✅ committed; per-endpoint/org-scoped cloud poller; Family-B helpers for WinRM/Modbus/BACnet/JMX/Prometheus-scrape); (3) the **two highest-leverage primitives** uncovered are a **Prometheus-scrape datasource** (unlocks GPU/DCGM, K8s, MinIO, Loki, OpenFaaS, BlueCat, Pure storage in one stroke) and a **generic Modbus/BACnet datasource** (unlocks generators/ATS/BCM/solar/HVAC). Remaining uncovered territory is the deferred niche (Unisys, medical/DICOM, a handful of one-off appliances) — "add over time." (Wave 7 below extends into the vertical categories.)

---

# Appendix F — Wave 7: Vertical categories (industrial/OT, AV, cloud-IoT, EV/energy, DCIM, edge/retail, satellite, building-safety)

Honest framing: these are **industry/vertical-specific** — lower relevance to an enterprise-Azure-IT fleet like yours, but real, popular categories that complete the analysis as a general LibreNMS resource. Several are **not feasible** LibreNMS targets (flagged).

| Category | Verdict | The genuine API-only gap / note |
|---|---|---|
| **Industrial OT historians / SCADA** (OSIsoft/AVEVA PI, GE Proficy, Ignition, OPC-UA) | **High value (industrial), high effort** | PI Web API only: tag counts, **PIBufSS buffer queue depth** (silent data-loss), license/point utilization, calc-engine status; GE Proficy **collector state** (running/stopped/errored — #1 cause of historian gaps); Ignition 8.3+ REST (redundancy/OPC/alarm-pipeline); **OPC-UA session/subscription health** (no MIB, no agent). Auth complex (Kerberos/OAuth); **OT/IT segmentation** is a deployment constraint. |
| **AV-over-IP / pro-AV / broadcast** (Q-SYS, Crestron, Extron, Dante, PJLink) | **Mixed; one clear quick-win** | **PJLink projectors = quick-win** (TCP 4352, no license, ~every brand, lamp-hours + ERR1–5 fault vector; LibreNMS has none). Dante DDM GraphQL (50+ RxChannel states + clock-offset — predicts audio dropout); Q-SYS (zero SNMP, QRC/Reflect only); Crestron XiO room health (cloud, license-gated). **NDI/SDVoE = not feasible** (embedded SDK / vendor-fragmented). |
| **Cloud IoT** (AWS IoT Core, Azure IoT Hub, ThingsBoard) | **High (IoT shops); zero SNMP** | Connected-device count, message in/out + **throttle metrics** (cascade to data loss), per-device last-seen, shadow desync, rule-engine throughput. All cloud-monitor API. (Google IoT Core is retired.) |
| **EV charging & smart energy** (OCPP, SunSpec Modbus, Tesla, microgrid) | **Rising; architecture mismatch** | Zero SNMP. **OCPP is event-driven push** (needs a CSMS listener, not a poller shim); **SunSpec Modbus (model 120/802)** is the standardized BESS/solar path (Zabbix has it, LibreNMS doesn't); **microgrid island/grid-mode boolean** = high-value alert; per-connector charging state + session kWh. |
| **DCIM suites** (Sunbird, Nlyte, EcoStruxure IT, Device42) | **Medium; analytics/topology layer** | **Stranded capacity** (allocated-but-unused kW — pure DCIM construct), **rack-as-logical-container** rollups, **power-chain topology** (UPS→PDU→rack→server relationship data SNMP can't express), per-rack delta-T. The suite *aggregates* SNMP devices and adds the allocation/topology/analytics layer. |
| **Edge / retail / digital signage** (BrightSign, signageOS, POS, PTMS) | **Medium (retail/QSR)** | Player *liveness* (process + last-checkin) vs interface liveness, display power/HDMI-signal state, **proof-of-play** (revenue/compliance — a category SNMP can't address), playback-error logs, payment-terminal PCI/tamper/cert state, content-distribution sync lag per store. |
| **Satellite / teleport / RF / DAS** (iDirect, Comtech, Hughes, DAS) | **Niche (maritime/telecom)** | **iDirect modem SNMP largely non-functional on current HW** (RF telemetry behind iVantage NMS); **Es/N0 + rain-fade margin** (highest value, no MIB); beam/ACM state; DAS per-sector power/VSWR/optical (vendor NMS only). Comtech is the most SNMP-capable. |
| **Building safety & access** (fire panels, elevators, Metasys/Niagara BMS) | **Facilities; mostly not-feasible via API** | Zero native SNMP; **BACnet is the field protocol** (LibreNMS has no BACnet poller). Fire panels gated behind licensed gateways + NFPA-72/AHJ constraints (read-only via certified gateway only); elevators cloud-mediated + sales-gated (KONE/Otis); Metasys REST is the realistic target (licensed); Niagara SNMP is inbound-only. |

## F.1 Wave-7 reinforcement of the primitive insight
Wave 7 strengthens the capstone's two-primitive recommendation:
- **Generic Modbus datasource** — now also justified by **SunSpec (solar/BESS), smart sub-meters, and EV/energy** (Zabbix has this; LibreNMS's clearest single capability gap vs. Zabbix).
- **Generic BACnet datasource** — now also justified by **building-safety/BMS** (fire/elevator/Metasys/Niagara all BACnet).
- **New cheap quick-win:** a **PJLink poller** (TCP 4352) covers the global installed projector base with no license and no SNMP dependency — a small, self-contained addition with broad reach.
- **Architecture note:** OCPP (EV) and Dante/NDI (AV) are **push/event** or **embedded-SDK** models that don't fit a polling NMS cleanly — they'd need a listener/sidecar (a further Family-B variant), which is why they're lower-priority despite zero SNMP coverage.

---

# Final close — analysis comprehensively complete (7 waves, ~68 categories)

The analysis now spans **seven waves and ~68 platform categories**, from the most-popular enterprise infrastructure (networking, firewalls, servers, storage, cloud, virtualization, Windows, GPU/AI) through the long tail (PDUs, serial/KVM, time, tape, mainframe) and into vertical domains (industrial/OT, AV, EV, building, satellite) — i.e. the full arc you asked for, **biggest-returns first, drilling toward the niche.** Everything below this is genuinely one-off / sales-gated / regulated gear best added incrementally.

**The three durable conclusions:**
1. **LibreNMS's SNMP/agent baseline is deep** — it already covers roughly half the categories well (see the Master ROI "skip" list). The honest gap is *operational depth, logs, per-flow/per-entity/per-process data*, plus the categories with **no SNMP surface at all**.
2. **The Tier-S gaps** (build first): **Windows/WinRM, Azure/cloud, Kubernetes, GPU/AI (DCGM), backup RPO, NX-OS EVPN/VXLAN+overlay-FDB** — the highest return, clearest paths.
3. **Build by transport family, not per device.** Implement once: ✅ HTTP/REST `\ApiQuery` (committed) → covers dozens of vendors; a **per-endpoint/org-scoped cloud poller** (Azure/AWS/Meraki/Zscaler/backup/SaaS); **Family-B helpers** for WinRM, **a Prometheus-scrape datasource** (unlocks GPU/K8s/MinIO/Loki/ThingsBoard/BlueCat/Pure in one stroke), and **generic Modbus + BACnet datasources** (unlock the entire OT/facility/energy/building long tail). A small **PJLink** poller is a bonus quick-win.

This document + the multi-transport architecture spec (`2026-05-30-librenms-transport-architecture.md`) together form the strategy and the backlog. The committed M1 (`nxapi-vrf` branch) is the first concrete proof; the Master ROI ranking is the post-M1 roadmap.
