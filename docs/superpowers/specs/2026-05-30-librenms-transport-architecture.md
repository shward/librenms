# LibreNMS Multi-Transport Architecture — Design Proposal (expanded vision)

**Date:** 2026-05-30
**Author:** Josh Thomas-Ward (with Claude Code)
**Status:** Discovery complete — awaiting decisions (§10)
**Supersedes scope of:** `2026-05-29-librenms-api-data-source-design.md` (that spec's Milestone 1 survives as M1 here)
**Repo:** fork of LibreNMS, branch `nxapi-vrf`

---

## 1. Vision

Make **HTTP/REST APIs and WinRM first-class, co-equal data-collection transports** beside SNMP — baked into
onboarding, discovery, and polling. Not a widget. Not a fallback. A value can come from SNMP or an API; the rest
of LibreNMS neither knows nor cares. The scaling mechanism mirrors what LibreNMS already does for SNMP: just as it
ships **MIBs** and discovery consumes them, it ships **API/WQL definitions** and discovery consumes those too.
Some device classes become SNMP-optional or **API-only** — the headline being **Windows over WinRM with no SNMP**,
because SNMP on Windows performs terribly.

**Locked decisions (user):** upstream-mergeable; encrypt credentials at rest; **auto-detect transport at
onboarding** (probe SNMP *and* API, record what the device speaks); dashboard widget **shelved** (display is
automatic). WinRM/Windows-without-SNMP is an explicit target.

---

## 2. The load-bearing finding (verified across dimensions)

**LibreNMS's pipeline is already transport-transparent from the fetch boundary up.** Independently verified:
`app('Datastore')->put()` fans any value to every backend without inspecting transport (`Datastore.php:113,139`);
RRD file identity keys on `class/type/index`, **not** `poller_type` (`common.php:133`), so an SNMP- and an
API-sourced sensor of the same class graph to the *same* file; sensor graph defs read only the model + RRD
(`graphs/sensor/generic.inc.php`); `SyncsModels` diff-persists any `Keyable` collection regardless of origin
(`SyncsModels.php:45`); and no display page filters by transport. **Nothing below the fetch step changes.**

The only SNMP assumptions live at the **fetch step**, and there are exactly three:
1. `poll_sensor()` ships every non-agent/non-ipmi sensor to `bulk_sensor_snmpget()` (`functions.inc.php:43`).
2. Modern modules hardcode `\SnmpQuery` inside `poll()` (before the source-blind `$datastore->put()`).
3. `YamlDiscovery::preCache()` hardcodes `SnmpQuery::walk()` (`YamlDiscovery.php:388`) — the **single** declarative seam.

This is why the vision is *additive*, not a rewrite: we branch these fetch seams and let the existing rails carry it.

---

## 3. The two transport families

| Family | Mechanism | Transports | LibreNMS shape |
|---|---|---|---|
| **A — JSON/REST over HTTP** | Laravel HTTP client | NX-API, RESTCONF, Meraki, Aruba Central, generic REST | `\ApiQuery`/`HttpApiQuery`/`ApiResponse` (**already committed**, Tasks 1–3) |
| **B — protocol-helper siblings** | shell out to an external helper via `Process` | **WinRM** (pywinrm), later gNMI (gnmic), NETCONF | new `WinRmQuery`/`WinRmResponse` modeled **line-for-line on `Ipmitool.php`** |

**Why WinRM is Family B, not A (web-researched):** WinRM is WS-Management — SOAP/XML over 5985/5986 with
NTLM/Kerberos/CredSSP auth and base64-UTF-16LE PowerShell payloads in a stateful Shell/Command/Receive sequence.
`HttpApiQuery` is JSON-only with Basic/Token auth and cannot express this. The **PHP WS-Man ecosystem is dead**
(acropia/PHP-WinRM, webzes/Wsman, kroshilin/winrm — all abandoned, Basic-only, none on Packagist; libcurl's NTLM is
fragile and there's no Kerberos/CredSSP in PHP cURL). The proven path — used by **OpenNMS** — is a real WS-Man client
+ a declarative datacollection config. We mirror that: a ~60-line repo-shipped `scripts/winrm_query.py` (pywinrm,
maintained, supports NTLM/Kerberos/CredSSP), invoked exactly like `ipmitool`/`fping`/`net-snmp` are today. Realistic
SNMP-free Windows metrics via WQL/PowerShell: CPU (`Win32_Processor`), memory (`Win32_OperatingSystem`), disk
(`Win32_LogicalDisk`), services (`Win32_Service`), arbitrary perf counters (`Get-Counter`).

---

## 4. The pluggable transport abstraction (light touch)

Do **not** force one interface — the verbs genuinely differ (`walk/get/next` vs `get/post/cli` vs `powershell/wql`).
Instead:
- **`TransportResponse`** contract — `isValid()`, `getErrorMessage()`, `mapTable()`. `SnmpResponse` already
  satisfies it; `ApiResponse`/`WinRmResponse` implement it. This is the common shape every consumer relies on.
- Keep the per-transport query classes (`NetSnmpQuery`, `HttpApiQuery`, future `WinRmQuery`) with their natural
  verbs, each fronted by a facade (`\SnmpQuery`, `\ApiQuery`, future `\WinRm`).
- **`ConnectivityHelper`** becomes the single source of truth for "what transport(s) does this device speak and
  which is primary" (`transports()`, `primaryTransport()`, `apiIsAllowed()`).
- A **`Transport` enum** (`Snmp|Api|Icmp|WinRm`) is the shared vocabulary.

Modules stay transport-agnostic: an OS capability method (`$os->discoverVrfs()`, `$os->pollProcessors()`) calls
`\SnmpQuery` or `\ApiQuery` (or both) *internally* and returns the same Eloquent/`Datastore` result — exactly as the
NX-OS VRF work already does. The dispatcher's only new job is the reachability/enable gate.

---

## 5. Onboarding auto-detect + multi-transport credentials

Generalize the existing **SNMP-only** auto-detect (`ValidateDeviceAndCreate::detectCredentials()`,
`:95-153`) into a transport-agnostic loop:
- A `TransportDetector` per transport. **SNMP wraps the existing version/community/v3 loop verbatim** (zero
  behavior change). API/WinRM add `DeviceIsApiReachable`/`DeviceIsWinRmReachable` probes (peers of
  `DeviceIsSnmpable`).
- **Record every transport that succeeds** (not first-wins) — a device can be both SNMP and API.
- Throw `HostUnreachable*` only when **no** transport (and no ping-fallback) succeeds — so an API-only / SNMP-degraded
  device onboards cleanly (the killer Windows/NX-OS case).
- Config defaults `api.credentials` / `winrm.credentials` (type `password-array`, like `snmp.community`) let
  onboarding try org-default cred sets; device-specified creds go first, same idiom as SNMP.
- Extend addhost UI / `device:add` CLI / REST `add_device` with API/WinRM fields, masked write-only (`********`).

**Credentials at rest:** encrypted columns on `devices` via Laravel's `encrypted` cast.
- Secrets (`api_password`, `api_token`, `winrm_password`) must be **`TEXT`** (ciphertext > 64 bytes) — *not* `varchar`.
- `$hidden = ['api_password','api_token','winrm_password']` (safe: brand-new columns, no legacy `toArray()` reader;
  `HttpApiQuery` reads them via attribute access which bypasses `$hidden`). This also closes the REST-echo leak for
  the new secrets. (The pre-existing SNMP-cred plaintext leak is a separate, optional boundary-scrub fix.)
- **`KeyRotate` must be extended** to re-encrypt these device columns. *Correction to the M1 spec:* `KeyRotate`
  today only rekeys one config row — it does **not** iterate model columns, so device-column rekey is **net-new**
  and must ship in the same PR as the encrypted columns, or `key:rotate` silently orphans creds.
- **Operational hard requirement:** `APP_KEY` must be byte-identical on all 9 cluster nodes or remote pollers can't
  decrypt. Add a decrypt self-test to `validate.php`.

---

## 6. Declarative definitions — "MIBs for APIs/WQL"

Branch the **single** source-blind seam `YamlDiscovery::preCache()` (`:388`) on a per-entry `source:` discriminator
(enum **`snmp|api|winrm`** from day one to avoid schema churn; default `snmp` so every existing file is unchanged).
A new `ApiDiscovery::fetch()` (the SNMP-walk analog) runs `\ApiQuery`, and on success normalizes the JSON into the
**identical `$pre_cache[oid][index][column]` shape** `valuesByIndex()` produces — after which `discover()`,
`getValueFromData()`, `replaceValues()`, `canSkipItem()`, and the sensors `discovery_process()` loop are **100%
reused, untouched**. On transport error it leaves the bucket empty **and sets a transient-error flag so cleanup is
suppressed** (never delete rows on a blip).

- Definitions live in the existing `resources/definitions/os_discovery/<os>.yaml` (the per-OS "MIB"); optional
  `resources/definitions/api/<profile>.yaml` holds reusable endpoint/envelope fragments referenced by name (the DRY
  "shared MIB"). WinRM/WQL definitions follow the OpenNMS `wsman-datacollection-config` shape
  (`wql`/`namespace`/`index_of`/`attrib`).
- **Schema gotcha (top CI-break risk):** `discovery_schema.json` has `additionalProperties:false` at every level
  and `YamlSchemaTest` enforces it — the new `source`/`api`/`winrm` keys **must** be added to the schema in the same
  PR, and `num_oid` must be relaxed for non-SNMP sources (synthesize a stable `sensor_oid`).

**Numeric polling:** reuse the existing `poller_type` discriminator (peer of `ipmi`/`agent`). Add an `http`/`wsman`
branch in `poll_sensor()` **before** `bulk_sensor_snmpget()` (so an api path is never sent to net-snmp), batch one
fetch per (device, path), and feed the **unchanged** `record_sensor_data()` → `Datastore::put()` → auto-graph.
**Critical:** skip recording when `isValid()` is false (recording 0 poisons the RRD).

---

## 7. Transport-aware dispatch (generalizing the SNMP-up gate)

Today "device up" == "up via SNMP" at every dispatch point. Generalize without touching the ~50 module call sites:
- `CheckDeviceAvailability` probes the device's **primary** transport (ping → primary-transport probe); extend
  `AvailabilitySource` with `Api`/`WinRm` so `status_reason` is honest.
- `ModuleStatus::isEnabledAndDeviceUp()` gains an **optional** `Transport $require` parameter. **Purely additive** —
  all 50 existing callers pass nothing → defaults to `Snmp` → unchanged. The `LegacyModule` `['ipmi','unix-agent']`
  name-blacklist becomes `require: null` (transport-agnostic), generalizing the hardcode.
- An OS declares its posture via a marker interface + `defaultPrimaryTransport()` (e.g. a new `OS/Windows` returns
  `WinRm`). `OS::make()` is unchanged.
- Keep `snmp_disable`; derive `transports()` from it + the api columns (no data migration of existing installs).
- **Guardrails:** the `ModuleStatus` change must be grep-swept across all 50 sites; `Core` bypasses `ModuleStatus`
  and must be updated in lockstep; new availability behavior gates on `primary_transport == Api` so existing
  `snmp_disable`+ping devices are byte-for-byte unchanged.

---

## 8. Milestone roadmap (6 upstream-mergeable PRs)

Ordering is load-bearing: `HttpApiQuery` already references `api_*` columns that don't exist and the facade isn't
registered, so PR-A makes the committed code reachable and PR-B must land before any real caller.

| Milestone | PRs | Ships | Upstream? |
|---|---|---|---|
| **M1** — wire + persist + first slice | PR-A facade+alias (zero behavior change) · PR-B encrypted creds migration + KeyRotate + `$hidden` · PR-C NX-OS VRF NX-API fallback (`Vrf` gains `Keyable`; creds-gated; preserve `ifVrf`; prune-only-on-valid) | NX-OS non-default VRF discovery via NX-API | A/B yes; C engine yes, vendor strings fork-local |
| **M2** — onboarding auto-detect | PR-D `detectTransports()` + `DeviceIsApiReachable` + multi-transport record | Add-device probes API like SNMP | Yes (probe default-**off**) |
| **M3** — source-agnostic display | PR-E `DeviceVrfsController` (the shelved widget, revived as optional sugar) | VRFs visible on tabs/widget regardless of source | Yes |
| **M4** — declarative numerics | PR-F `source: api` at preCache seam + `poller_type='http'` | New numeric coverage = a definition file, no PHP ("MIBs for APIs") | Engine yes; defs fork-local |
| **M5** — SNMP-optional device class | relax `! snmp_disable` gates; API-driven OS detection | API-only devices onboard + poll | Yes |
| **M6** — WinRM (fork-first) | `WinRmQuery`/`WinRmResponse` + `scripts/winrm_query.py` + `OS/Windows` capability methods + `source: wsman` | **Windows with no SNMP** | Fork-local until a vetted helper story |

**Coexistence guarantee throughout:** every non-SNMP path is gated on creds-present and never alters an SNMP-only
device; all new columns are nullable with safe defaults (`transports`/`primary_transport` backfilled to SNMP); the
`source:` branch defaults to SNMP.

---

## 9. Key risks (carried from the exploration)
- **`additionalProperties:false` schema lockdown** — new YAML keys must land in `discovery_schema.json` same-PR or all
  os_discovery validation fails. #1 CI-break risk.
- **`KeyRotate` device-column rekey is net-new** (not "already proven") — ship with the encrypted columns.
- **`ModuleStatus` blast radius** — ~50 callers; signature change must be additive + grep-swept; `Core` updated in lockstep.
- **APP_KEY divergence across 9 nodes** breaks decryption on remote pollers — validate + document.
- **Transient-error data loss** — both the sensor sync and discovery prune must suppress on transport error
  (`isValid()` distinguishes error from empty); recording 0 poisons RRDs.
- **Per-node config cache** — definition-file edits (M4+) need a flush on all 9 nodes (creds in DB columns avoid this).
- **WinRM secrets** — pass via stdin, never argv (do **not** copy `Ipmitool`'s `-P` on argv); pywinrm is an optional
  poller dependency like ipmitool/fping; upstream may resist a Python dep (mitigate with the external-binary precedent).
- **`api_password` width** — `TEXT`, not `varchar(64)`, or ciphertext truncates and corrupts creds.

---

## 10. Decisions needed

1. **Credential storage shape.** Flat encrypted `api_*`/`winrm_*` columns on `devices` (simpler, M1-ready, models
   API-as-secondary) — *or* a `device_transports`/`device_credentials` child table keyed `(device_id, transport[,label])`
   (true co-equal/multi-instance, e.g. two API endpoints or per-VRF NX-API; bigger migration). The auto-detect
   "record all transports" vision leans co-equal; flat columns + a `transports` bitmask is the pragmatic middle.
   **This locks the M2 schema — decide before PR-B.**
2. **WinRM helper runtime (M6).** `pywinrm` (Python dep on pollers; mature; NTLM/Kerberos/CredSSP; matches OpenNMS) —
   *or* a self-contained Go binary (heavier to build/distribute, single artifact, no Python) — *or* defer the call to M6.
3. **Upstream encryption stance.** Upstream LibreNMS has historically kept SNMP creds plaintext and may resist
   encrypted columns. Build encrypted for the fork regardless (and propose upstream) — *or* open a pre-PR community
   discussion first to avoid building something upstream rejects?
4. **Onboarding probe default.** Default-**off** (explicit enable; avoids probing every added device's :443 / tripping
   IDS — upstream-safer) — *or* default-on for the boldest auto-detect experience?
5. **Resume point.** Re-plan a revised M1 and resume building now (PR-A facade is zero-risk and unblocked regardless)
   — *or* hold for your review of this spec first?

---

## 11. Provenance
7-dimension exploration (pluggable transport abstraction · onboarding auto-detect · declarative definitions ·
transport-selection dispatch · WinRM/Windows feasibility with live web research · reporting transparency · upstream
roadmap), grounded in source with file:line citations. Raw findings: `/tmp/lnms_vision.md`. Foundation already
committed on `nxapi-vrf`: `\ApiQuery` transport (`ApiQueryInterface`/`HttpApiQuery`/`ApiResponse`) + facade + tests.
