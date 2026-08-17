# Changelog

## 0.1.5 — 2026-08-17
- Lockstep-Versionsschub auf 0.1.5 (keine funktionale Änderung; kann nun `enrol_flexaccess\api::get_effective_policy` konsumieren).

## 0.1.4 — 2026-08-17
- Lockstep-Versionsschub auf 0.1.4 (keine funktionale Änderung).

## 0.1.3 — 2026-08-17
- Lockstep-Versionsschub auf 0.1.3 (keine funktionale Änderung; Follow-up-Funnel A-1 folgt).

## 0.1.2 — 2026-08-17
- Scope-Erweiterung (Planung/Doku): **Follow-up-Persistierungsmails** als Kernfunktion des Temporary→Persistent-Funnels aufgenommen (ADR-013); Post-Registration-Hook für spätere Cohort-Zuweisung vorgesehen (ADR-015).

## 0.1.1 — 2026-08-17
- Version scheme moved to incremental `0.1.x` (release `0.1.1`).
- **Declared a hard dependency on `enrol_flexaccess`** (access-method policy lives in enrol); this establishes the accepted `auth ↔ enrol` cycle supported by Moodle. Facade calls remain runtime-lazy; per-course fallback to normal login is unchanged.
- Added `$plugin->supported = [405, 502]`.

## 0.1.0-alpha — 2026-08-17
- Initial architecture scaffold.

## 0.1.0-alpha3 - 2026-08-17
- Add system/course shared access-key requirement for temporary-user entry; secrets are hash-only.
