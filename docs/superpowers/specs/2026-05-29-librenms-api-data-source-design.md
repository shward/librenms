# LibreNMS API Data-Source Integration — Design Proposal

**Date:** 2026-05-29
**Author:** Josh Thomas-Ward (with Claude Code)
**Status:** Discovery complete — awaiting decisions (see §9)
**Repo:** fork of LibreNMS (`github.com/shward/librenms`), branch context `nxos-vrf-discovery`

---

## 1. Goal

Let LibreNMS collect device data from **HTTP/REST APIs** (Cisco NX-API, RESTCONF, vendor JSON)
alongside SNMP, so that everything still "comes down to one place." Three product goals:

1. **Credentials** — store per-device API credentials the way SNMPv3 credentials are stored.
2. **API fallback** — a data-source abstraction where an OS/module can say *"this value isn't in SNMP —
   fetch it from this API path"* and have the result flow through the normal discovery → poll → store →
   graph/display pipeline, indistinguishable from an SNMP-derived value.
3. **Widgets** — device screens become composable widgets rather than fixed, hard-coded tabs.

**Driving example:** plain Cisco NX-OS switches (non-VTEP, no BGP-per-VRF — e.g. `dv-int-pol-sw1/2`)
expose **only the default VRF** over SNMP. Non-default VRFs are visible **only via NX-API**. This is the
concrete coverage gap that motivates the whole feature.

---

## 2. The single most important finding

**LibreNMS's data pipeline is already transport-agnostic from the storage layer up.** This is what makes
the feature tractable rather than a rewrite:

- `app('Datastore')->put($device, $measurement, $tags, $fields)` (`LibreNMS/Data/Store/Datastore.php:113`)
  fans any value out to **every** enabled backend (RRD/InfluxDB/Prometheus/Graphite/OpenTSDB/Kafka). It does
  not know or care whether the bytes came from SNMP.
- `SyncsModels::syncModels()` (`LibreNMS/DB/SyncsModels.php:45`) diff-persists any Eloquent collection; it
  does not care how the models were populated.
- The dashboard widget engine and device tabs render whatever an Eloquent model / the Component store holds.

So "API as a data source" is **not** a pipeline rewrite. It is: **add one new fetch path** (an `ApiQuery`
parallel to `SnmpQuery`), **add one credential store**, then let the existing rails carry the data. The
`LibreNMS/Data/Source/` directory already hosts non-SNMP peers (`Fping.php` for ICMP, `Ipmitool.php` for
IPMI), so a new HTTP source there fits the grain of the codebase.

The interesting decisions are therefore narrow and specific (see §9), not architectural.

---

## 3. Key seams (verified, with file:line)

| Concern | Seam to extend | Anchor |
|---|---|---|
| SNMP credentials (the template) | plaintext columns on `devices`, read by `NetSnmpQuery::buildAuth()` | `app/Models/Device.php:48-91`, `NetSnmpQuery.php:341-367` |
| Data-source abstraction | `SnmpQueryInterface` → `NetSnmpQuery` → `SnmpResponse`, via `\SnmpQuery` facade | `LibreNMS/Data/Source/*`, `config/app.php:25` |
| HTTP client (mandated) | `LibreNMS\Util\Http::client()` (proxy + UA); `app/ApiClients/BaseApi.php` wraps it | `LibreNMS/Util/Http.php:38` |
| Discovery/poll dispatch | modern `Module` class → `$os instanceof <Cap>Discovery` → `$os->discoverX()` → `SyncsModels` | `LibreNMS/Modules/Mpls.php`, `Vlans.php` |
| OS specialization | `OS::make()` → per-OS class; nxos currently → `Shared\Cisco` (no `Nxos.php`) | `LibreNMS/OS.php:253-282` |
| Metric storage | `Datastore::put()` with `rrd_def`/`rrd_name` in tags | `LibreNMS/Data/Store/Datastore.php:113` |
| Numeric data-source discriminator | `sensors.poller_type` (snmp/ipmi/agent); composite key includes it | `app/Models/Sensor.php:159`, `includes/polling/functions.inc.php:37` |
| Declarative discovery (code-free) | `YamlDiscovery::preCache()` — the ONE source-blind seam, hardcodes `SnmpQuery::walk` | `LibreNMS/Device/YamlDiscovery.php:388-391` |
| Composable widgets | widget = a `Route::post(...)` under `ajax/dash`; catalog via route reflection | `routes/web.php:388-414`, `DashboardController.php:236-255` |
| Device-scoped widget template | `HealthSensorsController` (device/group/regex scope, device select2) | `app/Http/Controllers/Widgets/HealthSensorsController.php` |
| Plugin injection points | `DeviceOverviewHook` (Overview panel), `PortTabHook` (in-page port container) | `app/Plugins/Hooks/*`, `includes/html/pages/device/overview.inc.php:17` |

**Operational constraint (9-node cluster):** the merged config — including all parsed YAML — is memoized
**per-node** in the file cache key `librenms-config` (`ConfigRepository.php:58`). Anything stored in
config/YAML files needs a cache flush on every node; **per-device DB columns/rows avoid this hazard.**

---

## 4. Three approaches evaluated (judge panel + adversarial critique)

Each was fully designed and then stress-tested by an adversarial reviewer against the real source.

### Approach A — First-class API transport  *(verdict: viable; strongest foundation)*
Add `\ApiQuery` (facade) → `ApiQueryInterface` → `HttpApiQuery` → `ApiResponse` in `LibreNMS/Data/Source/`,
paralleling SNMP so call sites and `mapTable()` are unchanged. Encrypted per-device API credential columns
on `devices`. Promote VRF to a modern module + new `OS/Nxos.php`.
- **Strengths:** most native, most reusable, most upstreamable *in spirit*; the only approach that makes API
  a true first-class transport every future OS/module reuses.
- **Blockers the critic found:** (1) adding `community/authpass/cryptopass` to a global `$hidden` would
  **break all legacy SNMP** (legacy reads `$device['authpass']` via `toArray()`); fix the REST echo at the
  serialization boundary instead. (2) `App\Models\Vrf` lacks `Keyable`/`getCompositeKey()` → `syncModels()`
  fatals. (3) the proposal silently **drops `ports.ifVrf`** port-to-VRF membership. (4) there is **no parent
  SNMP `discoverVrfs()`** — VRF SNMP logic is multi-vendor procedural in `vrf.inc.php`, so "call parent first"
  understates the effort. (5) `cisco-vrf-lite` is also enabled on nxos (overlap). (6) creating `OS/Nxos.php`
  re-routes **all** nxos devices off `Shared\Cisco`. (7) the interface should be **trimmed** — `mibs/numeric/
  translate/context` are meaningless for HTTP.

### Approach B — Declarative API metric/sensor definitions  *(verdict: viable; cheapest for numerics)*
Add a `sensors.poller_type='http'` branch (the existing data-source discriminator) so API numerics reuse
`discover_sensor` → `SyncsModels` → `record_sensor_data` → `Datastore::put` → **auto-graph with zero new
graph code**. Add a `source: api` discriminator to `os_discovery` YAML, branched at the single source-blind
seam `YamlDiscovery::preCache()`.
- **Strengths:** least code; YAML-author-friendly; reuses the richest existing pipeline.
- **Blockers/limits the critic found:** (1) **numeric-only** — sensors cannot hold VRF name/RD/description, so
  it does **not** unify the structured VRF case (and punts goal 3 entirely to C). (2) **TLS blocker** — NX-OS
  self-signed certs vs `Http::client()` verifying by default. (3) the "encrypted `device_attribs` cast" is
  **not possible as a standard cast** (casts key on column name, not on a sibling `attrib_type` value). (4)
  sensor cleanup-by-omission **deletes http sensors on a transient API failure** at discovery time. (5)
  `os_discovery/nxos.yaml` **already exists** (modify, not create). (6) http sensors must be bucketed out
  **before** `bulk_sensor_snmpget()` or their `api_path` gets sent to net-snmp as an OID.

### Approach C — Composable widget + plugin provider  *(verdict: viable; strongest on goal 3)*
Build the integration as a self-contained Plugin v2 package (creds via SettingsHook + device-attribs, fetch
via `Http::client()`, store into `vrfs`, render via widgets + a new `DeviceTabHook`).
- **Strengths:** the device-scoped **widget** half is genuinely strong, low-risk, and upstreamable (verified:
  `DeviceVrfsController` clone of `HealthSensorsController` + one `ajax/dash` route line). Honest that it's
  parallel-to, not unified-with, the SNMP pipeline.
- **Blockers the critic found:** (1) `DeviceTabHook` is **not** a "~30-line parallel to PortTabHook" —
  `PortTabHook` is an in-page container on the Port page, **not** a `PageTabs` participant, and `PageTabs` is
  hard-typed to `DeviceTab`; composable top-level tabs are a real core-contract change. (2) **no plugin
  scheduling entry point exists** — `PluginProvider` globs only `app/Plugins/*/*.php` one level deep and has
  no schedule/queue hook, so the plugin's own collection scheduler needs a core edit anyway. (3) **no plugin
  write-hook for settings** — `PluginSettingsController::update()` writes the JSON column verbatim, so the
  "encrypt the global password before save" guarantee isn't deliverable inside the plugin boundary. (4)
  `PluginManager::call()` **auto-disables the whole plugin** on any thrown error from a hook (fragile for a
  creds UI). (5) same TLS-default and conditional-attrib-cast problems as B.

---

## 5. Recommended architecture — staged hybrid, A as the spine

Take the verified-strong parts of each and avoid every blocker the critics found. **A provides the transport
and the structured-data home; B provides the cheap numeric path; C provides the display layer.** Sequenced so
each phase ships value and the NX-OS VRF case is the first vertical slice that proves the spine.

```
                 ┌─────────────────────────────────────────────────────────┐
   Goal 3 ─────► │ Display: DeviceVrfsController widget (C) + Overview panel │
                 │            [+ composable device tabs, later/optional]     │
                 └───────────────▲─────────────────────────────▲────────────┘
                                 │ reads source-agnostic models │
   Goal 2 ─────► ┌───────────────┴───────────┐   ┌─────────────┴───────────┐
                 │ Structured: vrfs table (A) │   │ Numeric: poller_type=   │
                 │  via Modules/Vrf + SyncsM. │   │  'http' + YAML source:api(B)│
                 └───────────────▲───────────┘   └─────────────▲───────────┘
                                 │                              │
                 ┌───────────────┴──────────────────────────────┴───────────┐
   Spine ──────► │     \ApiQuery  (HttpApiQuery → ApiResponse) on Http::client│  (A)
                 └───────────────▲───────────────────────────────────────────┘
                                 │ reads
   Goal 1 ─────► ┌───────────────┴───────────────────────────────────────────┐
                 │  Encrypted per-device API credential columns on `devices`  │  (A)
                 └────────────────────────────────────────────────────────────┘
```

### Phase 0 — `\ApiQuery` transport *(pure addition, independently mergeable)*
- `LibreNMS/Data/Source/ApiQueryInterface.php` — **trimmed** to HTTP-relevant verbs only:
  `make()`, `device(Device)`, `cache()`, `get(path, query=[])`, `post(path, body=[])`, `cli(command)`.
  No `mibs/numeric/translate/context`.
- `LibreNMS/Data/Source/HttpApiQuery.php` — built on `LibreNMS\Util\Http::client()`; reads creds off the
  Device; **honours a per-device TLS-verify toggle** (`->withoutVerifying()` only when configured); catches
  `ConnectionException` + HTTP ≥ 400 → non-valid `ApiResponse`.
- `LibreNMS/Data/Source/ApiResponse.php` — re-implements only the `SnmpResponse` surface callers use:
  `isValid()`, `getErrorMessage()`, `values()`, `valuesByIndex()`, `mapTable()`. **Crucially distinguishes
  transport/JSON-RPC error (preserve rows) from valid-but-empty (legit empty)** — JSON-RPC returns HTTP 200
  with an error body, so parse the body, not just the status. Parity is proven by fixture tests, not assumed.
- `app/Facades/FacadeAccessorApi.php` + `'ApiQuery' => …` in `config/app.php` (one line beside `SnmpQuery`).
- Tests: `tests/Unit/Data/Source/ApiResponseTest.php` locking the parity contract.

### Phase 1 — Encrypted per-device API credentials *(goal 1)*
- Migration adding nullable columns to `devices`: `api_transport`, `api_host`, `api_port`, `api_username`,
  `api_password` (text), `api_token` (text), `api_verify_tls` (tinyint default 1). Mirror into
  `resources/definitions/schema/db_schema.yaml` (two-source-of-truth rule).
- `Device::$fillable` += the new columns; `Device::casts()` += `api_password=>'encrypted'`,
  `api_token=>'encrypted'`, `api_verify_tls=>'boolean'` (uses the in-repo Laravel `Crypt` machinery already
  proven in `KeyRotate.php:153`).
- **Do NOT add SNMP creds to a global `$hidden`.** Instead fix the REST secret echo (`api_functions.inc.php:483`)
  at the response boundary (`makeHidden()`/explicit exclusion on that payload) and ensure `api_password`/
  `api_token` are excluded there too.
- Credential entry: native Blade section in the device edit Access tab + a legacy bridge in
  `edit/snmp.inc.php`, with masked (`********`) write-only fields. Optional non-fatal reachability probe in
  `ValidateDeviceAndCreate`.
- **Operational note:** `APP_KEY` must be byte-identical across all 9 cluster nodes or remote pollers can't
  decrypt; `KeyRotate` must cover the new columns.

### Phase 2 — NX-OS VRF vertical slice *(goal 2, structured — proves the spine)*
- Add `Keyable` + `getCompositeKey()` to `App\Models\Vrf` (keyed on `vrf_oid`) so `SyncsModels` works.
- In the **NX-OS branch** of `includes/discovery/vrf.inc.php`: when the existing SNMP path (NV-OVERLAY +
  BGP4) returns empty **and** API creds are present, fall back to
  `\ApiQuery::device(...)->cli('show vrf')`, parse name/RD/description, persist via the existing Eloquent
  `vrfs()` path, **preserve `ports.ifVrf`** mapping, and keep the transient-vs-empty guard so a transport
  blip never deletes good rows.
- **Gate the fallback on creds-present** so existing nxos users keep the SNMP heuristic untouched.
- Handle the SNMP-up gate (an API-only / SNMP-degraded NX-OS device must still discover VRFs).
- *(Deferred, larger:* full promotion to a modern `LibreNMS/Modules/Vrf.php` + `LibreNMS/OS/Nxos.php`, which
  must also reconcile `cisco-vrf-lite` and re-home multi-vendor SNMP VRF logic. Not required for the slice.)*

### Phase 3 — Composable display *(goal 3, low-risk part)*
- `app/Http/Controllers/Widgets/DeviceVrfsController.php` — clone of `HealthSensorsController`
  (device/group/regex scope, device select2), querying `$device->vrfs()`. One `Route::post('device-vrfs', …)`
  under `ajax/dash` + a `lang/en/widgets.php` entry + two Blades. **Source-agnostic** — renders SNMP- and
  API-populated VRFs identically. Upstreamable as-is.
- Optionally a `DeviceOverviewHook` panel (verified injection point) showing VRF count / last source.

### Phase 4 — Declarative numeric metrics *(goal 2, numeric — separate track)*
For numeric API telemetry that should graph (per-VRF route counts, interface counters NX-API exposes that
SNMP doesn't):
- `poller_type='http'` branch in `poll_sensor()` — **bucket http sensors before `bulk_sensor_snmpget()`**;
  batch one fetch per (device, api_path); feed `record_sensor_data()` unchanged.
- poller-type-aware discovery cleanup that **does not wipe http sensors on a transient API failure**.
- `source: api` / `api_path` discriminator in `discovery_schema.json` + the `os_discovery/nxos.yaml` (modify,
  it exists), branched at `YamlDiscovery::preCache()` (cover **both** orchestrators), normalizing JSON into
  the same `$pre_cache[oid][index][column]` shape so everything downstream is untouched.

### Phase 5 — Composable device tabs *(goal 3, larger/optional)*
Add a real `DeviceTabHook` — acknowledging this is a genuine core-contract change (widen `PageTabs`'
`DeviceTab` typing / add a `DeviceController` render branch), **not** a trivial `PortTabHook` parallel.

---

## 6. Worked example — VRFs on `dv-int-pol-sw1` (NX-OS, SNMP-blind)

1. **Creds:** operator sets API transport=`nxapi`, host, port=443, basic auth, `api_verify_tls=0` (self-signed)
   on the device Access tab. `Device->save()` encrypts `api_password` via the `encrypted` cast.
2. **Discovery:** the existing `vrf` module runs (enabled for nxos via `os_detection/nxos.yaml:26`). The NX-OS
   branch runs its SNMP NV-OVERLAY/BGP4 walk → returns only `default` (or nothing).
3. **Fallback (creds present):** `\ApiQuery::device($device)->cli('show vrf')` → `FacadeAccessorApi` →
   fresh `HttpApiQuery` → `Http::client()->withBasicAuth(...)->withoutVerifying()->post('https://…/ins', JSON-RPC)`.
4. **Normalize:** `ApiResponse` wraps the `TABLE_vrf/ROW_vrf` rows; `isValid()` true on HTTP 200 + parseable body.
5. **Persist:** parse name/RD/description → `Device->vrfs()->updateOrCreate(['vrf_oid'=>$name], [...])`; set
   `ports.ifVrf`; prune unseen rows **only** on a clean fetch (transient error preserves rows).
6. **Display:** the Routing tab and a `device-vrfs` dashboard widget show all VRFs — source-agnostic.

---

## 7. Why this beats each pure approach
- vs **pure A:** keeps A's transport+creds spine but removes its blockers (no global `$hidden`; `Keyable`
  added; `ifVrf` preserved; honest about VRF-module promotion effort; trimmed interface) and borrows C's
  widget for goal 3 (A's weakest goal).
- vs **pure B:** B is numeric-only and can't carry VRF identity; the hybrid uses B exactly where it shines
  (numeric metrics, code-free) and uses A's structured path for VRFs.
- vs **pure C:** C can't unify with the poll pipeline and has no plugin scheduling/settings-encryption hook;
  the hybrid drives collection through the normal dispatcher (the spine) and keeps only C's verified-strong,
  upstreamable widget.

---

## 8. Risks & mitigations (distilled from the adversarial pass)
| Risk | Mitigation |
|---|---|
| TLS: NX-OS self-signed certs vs verify-by-default | per-device `api_verify_tls` column, default secure; `->withoutVerifying()` only when off |
| Transient API error wipes good rows (VRF + sensors) | `ApiResponse` distinguishes transport/JSON-RPC error from empty; preserve-on-error in both VRF sync and sensor cleanup |
| `$hidden` breaking legacy SNMP | never add SNMP creds to global `$hidden`; fix REST echo at the response boundary |
| `syncModels` fatal | add `Keyable`/`getCompositeKey()` to `Vrf` |
| Dropping `ports.ifVrf` | port-to-VRF mapping is part of the slice's persist step |
| `OS/Nxos.php` re-routing all nxos devices | deferred; the slice stays in `vrf.inc.php`; if promoted, `Nxos extends Shared\Cisco` and adds only capabilities |
| APP_KEY divergence across 9 nodes | document hard requirement; exercise `KeyRotate` for new columns |
| Per-node config-cache for YAML edits | prefer per-device DB columns for creds; flush all nodes on YAML/schema/config edits |
| Blocking HTTP during discovery/poll | reuse `$device->timeout/retries`; non-fatal; never stall the poll wheel |
| `cisco-vrf-lite` overlap on nxos | reconcile before/with full `Modules/Vrf` promotion (Phase 2-deferred) |

---

## 9. Decisions — LOCKED 2026-05-29

1. **Upstream intent → keep it upstream-mergeable.** Design as clean, minimal, convention-following PRs.
   Vendor specifics (NX-API command strings, NV-OVERLAY heuristics) stay fork-local.
2. **Credential encryption → encrypt at rest.** Laravel `encrypted` cast on `api_password`/`api_token`.
   Hard requirement: identical `APP_KEY` on all 9 cluster nodes; `KeyRotate` must cover the new columns.
3. **First milestone → NX-OS VRF vertical slice first.** Minimal cut of Phases 0 + 1 + 2: `\ApiQuery`
   transport + encrypted creds + the NX-OS VRF NX-API fallback in `vrf.inc.php`.
4. **Goal-3 depth → widgets + Overview panel only.** Build the `DeviceVrfsController` dashboard widget
   (+ optional `DeviceOverviewHook` panel). **Defer** the `DeviceTabHook` / composable-tabs core change (Phase 5).

### Locked scope for Milestone 1 (the NX-OS VRF vertical slice)
- **Phase 0** — `\ApiQuery` transport (trimmed interface: `get`/`post`/`cli` + `device`/`cache`), `HttpApiQuery`
  on `Http::client()` with per-device TLS-verify toggle, `ApiResponse` (transport/JSON-RPC-error vs empty),
  facade + alias, parity tests. *Independently mergeable; no behavior change.*
- **Phase 1** — encrypted per-device API credential columns on `devices` + `db_schema.yaml` + `Device`
  `$fillable`/`casts` + Access-tab UI (masked/write-only) + REST-echo fix at the response boundary (NOT global
  `$hidden`) + optional non-fatal probe in `ValidateDeviceAndCreate`.
- **Phase 2** — `Keyable` on `App\Models\Vrf`; NX-OS branch of `vrf.inc.php` falls back to
  `\ApiQuery->cli('show vrf')` when SNMP is empty **and** creds present; preserve `ports.ifVrf`; keep the
  transient-vs-empty guard; handle the SNMP-up gate.
- **Phase 3** — `DeviceVrfsController` widget (clone of `HealthSensorsController`) + one `ajax/dash` route +
  lang entry + Blades; optional `DeviceOverviewHook` panel.
- **Out of scope for M1 (deferred):** numeric `poller_type='http'` + YAML `source: api` (Phase 4); full
  modern `Modules/Vrf.php` + `OS/Nxos.php` promotion and `cisco-vrf-lite` reconciliation; `DeviceTabHook` (Phase 5);
  fork-first special-casing.

---

## 10. Provenance
Produced from a 12-agent codebase discovery sweep (every relevant subsystem deep-read with file:line
citations) followed by a 3-architecture judge panel with adversarial critique. Raw findings:
`/tmp/lnms_findings.md`; architecture digest: `/tmp/lnms_design.md`; architecture brief: `/tmp/lnms_arch_brief.md`.
