# Changelog

## 0.1.1 — 2026-08-17
- Version scheme moved to incremental `0.1.x` (release `0.1.1`).
- **Declared a hard dependency on `enrol_flexaccess`** (access-method policy lives in enrol); this establishes the accepted `auth ↔ enrol` cycle supported by Moodle. Facade calls remain runtime-lazy; per-course fallback to normal login is unchanged.
- Added `$plugin->supported = [405, 502]`.

## 0.1.0-alpha — 2026-08-17
- Initial architecture scaffold.

## 0.1.0-alpha3 - 2026-08-17
- Add system/course shared access-key requirement for temporary-user entry; secrets are hash-only.
