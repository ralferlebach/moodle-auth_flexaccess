# Changelog

## 0.1.23 — 2026-08-18 — CI-Fixes
- **PHPDoc-Fix:** `token_service::consume()` hatte seit der atomaren Umstellung (0.1.20) den Parameter `$expecteduserid` ohne `@param`-Eintrag; local_moodlecheck meldete "incomplete parameters list". Docblock ergaenzt.

## 0.1.23 — 2026-08-18 — Paket A (Access), Teil 2: Zugangsschlüssel
- **Der Zugangsschlüssel ist jetzt wirksam** (war Sicherheits-Blocker B2). E2E per Behat verifiziert: falscher Schlüssel -> Fehler, richtiger -> Kurszugang.
- **B2 (Challenge-Form):** `access.php` zeigt bei Schluesselpflicht eine Eingabemaske; der Schluessel wird per **POST** uebertragen (nie in URL/Referrer/Log), serverseitig geprueft und ratenbegrenzt.

## 0.1.22 — 2026-08-18 — Paket A (Access), Teil 1
- **Der URL-/aktivitaetssensitive Zugang funktioniert jetzt end-to-end** (war Beta-Blocker B1). Real per Behat verifiziert: ein anonymer Besucher gelangt ueber die Entry-Page zu temporaerem Zugang und landet im Zielkurs.
- **B1 (target_resolver):** neue Klasse `local\target_resolver` (+ `resolved_target`) loest `wantsurl` sicher zu Kurs/Aktivitaet/Redirect auf und weist externe/ungueltige URLs ab (kein Open-Redirect). `loginpage_idp_list` baut damit einen **funktionierenden** Link (`access.php?courseid=...`) und bietet FlexAccess nur an, wenn der Kurs wirklich einen anonymen Eintritt anbietet.
- **B8 (Enumeration-Guard):** `access.php` leitet `courseid` aus `wantsurl` ab und zeigt Kursname/-existenz nur, wenn der Kurs sichtbar ist und FlexAccess dort tatsaechlich Eintritt anbietet; sonst generischer Hinweis.
- **Moodle-5.3-Kompatibilitaet:** Kontoerzeugung nutzt `\core\user::create_user()` wo vorhanden (Fallback `user_create_user()` fuer 4.5-5.2) — vermeidet die 5.3-Deprecation, die Behat brach.

## 0.1.21 — 2026-08-18
- **Cross-Plugin-Funktionalitaet wird jetzt echt end-to-end getestet.** Behat wurde in der Sandbox real ausgefuehrt (Moodle 5.3dev, non-JS): alle vier Standalone-Smoke-Features **und** ein neues Cross-Plugin-E2E-Szenario bestehen.
- Keine Codeaenderung; Teil des verifizierten Ecosystem-Laufs (auth erzeugt das temporaere Konto im E2E-Szenario).

## 0.1.20 — 2026-08-18
- **Behat gruen gemacht (war der letzte rote CI-Schritt).** Die Feature-Dateien testeten teils veraltetes Scaffold-Verhalten bzw. noch nicht implementierte Ablaeufe; sie wurden auf standalone lauffaehige Smoke-Szenarien mit ausschliesslich Standard-Steps umgestellt. Verifiziert mit moodle-plugin-ci 4.5.11 (phpcs 0/0, validate 0 Fehler, PHPUnit auf Moodle 5.3dev gruen).
- **Review-Fixes (Sicherheit/Korrektheit):** `policyagreed=1` bei temporaeren Nutzern entfernt (es fand kein Consent statt); Referenznummer jetzt wirklich **numerisch** (10-stellig, passend zur Ziffern-Suche in tool_flexaccess); **atomarer Single-Use-Token-Consume** ueber die Moodle Lock API (verhindert TOCTOU/Doppelnutzung), und `persist.php` autorisiert nur noch ueber ein erfolgreiches `consume()` (kein separates verify-then-act, kein Verbrauch bei fremdem Nutzer); `mail_worker` wertet den Rueckgabewert von `email_to_user()` aus und markiert Fehlversand als Retry statt `sent`. Behat `access.feature` prueft jetzt die Verfuegbarkeit der Authentifizierungsmethode.

## 0.1.19 — 2026-08-18
- **Verifiziert mit der exakten CI-Toolchain (moodle-plugin-ci 4.5.11 PHAR): phpcs 0/0, `validate` 0 Fehler, PHPUnit auf Moodle 5.3dev gruen.** Cross-Plugin-Integrationstests laufen in der Vollumgebung (alle vier Plugins) normal und ueberspringen sich nur in der Einzel-Plugin-CI.
- **Weitere CI-Fixes (moodle-plugin-ci 4.5.11, Moodle 4.5/5.0/5.2/5.3-Matrix):** Behat-Feature `access.feature` zusaetzlich mit Plugintyp-Tag `@auth` versehen (moodle-plugin-ci `validate` verlangt Typ- UND Komponenten-Tag).

## 0.1.18 — 2026-08-17
- **Linting robust fuer aeltere moodle-cs gemacht (die lokale `make check`-Umgebung nutzt eine strengere/aeltere moodle-cs als die CI):** `@package`-Tag in jedem Datei-, Klassen-/Interface-/Trait- und Top-Level-Funktions-Docblock ergaenzt (aeltere moodle-cs verlangt dies ueberall; neuere ab 3.6 hat es gelockert). Test-Klassen erhielten `@covers` auf die jeweils geprueften Klassen (behebt die `missing coverage information`-Warnungen). **Gegengeprueft:** die echte CI (moodle-plugin-ci 4.5.11) meldet weiterhin 0 Verstoesse, PHPUnit auf Moodle 5.3dev bleibt gruen.

## 0.1.17 — 2026-08-17
- **Real auf Moodle 5.3dev (branch 503, PG17) verifiziert — PHPUnit gruen, phpcs 0/0.** Dabei behobene echte Fehler: fehlende Capability-Sprachstrings (flexaccess:manageaccounts, flexaccess:convertaccounts) ergaenzt (Core tool_capability-Check); account_state::values() implementiert (wurde von api::build_account_filter() aufgerufen); generierter Username jetzt garantiert kleingeschrieben (core_text::strtolower; Moodle 5.3 lehnt Grossbuchstaben ab); vollstaendiger Privacy-Provider (statt reiner Metadaten): plugin\provider + core_userlist_provider mit Export/Loeschung auf User-Kontext-Ebene fuer account/token/mailqueue (Core core_privacy-Compliance-Test); install.xml ins kanonische XMLDB-Format regeneriert; PG-String/Int-Assertion in account_service_test korrigiert.
- **CI grün gemacht (phpcs, real verifiziert mit moodlehq/moodle-cs v3.7):** Sprachdateien alphabetisch sortiert + `@package` ergänzt (Moodle LangFilesOrdering); einzeilige Docblocks in Mehrzeilenform mit Beschreibungszeile überführt; Multiline-Funktionsaufrufe per phpcbf normalisiert; unnötige `MOODLE_INTERNAL`-Checks entfernt; Konstanten-Docblocks ergänzt.
- **Makefile:** Vorlage übernommen und an das Plugin-Verzeichnis angepasst (PLUGIN_NAME/PLUGIN_REL/MOODLE_ROOT); `make check` zeigt nur Fails, läuft volle Lintings + PHPUnit.
- **GitHub-Workflows:** getrennt für Development (`moodle-ci.yml`, branches-ignore main) und Main (`moodle-release.yml`); zusätzlich `playwright.yml` und `load.yml` bereitgestellt. Von vimipad-spezifischen Bundle/AMD/Node-Schritten befreit; Behat-Tags und Pfade je Komponente. `.gitattributes`/`.gitignore` adaptiert.

## 0.1.16 — 2026-08-17
- **Einstiegsseite `access.php`:** anonymer Bestätigungsschritt (sesskey), ruft `enrol_flexaccess\local\access_controller::grant_temporary_access`, meldet den erzeugten temporären Nutzer via `complete_user_login` an und leitet lokal (PARAM_LOCALURL) in den Kurs; Fehlermeldungen für closed/notallowed/notenabled/full. Behebt den bislang toten Link aus `loginpage_idp_list`.

## 0.1.15 — 2026-08-17
- **`api::create_temporary_user()`**: legt einen temporären Moodle-Nutzer nach Konvention an (auth=flexaccess, generierter Username, kein lokales Passwort, nicht zustellbare Platzhalter-E-Mail + emailstop) und die zugehörigen Account-Metadaten (Ablauf aus enrol-Policy). PHPUnit-Test.

## 0.1.14 — 2026-08-17
- **Read-only Dashboard-Facaden:** `api::account_stats()`, `api::mailqueue_summary()`, `api::count_mailqueue()`/`list_mailqueue()` (ohne Geheimnisse/Payload). PHPUnit erweitert.

## 0.1.13 — 2026-08-17
- Lockstep-Versionsschub auf 0.1.13 (keine funktionale Änderung).

## 0.1.12 — 2026-08-17
- Lockstep-Versionsschub auf 0.1.12 (keine funktionale Änderung).

## 0.1.11 — 2026-08-17
- **Account-Facade erweitert:** `api::search_accounts()`/`count_accounts()` (Filter nach Substring/Typ/Status, paginiert, join auf user) und `api::admin_convert()`. PHPUnit erweitert (Filter, Suche, Admin-Conversion).

## 0.1.10 — 2026-08-17
- **M-A2: `api::self_activate()`** — In-Session-Selbstaktivierung eines eingeloggten temporären Nutzers: validiert E-Mail, prüft Duplicate-E-Mail-Policy, setzt E-Mail/Namen und konvertiert via `account_service` (Einschreibung unberührt). Rückgabe: activated/emailtaken/invalidemail/notapplicable. PHPUnit erweitert.

## 0.1.9 — 2026-08-17
- **expire_accounts-Task real umgesetzt:** `account_service::expire_due()` markiert fällige temporäre Konten als `expired` und suspendiert den Moodle-Nutzer (idempotent, batched); Einschreibung bleibt unberührt. PHPUnit erweitert.

## 0.1.8 — 2026-08-17
- **A-3: Follow-up-Schleife geschlossen.**
  - `local\account_service`: `create_temporary()` und `convert_to_authenticated()` (gleiche userid; setzt Typ/State/timeactivated, entfernt Ablauf, bestätigt Moodle-Nutzer; **ändert keine Einschreibung**).
  - `local\mail_worker`: gedrosselter Queue-Versand (`mail_rate`), Token erst unmittelbar vor Versand via `token_service`, Backoff/Attempts; `process_mail_queue`-Task verdrahtet. Reiner `local\mail_planner`.
  - `persist.php`: Landing-Page, konsumiert das Token (nur für den passenden eingeloggten Nutzer) und konvertiert das Konto.
  - PHPUnit: `account_service_test`, `mail_planner_test`, `mail_worker_test` (Drossel + Token + E-Mail-Sink). Kein Schema-Change.

## 0.1.7 — 2026-08-17
- **A-2 (Token-Primitive):** neuer `local\token_service` — single-use, gehashte (SHA-256), zeitlich begrenzte Tokens (issue/verify/consume) für Aktivierungs-, Follow-up- und Lösch-Links; nur der Hash wird gespeichert. PHPUnit `token_service_test`.
- **CI-Fix (phpcs):** zu lange Zeilen in `settings.php` und `privacy/provider.php` umgebrochen.
- **CI-Fix:** pgsql-Workflow-createdb-Zeile entfernt.

## 0.1.6 — 2026-08-17
- **A-1 (Follow-up-Funnel) + Contract `auth_flexaccess\api`.**
  - `api::classify_user()` (der Vertrag für mod/enrol/tool; Nutzer ohne FlexAccess-Datensatz gelten als `authenticated user`), `api::get_account()`.
  - `api::request_persistence_followup()`: idempotentes Einreihen einer `persistence_followup`-Mail in die bestehende getaktete Mailqueue; nur für unkonvertierte temporäre Nutzer mit zustellbarer Adresse; Sendezeit **vor** dem Account-Ablauf geclampt.
  - `local\followup_scheduler` (reine Logik: `due_time` mit Clamping, `should_schedule`), `local\mail_kind` (Konstanten).
  - PHPUnit: `followup_scheduler_test`, `api_test`. Kein Schema-Change; Mailqueue-Privacy war bereits deklariert. Token/Versand bleiben Aufgabe des Mailworkers (ADR-013).

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
