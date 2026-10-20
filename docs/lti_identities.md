# LTI-Identitäten und Kontozusammenführung (ab 1.2.0)

Dieses Dokument beschreibt, wie FlexAccess mit LTI-1.3-Identitäten umgeht (auth_flexaccess #12,
LTI-BINDING) und wie Kontozusammenführungen mit `tool_mergeusers` abgesichert sind (auth_flexaccess #13,
LTI-MERGE). Es richtet sich an Administration und Betrieb. Die Prüfungen liegen in
`classes/local/lti_guard.php`, `merge_guard.php`, `merge_service.php` und `merge_registry.php`.

## 1. Grundsatz: Nur dauerhafte Identitäten werden gebunden

Moodle Core (auth_lti) bindet eine Plattform-Identität (`iss`, `sub`) an das Konto, mit dem die
Person in diesem Moment angemeldet ist. FlexAccess lässt das nur für **dauerhafte Konten** zu:

| Zustand des angemeldeten FlexAccess-Kontos | Bindung | Meldung |
|---|---|---|
| Authentifiziert, ACTIVE, bestätigt, eigenes Passwort, keine offene Adressbestätigung | erlaubt | – |
| Temporär (EPHEMERAL, PROVISIONAL, Zugangskarte) | abgewiesen | Hinweis auf „Zugang dauerhaft machen“ |
| PENDING_CREDENTIAL (noch kein eigenes Passwort) | abgewiesen | `nocredential` |
| Geänderte, noch nicht bestätigte E-Mail-Adresse | abgewiesen | `verificationpending` |
| Abgelaufen, gesperrt, gelöscht, unbestätigt, Auth-Plugin deaktiviert | abgewiesen | Grund des Login-Guards |
| Kein FlexAccess-Konto (manuell, LDAP, …) | nicht Sache von FlexAccess | – |

Technik: Der Callback `auth_flexaccess_after_require_login` prüft auf `/auth/lti/login.php` die
Anfrage „bestehendes Konto verknüpfen“, **bevor** Core die Bindung schreibt. Bei Ablehnung wird der
wartende Launch (`$SESSION->auth_lti`) verworfen, damit dieselbe Sitzung die Bindung nicht später
nachholen kann. Abweisungen werden als Login-Guard-Ereignis (Kanal `ltibind`) protokolliert.

Ein späterer Launch über eine bestehende Bindung meldet die Person über Core an
(`auth_lti::complete_login()`). Der Hook `\core_user\hook\after_login_completed` prüft dann dieselben
Regeln erneut; eine unzulässige Sitzung wird sofort beendet und nie zur Gastsitzung (Kanal
`external`). Ist die Person auf dem Provider schon angemeldet, überspringt Core `complete_user_login()`;
dann prüft der Callback auf der Launch-Seite (`/enrol/lti/launch.php`, `launch_deeplink.php`,
`lti_guard::check_launch_session()`) dieselben Regeln und beendet die Sitzung bei einem Verstoß.

Bindungen entstehen nie über E-Mail-Adressen oder Namen. Die gleiche Adresse dient nur dem Systemstatus
als **Hinweis** auf mögliche Dubletten (Abschnitt 2), die eine Person prüfen und freigeben muss.

Nutzer, deren Authentifizierung eine Administratorin von `flexaccess` auf eine andere Methode umgestellt
hat, regelt diese andere Methode; FlexAccess weist sie trotz verbliebener Kontozeile nicht ab.

## 2. Bereitstellungsmodi von enrol_lti

| Modus (Lernende) | Empfehlung für FlexAccess-Kurse | Folge |
|---|---|---|
| Bestehendes Konto abfragen (PROMPT_EXISTING_ONLY) | empfohlen | Person meldet sich mit ihrem dauerhaften FlexAccess-Konto an und verknüpft es |
| Neues oder bestehendes Konto (PROMPT_NEW_EXISTING) | möglich | wie oben; „neues Konto“ erzeugt ein separates auth_lti-Konto |
| Automatisch anlegen (AUTO_ONLY) | nur mit Zusammenführungsprozess | Core legt ein **zweites** Konto an, auch wenn ein FlexAccess-Konto mit gleicher Adresse existiert |

Zwei Folgen von AUTO_ONLY, die ein späterer Moduswechsel nicht rückgängig macht:

- **Mitgliedersynchronisation (NRPS)** legt bei AUTO_ONLY auch für Mitglieder, die nie gestartet haben,
  bereits auth_lti-Konten an und bindet sie (E2E: `@provider NRPS membership sync …`).
- **Wechsel auf PROMPT_*:** Bestehende Bindungen bleiben; wer schon ein automatisches Konto hat, wird nicht
  erneut gefragt. Diese Fälle erscheinen als Dublettenkandidaten und werden über die Zusammenführung
  (Abschnitt 3) bereinigt. („Bindung entfernen“ gibt es nur für Bindungen an nicht dauerhafte
  FlexAccess-Konten oder gelöschte Nutzer, nicht für auth_lti-Konten.)

Der Systemstatus (tool_flexaccess → Systemstatus → LTI) meldet veröffentlichte Ressourcen mit
AUTO_ONLY, Dublettenkandidaten (auth_lti-Konto mit gleicher Adresse wie ein dauerhaftes FlexAccess-Konto),
Bindungen an nicht dauerhafte oder gelöschte Identitäten und Zusammenführungen, die geprüft werden
müssen. Die Seite **tool_flexaccess → Systemstatus → LTI-Identitäten** listet diese Fälle mit den Aktionen.

## 3. Zusammenführung mit tool_mergeusers

### Ablauf

1. **Bewerten** (`api::assess_merge()`, Seite „LTI-Identitäten“): Quelle (wird entfernt) und Ziel
   (bleibt) werden gegenübergestellt; Sperrgründe und Warnungen werden angezeigt.
2. **Freigeben**, wenn beide Identitäten dauerhaft sind (FlexAccess-Konto mit Passwort oder auth_lti):
   `api::approve_merge()` hält im Register `auth_flexaccess_merge` fest, wer freigegeben hat und wann
   (Stufe „preapproved“, `approvedby`, `timeapproved`). Die Freigabe gilt einen Tag.
3. **Zusammenführen** in tool_mergeusers. Innerhalb der Transaktion von tool_mergeusers:
   - `add_settings_before_merging`: Die FlexAccess-eigenen Tabellen (Konto, Tokens, Mailqueue,
     Register) werden vom generischen Tabellen-Merge ausgenommen.
   - `after_merged_all_tables`: Die Richtlinie wird geprüft und die FlexAccess-Einschreibungen der
     Quelle werden auf das Ziel übertragen – noch in derselben Transaktion. Ein Verstoß (oder eine
     gleichzeitig laufende Bearbeitung desselben Paars, `locked`) meldet einen Fehler an tool_mergeusers,
     das daraufhin die **gesamte Transaktion zurückrollt** – es ändert sich nichts. Ein abgewiesener
     Merge hinterlässt deshalb auch keinen Registereintrag; die Ablehnung steht im Protokoll von
     tool_mergeusers.
4. **Prüfung** nach dem Commit (Ad-hoc-Aufgabe `reconcile_merge`, `merge_service::verify()`): Bindungen
   und LTI-Mitgliedschaften werden überprüft, eine administrative Sperre des Ziels wird wiederhergestellt,
   falls tool_mergeusers sie nach dem Commit aufgehoben hat. Erst wenn alle Nachbedingungen erfüllt sind,
   werden die FlexAccess-Daten der Quelle entfernt.

### Registerstufen und Nachweis (ab 2026101800, auth_flexaccess #14)

Ein Registereintrag ist **kein** Nachweis, dass zwei Nutzer zusammengeführt wurden. Die Stufe sagt, wo
FlexAccess mit einem Paar steht; ob Moodle die Nutzer wirklich zusammengeführt hat, steht getrennt davon
als Nachweis im Eintrag: die ID des erfolgreichen tool_mergeusers-Protokolleintrags für genau dieses Paar
(`mergelogid`, `timeconfirmed`). Gesetzt wird er vom Ereignis `user_merged_success` (nach dem Commit), von
der Prüfung (`verify`) oder beim Upgrade aus dem Protokoll; bei jeder Verwendung wird er erneut gegen die
Tabelle `tool_mergeusers` geprüft.

| Stufe | Bedeutung |
|---|---|
| `preapproved` | Freigabe erteilt, noch nicht zusammengeführt |
| `pending` | Erfasst ohne Nachweis (direkte Abgleichung, Altbestand) |
| `mergeinprogress` | tool_mergeusers führt gerade zusammen (in seiner Transaktion) |
| `mergeconfirmed` | tool_mergeusers hat den Merge bestätigt, die Prüfung läuft noch |
| `reconciled` | Abgeschlossen, alle Nachbedingungen erfüllt |
| `review` | Mindestens eine Nachbedingung nicht erfüllt |
| `refused` | Von FlexAccess abgewiesen |

**Umhängen** (Bindungen in `auth_lti_linked_login`, Mitgliedschaften in `enrol_lti_users`) ist nur
möglich, wenn alle Bedingungen gelten – sonst wird nichts verändert:

| Bedingung | Code bei Ablehnung |
|---|---|
| Nachweis vorhanden und noch prüfbar | `mergenotconfirmed` |
| Zu entfernendes Konto ist kein aktives, eigenständiges Konto mehr (gesperrt oder gelöscht, wie tool_mergeusers es hinterlässt) | `sourceactive` |
| Ziel existiert und wurde nicht selbst zusammengeführt | `targetnotcanonical` |
| Die Administratorin bestätigt, dass beide Konten derselben Person gehören | `ownershipnotverified` |
| Keine Mitgliedschaft der Quelle in einem Tool, dem das Ziel schon angehört (sonst zwei Notenempfänger) | `membershipconflict` |

Die Prüfung läuft vollständig vor der ersten Änderung; die Änderung selbst läuft in einer Transaktion und
wird zurückgerollt, falls danach noch etwas auf die Quelle zeigt. Eine Wiederholung findet nichts mehr
zu verschieben. Jede Aktion wird als `identity_merge_action` mit Quelle, Ziel, Nachweis (`mergelogid`),
Freigabe (`approvedby`, `timeapproved`), Ergebnis und Zahlen protokolliert.

Hinweis: Ist in tool_mergeusers „Nutzer sperren“ (`suspenduser`) abgeschaltet, bleibt das zusammengeführte
Konto aktiv; dann wird Umhängen mit `sourceactive` abgelehnt, bis das Konto gesperrt oder gelöscht ist.

### Sperrgründe (Merge wird abgewiesen)

| Code | Bedeutung |
|---|---|
| `sourcedurable` | Ein dauerhaftes FlexAccess-Konto soll in ein Konto ohne eigene Passwortanmeldung aufgehen (Nicht-FlexAccess-Konto, temporäres oder noch passwortloses FlexAccess-Konto) – die Person verlöre ihre Passwortanmeldung |
| `needsapproval` | Zwei dauerhafte Identitäten ohne gültige Freigabe |
| `targetmissing` | Das Ziel existiert nicht (mehr) |

### Nachbedingungen (sonst Status „review“)

| Code | Bedeutung | Blockierend im Hook |
|---|---|---|
| `sourcebindings` | Eine LTI-Bindung zeigt noch auf die Quelle | ja |
| `sourceltiusers` | Eine enrol_lti-Mitgliedschaft zeigt noch auf die Quelle | ja |
| `duplicateltiusers` | Das Ziel hat zwei Mitgliedschaften im selben Tool (zwei Notenempfänger) | ja |
| `targetlocked` | Das Ziel trägt eine Sperre, die FlexAccess nicht aufheben darf | nein |
| `targetlocklifted` | Eine administrative Sperre des Ziels wurde aufgehoben | nein (wird wiederhergestellt) |

Nachträglich erkannte Merges (Ereignis, Backfill nach dem Upgrade, erneute Prüfung) werden nie
„abgewiesen“, sondern als „review“ geführt: Sie sind bereits geschehen.

### Welche Daten wer behandelt

FlexAccess verlässt sich nicht auf die Tabellenliste von tool_mergeusers, sondern prüft nach jedem Merge
die Nachbedingungen am Datenbestand:

| Daten | tool_mergeusers (Zweig `MOODLE_405_STABLE`) | FlexAccess |
|---|---|---|
| Alle Core-Tabellen mit Nutzer-ID (Noten, Abschluss, Rollen, Einschreibungen, Protokolle …) | verschiebt generisch auf das Ziel | überträgt zusätzlich die FlexAccess-Einschreibungen nach eigener Regel (aktiv gewinnt, spätestes Ende gewinnt) |
| `auth_lti_linked_login` (Bindung iss/sub) | generisch | Nachbedingung `sourcebindings`; „Umhängen“, falls etwas übrig bleibt |
| `enrol_lti_users` (Mitgliedschaft, Notenziel) | generisch, Doppelte möglich | Nachbedingungen `sourceltiusers`, `duplicateltiusers` |
| `auth_flexaccess_account`, `_token`, `_mailqueue`, `_merge` | ausgenommen (`add_settings_before_merging`) | eigener Abgleich, Quelldaten erst nach erfüllten Nachbedingungen entfernt |
| Sperre des Ziels (`user.suspended`) | hebt sie nach dem Commit auf | stellt eine fremde (administrative) Sperre wieder her (`targetlocklifted`) |

### Nachweismatrix

| Aussage | PHPUnit (auth) | Zwei-Site-E2E (enrol `tests/playwright/lti`) |
|---|---|---|
| Dauerhaftes Konto wird ohne Dublette gebunden | `lti_binding_test::test_durable_account_is_bound_without_duplicate` | `@provider first launch links …` |
| Temporäre Sitzung wird nie gebunden | `test_temporary_session_is_not_bound` | `@provider a temporary FlexAccess session …` |
| PENDING_CREDENTIAL wird nicht gebunden | `test_pending_credential_is_not_bound` | `@provider an account still waiting …` |
| Abgelaufene FlexAccess-Konten können nicht launchen (auch mit offener Sitzung) | `test_bound_launch_of_lapsed_account_is_refused`, `test_launch_with_existing_session_of_lapsed_account_is_refused` | – (E2E prüft Kurszugangs-Ablauf und Moodle-Sperre) |
| Gesperrte Konten können nicht launchen | `test_bound_launch_of_suspended_account_is_refused` | `@provider FlexAccess expiry … suspended …` |
| AUTO_ONLY verknüpft nie per E-Mail | `test_auto_only_creates_distinct_user_and_never_links_by_email` | `@provider automatic account, approved merge …` |
| Dauerhaft → auth_lti wird abgewiesen (Rollback) | `lti_merge_test::test_durable_flexaccess_into_lti_is_refused` | – |
| Zwei dauerhafte Identitäten brauchen Freigabe | `test_merge_of_durable_identities_needs_approval` | `@provider automatic account, approved merge …` |
| Bindung bleibt nach dem Merge | `test_lti_into_flexaccess_keeps_login_binding_and_grade_delivery` | `@provider … stable relaunch`, `@provider NRPS membership sync …` |
| Notenrückgabe nach dem Merge | `test_lti_into_flexaccess_keeps_login_binding_and_grade_delivery` | – (AGS-E2E vor dem Merge: `@provider the provider grade reaches …`) |
| Dauerhaft → temporär/passwortlos wird abgewiesen | `test_durable_flexaccess_into_temporary_flexaccess_is_refused` | – |
| Umhängen nur nach bestätigtem Merge; Freigabe allein genügt nicht | `test_repoint_requires_a_merge_under_review` | – |
| Zwei lebende, nicht zusammengeführte Konten (pending/review, gefälschter Nachweis): keine Änderung | `test_repoint_refused_for_unmerged_pairs` | tool `merge-review.spec.js` (kein Umhängen angeboten) |
| Bestätigter Merge: Umhängen, Wiederholung ohne Wirkung, Relaunch beim Ziel | `test_stale_binding_after_confirmed_merge_is_repointed` | – |
| Konflikt doppelter Mitgliedschaft: nichts geändert, protokolliert | `test_repoint_conflict_changes_nothing` | – |
| Doppelte Mitgliedschaft wird abgewiesen | `test_duplicate_membership_is_refused` | – |
| Administrative Sperre bleibt erhalten | `test_admin_lock_of_survivor_is_restored` | – |
| Altbestände werden nachregistriert | `test_backfill_registers_earlier_merges` | – |

Die PHPUnit-Tests laufen gegen das echte tool_mergeusers (Zweig `MOODLE_405_STABLE`); die CI
installiert es dafür in den auth-Workflows.

## 4. Unterbrochene oder nachträglich erkannte Zusammenführungen

Ein Merge kann außerhalb der Hooks geschehen sein (ältere tool_mergeusers-Version, Abbruch nach dem
Commit, Merge vor dem Upgrade auf 1.2.0). Vorgehen:

1. Nach dem Upgrade registriert der Upgrade-Schritt `2026101700` frühere Merges aus dem Protokoll von
   tool_mergeusers und reiht `verify_merges` ein. Der Systemstatus zeigt danach die Anzahl der Paare
   mit Status „review“.
2. Auf **tool_flexaccess → Systemstatus → LTI-Identitäten** zeigt jedes Paar seine offenen Nachbedingungen.
3. Je nach Grund:
   - `sourcebindings` / `sourceltiusers`: **Umhängen** (verschiebt Bindungen und Mitgliedschaften auf das
     Ziel). Angeboten nur für einen von tool_mergeusers bestätigten Merge und mit Bestätigung der
     Zugehörigkeit; die Seite nennt sonst den Grund. Bei einer Mitgliedschaft in einem Tool, dem das Ziel
     schon angehört, wird nichts verschoben – zuerst die doppelte Mitgliedschaft auflösen.
   - `duplicateltiusers`: die überzählige Mitgliedschaft in enrol_lti prüfen und entfernen, dann **Erneut prüfen**.
   - Bindung an ein nicht dauerhaftes oder gelöschtes Konto: **Bindung entfernen** – die Person wird beim
     nächsten Launch erneut gefragt und kann ihr dauerhaftes Konto verknüpfen.
   - `targetlocked`: Sperre bewusst entscheiden (tool_flexaccess → Sperrherkunft), dann **Erneut prüfen**.
4. **Erneut prüfen** führt `merge_service::verify()` aus. Sind alle Nachbedingungen erfüllt, wird das
   Paar „reconciled“ und die FlexAccess-Daten der Quelle werden entfernt. Solange ein Paar offen ist,
   bleiben diese Daten auch beim Löschen des Quellnutzers erhalten.

Alle Aktionen verlangen `moodle/user:update` und einen Sesskey und werden als Ereignis
`identity_merge_action` protokolliert.

## 5. Rückgängig machen einer Zusammenführung

tool_mergeusers kann einen abgeschlossenen Merge nicht rückgängig machen, und FlexAccess auch nicht: die
Quell-ID ist danach in allen Core-Tabellen durch das Ziel ersetzt. Ein abgewiesener Merge braucht keine
Rücknahme (Rollback). Für einen versehentlich ausgeführten Merge gilt:

1. Vorher: Datenbanksicherung – der einzige vollständige Weg zurück.
2. Danach ohne Sicherung: ein neues Konto für die abgetrennte Person anlegen (bei FlexAccess dauerhaft,
   mit eigenem Passwort) und Kurs-Einschreibungen neu vergeben. Die LTI-Bindung zeigt nach dem Merge auf
   das Ziel; soll die Plattform-Identität zum neuen Konto gehören, muss eine Administratorin die Bindung
   in auth_lti (`auth_lti_linked_login`) entfernen, dann fragt der nächste Launch erneut. FlexAccess bietet
   dafür bewusst keine Aktion an, weil sie eine dauerhafte, gültige Bindung lösen würde.
3. Das Registerpaar bleibt als Nachweis erhalten (Status „reconciled“ oder „review“).

