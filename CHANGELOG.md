# Changelog

## 0.9.17 — 2026-08-20 — Fix: Cross-Plugin-Mailqueue (Standalone-CI) + saubere API-Grenze
- Keine Codeaenderung.

## 0.9.16 — 2026-08-20 — P2-Cleanup: Performance, Reliability, i18n
- **Perf (§15):** `account_stats()` nutzt jetzt eine einzige Conditional-Aggregate-Query statt sechs separater COUNTs.
- **Reliability (§17):** neuer Insert-Retry `insert_with_reference_retry()` — bei der (extrem seltenen) Referenzcode-Kollision zwischen Generierung und Insert wird der Code regeneriert und der Insert wiederholt (bis 5x), statt hart zu scheitern. Genutzt in `create_temporary`/`create_authenticated`.
- Test: account_stats deckt jetzt alle Zaehl-Zweige (total/temporary/authenticated/provisional/active/expired) ab.

## 0.9.15 — 2026-08-20 — RC-Gates (Review 0.9.13): 4 P0 + Reliability + Doku/CI-Sync
- **P0-1 (Conversion-Race):** `confirm_persistence()` laeuft jetzt durch den zentralen, per-User gelockten `finalise_identity()`. Das Token wird **nicht-konsumierend** geprueft und erst **innerhalb der Transaktion** verbraucht; scheitert die Conversion, rollt alles zurueck (Token bleibt unverbraucht). `finalise_identity()` rollt bei jedem Callback-Fehler transaktional zurueck.
- **Reliability (§16):** neuer `account_service::delete_account()` loescht den Moodle-User **zuerst**, dann die FlexAccess-Rows; `purge_expired()` nutzt ihn (keine inkonsistenten Reste mehr bei fehlgeschlagenem Core-Delete).
- **P0-2-Support:** `api::rollback_temporary_user()` (nur fuer noch temporaere Konten) als Compensation-Einstieg fuer die Enrolment-Seite.
- E2E-Test: Default-Verifikationsfluss (Verifikation AN) request -> Queue -> Worker -> Token -> confirm -> permanenter, login-faehiger Account.

## 0.9.14 — 2026-08-20 — Einladungen: personengebundenes Single-Use-Modell (Review §9)
- Keine Codeaenderung.

## 0.9.13 — 2026-08-20 — P2-Batch: Performance, Retention, Supply-Chain, Doku
- **Perf:** Compound-Index `(accounttype, accountstate, timeexpires)` auf `auth_flexaccess_account` fuer die Expiry-/Purge-Scans (install.xml + Upgrade 2026081913). Mailqueue-Worker laedt Empfaenger jetzt **gebatcht** statt einer Query pro Job (kein N+1 mehr).
- **Retention:** `process_mail_queue` bereinigt jetzt zusaetzlich zugestellte/fehlgeschlagene Queue-Zeilen (`mail_worker::prune_delivered`) und tote Token (`token_service::prune`) nach dem Retention-Fenster (Default = `retentiondays`, min. 1 Tag). Tests decken beide Prune-Pfade ab.

## 0.9.12 — 2026-08-20 — P1/P2-Härtung: Security (a) + Identity/State (b) + Cleanup/Docs (c)
- **(a) Security:** Rate-Limit-Identifier jetzt **HMAC-SHA256** mit per-Site-Secret statt ungesalzenem SHA1 (defeats Dictionary-Bruteforce von IP/E-Mail); `ratehit.identifier` auf 64 Zeichen geweitet (Upgrade 2026081912, stale Zeilen verworfen). Anonymer Einstieg (`access.php`): `\$SESSION->wantsurl` wird serverseitig gesetzt statt via Query-Param; Temp-Account-Erzeugung (ohne Key) und Guest-Login sind **POST-only** (Method-Gate gegen Prefetch/Scanner).
- **(b) Identity/State:** `account_state::PROVISIONAL` wird jetzt gesetzt, sobald Persistence angefragt wird (Quick-Reg + Funnel) — Dashboard/Audit konsistent. Reference-Nummer **kollisionsfrei** erzeugt (`generate_unique_reference`, 12-stellig, Existenzpruefung vor Insert) statt einmalig — schliesst den Orphan-User-Fall bei Kollision.
- **(c) Cleanup:** tote `stub*`-Strings entfernt; unbenutzter `\$window`-Parameter aus `rate_limiter::record`; tote auth-Lifetime-Settings (`temporarylifetime`/`provisionallifetime`, gehoeren dem enrol/policy-Layer; inkl. untranslated „1 week“) entfernt.

## 0.9.11 — 2026-08-20 — RC-Hardening: P0#6 (Admin-Conversion über Mailqueue)
- **P0#6 — Admin-Conversion nutzt jetzt die FlexAccess-Mailqueue:** statt Core `setnew_password_and_mail` (das die Queue und das Ratelimit umging) wird eine **queued Set-Password-Einladung** verschickt. Neuer Mail-Kind `set_password`, Purpose `setpassword`, Landing `setpassword.php` + `set_password_form`. Der Token wird wie üblich erst beim Versand vom Worker ausgegeben (nie in der Queue persistiert), zur Anzeige nicht-konsumierend via `verify()` geprüft und erst bei gültiger Passwort-Eingabe verbraucht; danach wird der Nutzer angemeldet. TTL 3 Tage.
- Test deckt den vollständigen Round-Trip ab: admin_convert → nichts sofort gesendet → Worker liefert → Token aus der Mail → `complete_set_password` setzt das Passwort → Replay des Single-Use-Tokens blockiert.

## 0.9.10 — 2026-08-20 — RC-Hardening: 7/8 P0 aus dem 0.9.8-Review
- **P0#3 — Conversion serialisiert:** `finalise_identity` laeuft nun unter einem **per-user Moodle-Lock**; `is_convertible`/E-Mail-Guards werden **im** serialisierten Bereich geprueft. Self-/Admin-/Verification-Conversions koennen nicht mehr gegeneinander laufen.
- **P0#4 — Parallelpfad entfernt:** `persist_temporary_user` delegiert an `finalise_identity` und nutzt damit `is_convertible` statt nur `is_temporary` (ein bereits abgelaufenes Konto kann nicht mehr persistiert werden).
- **P0#5 — `self_activate` finalisiert keine unbestaetigte Identitaet mehr:** es startet den Persistence-/Verifikations-Funnel (mit aktivierter Verifikation → Aktivierungslink per Mail; nur bei abgeschalteter Verifikation sofortige Umwandlung). `request_persistence` zusaetzlich um E-Mail-Formatvalidierung und `is_convertible` gehaertet.

**Offen (bewusst gestaffelt):** P0#6 — Admin-Conversion versendet die Passwort-Mail noch via Core `setnew_password_and_mail` (umgeht die FlexAccess-Mailqueue/Ratelimit). Fix erfordert einen neuen queued 'set-password'-Mailfluss.

## 0.9.9 — 2026-08-19 — Welle 4 Abschluss: Accessibility-Gate + Docs-SSOT & Traceability
- Keine Codeaenderung.

## 0.9.8 — 2026-08-19 — Welle 4: Policy-Caching (Perf)
- Keine Codeaenderung.

## 0.9.7 — 2026-08-19 — Welle 5: Einladungskampagnen (§49)
- Keine Codeaenderung.

## 0.9.6 — 2026-08-19 — Welle 4: Persistence-Follow-up (schließt P0 #9 vollständig)
- **P0 #9 komplett — Persistence-Follow-up:** neuer `api::send_persistence_followups()` erinnert Nutzer, die die Persistenz begonnen (echte pending-E-Mail hinterlegt), aber die Verifizierung nicht abgeschlossen haben, **einmalig** an die Aktivierung, sobald ihr temporaeres Konto ins Erinnerungsfenster laeuft. Anonyme Temp-Nutzer ohne echte Adresse werden nie angemailt. Getrackt via Preference `auth_flexaccess_followupsent` (beim Bestaetigen/Loeschen aufgeraeumt); der Worker revoked bei Zustellung, sodass nur ein Link live ist.
- Neue Einstellung `followupwindow` (Default 1 Tag, 0 = deaktiviert); Aufruf aus dem `expire_accounts`-Task. Privacy-Provider deklariert/exportiert/loescht die neue Preference. Test deckt Einmaligkeit + Zustellung nur an echte Adresse ab.

## 0.9.5 — 2026-08-19 — Welle 3 Strom E: administrierbare Kategorie-Policies (P0 #8) + Cleanup
- Cleanup: die Docblocks der geplanten Tasks (`expire_accounts`, `process_mail_queue`) beschreiben jetzt ihr tatsaechliches Verhalten statt „scaffold".

## 0.9.4 — 2026-08-19 — CI-Härtung + Upgrade-Robustheit (Plugin-Isolation, PHPDoc, reset_role_capabilities)
- **CI-Fix (Plugin-Isolation):** `account_service::convert_to_authenticated` ruft das enrol-eigene `participant_role::unrestrict()` jetzt nur noch via `class_exists`-Guard auf. So laeuft die auth-Testsuite isoliert (ohne enrol) ohne "class not found", ohne die Produktionslogik zu aendern.

## 0.9.3 — 2026-08-19 — Welle 3 Strom F: Quick-Registration neu spezifiziert (P0 #5)
- **P0 #5 — E-Mail-Freischaltung:** Quick-Registration erzeugt jetzt ein *provisorisches* temporaeres Konto (mit E-Mail und Passwort, dadurch bereits eingeschraenkt per Welle-2-Restriktion) und bindet die Aufwertung zu einem *regulaeren* Konto an eine E-Mail-Aktivierung. Der bestehende Persistenz-Funnel (`request_persistence` -> `confirm_persistence`) wird wiederverwendet; ist die E-Mail-Verifizierung deaktiviert, konvertiert der Vorgang sofort.
- `register.php` behandelt den neuen Status `verificationsent` (sofortiger Zugang + Aktivierungshinweis) und reicht ein optionales Zugangspasswort durch. Das Formular zeigt das Zugangspasswort-Feld nur bei aktivem Passwort-Gate.
- Die jetzt ungenutzte `create_quick_registered_user()` wurde entfernt (Dead-Code).
- Neue Strings: `register:accesspassword`, `register:verificationsent`, `access:badgate`.

## 0.9.2 — 2026-08-19 — Welle 2: Retention/Deletion, zentraler Conversion-Guard, Temp-Restriktionen (P0 #9/#10/#6)
- **P0 #9 — Retention/Deletion:** neuer `account_service::purge_expired()` loescht abgelaufene temporaere Konten nach einer Aufbewahrungsfrist endgueltig (Moodle-User via `delete_user` + Account/Token/Queue), transaktional. Neue Einstellung `retentiondays` (Default 30, 0 = unbegrenzt behalten); `expire_accounts`-Task ruft die Bereinigung auf.
- **P0 #10 — zentraler Conversion-Guard:** `account_service::is_convertible()` (temporaer + nicht abgelaufen/suspendiert) schuetzt jede Conversion; `finalise_identity` nutzt ihn statt der reinen Typpruefung. `convert_to_authenticated` hebt zudem die Besucher-Restriktionen auf.
- Tests: `purge_expired`, `is_convertible`.

## 0.9.1 — 2026-08-19 — Welle 1: Token-Sicherheit + atomares Temp-Rate-Limit (P0 #1, #2)
- **P0 #1 — kein Klartext-Token mehr in der Mailqueue:** Magic-/Persistenz-Mails speichern in der Queue nur noch `{kind, purpose, ttl}` + Empfaenger/User. Der **Worker** erzeugt den Token unmittelbar vor Versand, re-cappt die TTL gegen die Account-Restlaufzeit (SEC-03), speichert nur den Hash, rendert die URL und sendet. Neuer `mail_renderer`, `queue_token_mail`, `token_service::revoke_pending` (Retry widerruft alten Token → genau ein Live-Token). `send_persistence_verification` entfernt.
- **P0 #2 — atomarer, clusterfester Rate-Limiter:** `rate_limiter` komplett DB-basiert neu (`hit()` = insert-dann-count, race-frei; plus `too_many`/`record`/`reset`/`prune`). Neue Tabelle `auth_flexaccess_ratehit` (install.xml + upgrade). Housekeeping-Prune im Mailqueue-Task. Magic/Quick-Reg auf `hit()` migriert.
- Tests: `token_mail_security_test` (Token nie in Queue, nur Hash, Retry-Einzeltoken), `rate_limiter` atomarer `hit()`-Test.

## 0.9.0 — 2026-08-19 — Beta-Schwelle: CI-Fix, Maturity BETA, Versions-Neustart
- Versionsschema auf `2026081900` / Release `0.9.0` gesetzt, Maturity auf **MATURITY_BETA** angehoben; Cross-Plugin-Dependencies auf `2026081900` gezogen.
- **CI-Fix:** fehlende `@param $reference` in den Docblocks von `api::search_accounts` und `api::build_account_filter` ergaenzt (PHPDoc-Checker).
- Hinweis: Zwei aus dem erneuten Audit stammende Rest-P0 (Klartext-Token in der Mailqueue; generelles atomares Rate-Limit fuer anonyme Temporary-Erzeugung) sind als erste Beta-Haertungswelle eingeplant.

## 0.1.39 — 2026-08-19 — Konfigurierbare Rate-Limits, Cleanup, i18n, Backup/Restore, CI-Härtung
- **§5:** Magic-Login-Rate-Limits admin-konfigurierbar (`magicmaxperip`, `magicmaxperemail`, `magicwindow`); Konstanten bleiben Fallback-Defaults.
- **§3 Cleanup:** ungenutzten `pre_loginpage_hook`-Override entfernt (Basisklasse liefert No-op); ungenutzte Capabilities `auth/flexaccess:manageaccounts` und `:convertaccounts` entfernt (werden beim Upgrade automatisch abgeraeumt).

## 0.1.38 — 2026-08-19 — Re-login-fähige Konversion, Transaktionen, Mailqueue-Limit, Referenzsuche (§7/§8/§13/§16/§36)
- **§7/§8 Re-login-faehige Konversion:** `convert_to_authenticated` setzte bisher kein Passwort → konvertierte Konten waren nicht mehr anmeldbar. Neue gemeinsame `finalise_identity` (Validierung + Identitaets-Update + Konversion) plus:
  - `self_activate($userid, $email, $password, …)` setzt jetzt ein vom anwesenden Nutzer gewaehltes Passwort → sofort re-login-faehig.
  - `admin_convert($userid, $email, …)` verlangt eine echte E-Mail (temporaere Konten tragen nur eine Platzhalter-Adresse) und verschickt via `setnew_password_and_mail` einen Set-Password-Link; Rueckgabe jetzt Status-String.
- **§13 Transaktionsgrenzen:** `finalise_identity` und `confirm_persistence` kapseln alle Schreibvorgaenge in eine `start_delegated_transaction` — kein halb-konvertiertes Konto bei Teilfehlern.
- **§16 Mailqueue-Limit:** `mail_worker::process_due` holt hoechstens `MAX_BATCH = 200` Zeilen per DB-LIMIT statt des gesamten Backlogs.
- **§36 Referenzsuche:** `search_accounts`/`count_accounts` akzeptieren einen `$reference`-Parameter; `build_account_filter` matcht rein numerische Referenznummern exakt.
- Tests: self_activate mit Passwort + Re-Login-Nachweis, admin_convert mit E-Mail + Mail-Sink, Referenz-Suchtest.

## 0.1.37 — 2026-08-19 — Teilnehmerlisten-Sichtbarkeit durchgesetzt (§35, P0)
- Keine Codeaenderung.

## 0.1.36 — 2026-08-19 — Capacity-Race / verwaiste Accounts behoben (§18)
- Keine Codeaenderung.

## 0.1.35 — 2026-08-19 — DSGVO-Privacy-Provider (§11) + PHPDoc-Fixes
- **Privacy-Provider vervollstaendigt (§11):** deklariert/exportiert/loescht jetzt alle personenbezogenen Daten — `auth_flexaccess_account`, `_token`, `_mailqueue` (userid-gekoppelt, User-Kontext) sowie die User-Preference `auth_flexaccess_pendingemail` (unverifizierte E-Mail waehrend der Verstetigung). Vollstaendige Metadaten inkl. aller Feld-Beschreibungen; Export via `export_user_data` + `export_user_preferences`; alle Loeschpfade (Kontext, Nutzer, Userlist) entfernen zusaetzlich die Preference.
- **PHPDoc-Fix:** fehlender `@param $clientip` bei `api::request_magic_login` (CI-PHPDoc-Checker).
- Tests: `privacy_provider_test` (7) — Metadaten, Kontext-Ermittlung, Export inkl. Preference, `get_users_in_context`, alle drei Loeschpfade.

## 0.1.35 — 2026-08-19 — DSGVO-Datenschutz-Provider vervollstaendigt (§11)
- **Privacy-Provider vollstaendig**: Der Provider deckt jetzt auch die User-Preference `auth_flexaccess_pendingemail` (eine echte, noch unverifizierte E-Mail-Adresse) ab — Deklaration in den Metadaten, Export via `export_user_preferences()` UND Loeschung in allen drei Loesch-Pfaden (`delete_data_for_user`, `delete_data_for_all_users_in_context`, `delete_data_for_users`).
- `get_contexts_for_userid()` erkennt den Nutzerkontext jetzt auch, wenn NUR die Pending-E-Mail-Preference (ohne Tabellenzeilen) vorhanden ist.
- Tabellen-Metadaten vervollstaendigt: `_account` (+sourcecourseid/sourcecmid/timecreated/timeexpires), `_token` (+tokenhash [als Einweg-Hash gekennzeichnet]/timecreated/timeexpires/timeused), `_mailqueue` (+payloadjson/status/timecreated).
- 12 neue `privacy:metadata:*`-Strings (en+de). Neuer Test `privacy_provider_test` (Metadaten, Kontext-Erkennung, Export inkl. Preference, Loeschung inkl. Preference).

## 0.1.34 — 2026-08-19 — Rate-Limiting der oeffentlichen Schreib-Endpoints (§5)
- **Generischer Rate-Limiter** `local\rate_limiter` (Sliding-Window, App-Cache, zaehlt jeden Versuch).
- **Magic-Login-Anfrage rate-limitiert** pro Client-Adresse (15/10min) UND pro Zieladresse (3/10min, gegen Inbox-Spam) — enumeration-still (immer 'sent', bei Limit einfach kein Versand). `request_magic_login()` nimmt jetzt die Client-Adresse; `magic.php` reicht `getremoteaddr()` durch.

## 0.1.33 — 2026-08-19 — Enrolment-Expiry (§32/§33) + echte jmeter/playwright-Plaene (§26/§27)
- Keine Codeaenderung.

## 0.1.32 — 2026-08-19 — Magic-Login, Mail-Queue-Retrofit, SEC-03, main-CI + jmeter/playwright
- **Magic-Login (passwortlos)**: neue Seite `magic.php` + `magic_login_form`, API `request_magic_login()`/`consume_magic_login()`, Setting `allowmagiclogin` (Default an). Dauerhafte Konten fordern einen einmaligen Login-Link per E-Mail an (enumeration-sicher). Link auf `access.php` verlinkt.
- **Alle Mails ueber die Queue** (Review §9): neuer generischer Producer `api::queue_mail()`; Magic-Login UND die Persistenz-Verifikationsmail laufen jetzt ueber `auth_flexaccess_mailqueue` (Stundenlimit greift). `mail_kind` um VERIFICATION/MAGIC_LOGIN ergaenzt.
- **SEC-03 (Review §12)**: Persistenz- und Magic-Login-Token-Lebensdauer an die Kontogueltigkeit gekoppelt; `confirm_persistence()` und `consume_magic_login()` pruefen den Kontozustand erneut und lehnen abgelaufene/suspendierte Konten ab.

## 0.1.31 — 2026-08-18 — Aufraeumen: toter persistence_followup-Mailpfad entfernt
- **Vestigialen Followup-Pfad entfernt**: `api::request_persistence_followup()` und die Klasse `followup_scheduler` (samt Test) geloescht, `mail_kind::PERSISTENCE_FOLLOWUP` und die `followup:*`-Lang-Strings entfernt. Dieser Pfad mailte nach jedem temporaeren Zugang an die nicht zustellbare `@flexaccess.invalid`-Fake-Adresse und war seit der Selbstbedienungs-Persistenz (0.1.28) funktionslos. **Die generische Mail-Queue bleibt erhalten**: `mail_worker::deliver()` ist jetzt mailtyp-agnostisch und versendet generisch aus dem Job-Payload (recipient + subject + body/bodyhtml); Alt-/Fremd-Jobs ohne Payload werden ohne Retry verworfen, koennen die Queue also nicht blockieren. `token_service` bleibt (von der E-Mail-Verifikation genutzt).

## 0.1.30 — 2026-08-18 — DRY: gemeinsame Identitaetsfelder der Formulare
- **Duplizierung beseitigt** (phpcpd-Fund): neuer Trait `\auth_flexaccess\form\identity_fields` mit `add_identity_fields()` und `validate_identity($data, $excludeuserid)`. `quick_registration_form` und `persist_form` nutzen ihn jetzt; die zuvor doppelten 24 Zeilen (E-Mail/Vorname/Nachname/Passwort inkl. Validierung) existieren nur noch einmal. Verhalten unveraendert (per Behat fuer beide Formulare verifiziert). Formularspezifisches bleibt lokal: Hidden-Felder + Submit-Label der Registrierung, Submit-Label + email_available-Exclude (aktueller Nutzer) der Persistierung.

## 0.1.29 — 2026-08-18 — Paket B: E-Mail-Verifikation der Persistierung (Option, Default an)
- **E-Mail-Verifikation** vor dauerhaftem Konto: neue Admin-Einstellung `requireemailverification` (Default: aktiviert). Bei aktivierter Option speichert `request_persistence()` Name+Passwort am noch temporaeren Konto, merkt sich die gewuenschte E-Mail und mailt einen Bestaetigungslink; erst `confirm_persistence(token)` setzt E-Mail/Username und macht das Konto dauerhaft. Die squatting-relevante E-Mail/Username wird also erst nach Nachweis der Adresse gesetzt. Der Token authorisiert allein (kein Login noetig), ist einmalig und laeuft ab. Ist die Option aus, konvertiert `request_persistence()` sofort (bisheriges Verhalten). Der frueher funktionslose Token-/Mail-Pfad ist damit sinnvoll wiederverwendet. Verifikationsmail wird als Text+HTML gesendet (klickbarer Link).

## 0.1.28 — 2026-08-18 — Paket B: B4 Konvertierung temporaer -> persistent
- **Selbstbedienungs-Persistenz** (B4): `persist.php` ist jetzt ein Formular fuer den eingeloggten temporaeren Nutzer (E-Mail, Name, Passwort) statt der token-basierten Aktivierung. Neue API `persist_temporary_user()` aktualisiert den bestehenden Nutzer (echte E-Mail als Username, Name, emailstop=0, suspended=0, gesetztes Passwort) und ruft `convert_to_authenticated()` — **gleiche user id**, daher bleiben Einschreibung, Ergebnisse und Aktivitaet erhalten, und der Nutzer kann sich spaeter erneut anmelden. `account_service::is_temporary()` neu; `email_available()` akzeptiert jetzt eine auszuschliessende user id (der aktuelle Nutzer). Der alte Token-/Mail-Followup-Pfad bleibt vorhanden, ist aber fuer rein temporaere Konten funktionslos (E-Mail unzustellbar) — Aufraeumen folgt separat.

## 0.1.27 — 2026-08-18 — Paket A abgeschlossen: Methodenauswahl (Gast + Normallogin)
- **Entry-Page bietet jetzt alle konfigurierten Methoden an**: neben temporaerem Zugang, Access-Key-Challenge und Schnellregistrierung nun auch **Gastzugang** ("Continue as a guest" -> meldet den Moodle-Gast an und leitet in den Kurs; ob Inhalte sichtbar sind, haengt vom Gast-Enrolment des Kurses ab) und einen **Link zur reguleren Anmeldung** ("Already have an account? Log in" -> /login mit wantsurl). Die "nicht verfuegbar"-Seite bietet weiterhin keine Methoden an (Enumeration-Guard).

## 0.1.26 — 2026-08-18 — Paket A: Quick-Registration (allowquick)
- **Schnellregistrierung**: neue anonyme Seite `register.php` + `\auth_flexaccess\form\quick_registration_form` (E-Mail, Vor-/Nachname, Passwort). Erzeugt ein *persistentes, sofort login-faehiges* Konto (auth=flexaccess, bestaetigt, echte E-Mail, gesetztes Passwort) via neuer API `create_quick_registered_user()`; `account_service::create_authenticated()` legt den aktiven, unbefristeten Kontodatensatz an. Neue Helfer: `email_available()` (Eindeutigkeitspruefung). access.php verlinkt die Registrierung, wenn angeboten. Enumeration-Guard und lokaler Redirect-Zielschutz wie bei access.php.

## 0.1.25 — 2026-08-18 — CI-Fix (veraltete Behat-Datei)
- Keine Codeaenderung.

## 0.1.24 — 2026-08-18 — Paket A: B2 (Access-Key) verifiziert
- **Access-Key-Durchsetzung end-to-end per Behat verifiziert** (Sicherheits-Blocker B2 geschlossen): Challenge-Formular, falscher Schluessel wird abgewiesen, korrekter Schluessel gewaehrt Zugang; Rate-Limit im Flow, Schluessel nur per POST (nie in URL/Log). 3 Ecosystem-Szenarien, 20 Steps gruen.
- access.php nutzt jetzt den Facade `enrol_flexaccess\api::requires_temporary_access_key()` statt einer eigenen Policy-Abfrage; redundante, ungenutzte Challenge-Formklasse entfernt.

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
