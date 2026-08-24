# auth_flexaccess — Pflichtenheft

**Verantwortung:** zielabhängiges Login-Routing, Accounttypen/-zustände, temporäre Nutzer, Schnellregistrierung, Aktivierung/Conversion, Token- und Mail-Lifecycle.


## 1. Systemarchitektur

Die Plugins bilden eine gerichtete Abhängigkeit, die den **Zyklus** `auth ↔ enrol` bewusst enthalten darf. Moodle erlaubt zirkuläre Plugin-Abhängigkeiten: der Core prüft nur, ob die deklarierte Abhängigkeit in ausreichender Version **vorhanden** ist (`core_plugin_manager::are_dependencies_satisfied()`), nicht eine zyklenfreie Installationsreihenfolge. Fehlende Komponenten werden bei manueller Installation zur Nachinstallation angefordert (ZIP-Upload oder Moodle Plugins Directory); bei Git-Deployments liegt ohnehin der gesamte Code vor.

**Leitprinzip:** Jede Kopplung, die fachlich Voraussetzung für die Bereitstellung der Funktionalität ist, wird **hart** deklariert (`version.php` → `dependencies`) — auch wenn dadurch ein Zyklus entsteht.

- `auth_flexaccess` → `enrol_flexaccess` (die anzubietenden Zugangswege ergeben sich aus der Policy, die vollständig in `enrol` liegt)
- `enrol_flexaccess` → `auth_flexaccess` (temporäre Nutzer/Einschreibungen nur über auth-Account-Services)
- `tool_flexaccess` → `auth_flexaccess` + `enrol_flexaccess` (Account-/Mail-Services und Enrol-Policy-Diagnose, Pflichtenheft §12)
- `mod_flexaccess` → `auth_flexaccess` + `enrol_flexaccess` (Delegation der Aktivierung an auth, Profilfeld-Allowlist aus enrol-Policy)

**Laufzeit vs. Installation:** Eine harte Abhängigkeit garantiert nur, dass der **Code** des Partners vorhanden ist — nicht, dass er aktiviert ist oder im Kurs eine aktive Instanz hat. Daher (1) erfolgen plugin-übergreifende Facade-Aufrufe ausschließlich **lazy zur Laufzeit**, niemals in `db/install.php`/`db/upgrade.php` (sonst bricht die Erstinstallation wegen des Zyklus); und (2) `auth_flexaccess` fällt je Kurs auf den normalen Moodle-Login zurück, wenn dort keine aktive `enrol_flexaccess`-Instanz konfiguriert ist — reguläres Laufzeitverhalten, kein „Plugin-fehlt"-Fallback.

## 2. Zielauflösung

1. Moodle setzt bzw. übergibt die gewünschte Ziel-URL (`wantsurl`).
2. `auth_flexaccess` validiert, dass das Ziel intern ist.
3. URL/Route wird auf `cmid`, Aktivität und Kurs bzw. direkt auf Kurs aufgelöst.
4. Für den Kurs wird die wirksame FlexAccess-Policy abgefragt.
5. Nur tatsächlich erlaubte Zugangswege werden dargestellt.
6. Nach erfolgreichem Vorgang wird exakt auf das validierte ursprüngliche Ziel zurückgeleitet.

Ein Bypass-Marker für den normalen Core-Login wird ausschließlich serverseitig in der Session gehalten, um Redirect-Schleifen zu verhindern.

## 3. Accountmodell

### 3.1 accounttype

Exakte Werte:

- `temporary user`
- `authenticated user`

### 3.2 accountstate

Mindestens:

- `ephemeral` — ohne personenbezogene Angaben,
- `provisional` — Schnellregistrierung begonnen, E-Mail noch nicht bestätigt,
- `active` — FlexAccess-Identität bestätigt,
- `expired` — Laufzeit überschritten,
- `suspended` — manuell/automatisch gesperrt.

Accounttyp und Zustand werden nicht vermischt.

## 4. Temporäre Accountanlage

Die Implementierung erzeugt mit Moodle User API einen individuellen Nutzer. Nutzername und internes Passwort sind kryptographisch zufällig. Das Passwort wird weder angezeigt noch für den temporären Wiedereinstieg verwendet. Die E-Mail ist leer, sofern Moodle dies für den Erzeugungspfad zulässt; andernfalls wird eine eindeutige, nicht zustellbare Platzhalteradresse verwendet und `emailstop` gesetzt.

Die Referenznummer ist administrativ suchbar, aber niemals Login- oder Recovery-Geheimnis.

## 5. Schnellregistrierung und Aktivierung

- Formular verwendet Moodle Form API und sesskey.
- Profilfelder stammen aus einer Allowlist der Kurs-/Systemkonfiguration.
- Sofort nach erfolgreicher Plausibilitätsprüfung wird eine provisorische Session erzeugt.
- Mailqueue erhält nur semantischen Versandauftrag und nicht den Klartext eines Aktivierungstokens.
- Der Worker erzeugt den Token unmittelbar vor Versand, speichert nur dessen Hash und verwirft/invalidiert ihn bei fehlgeschlagenem Versand.
- Aktivierungslink bestätigt E-Mail und fordert das Setzen eines Passworts.
- Nach Erfolg: `accounttype = authenticated user`, `accountstate = active`.
- Löschlink ist single-use und nach Aktivierung ungültig.

## 6. Mailqueue und SMTP-Throttle

`auth_flexaccess` besitzt eine persistente Queue. Ein Scheduled Task läuft mindestens minütlich.

1. Moodle Lock API sperrt den Queue-Worker clusterweit.
2. Erfolgreich versendete FlexAccess-Mails der letzten 3600 Sekunden werden gezählt.
3. Restkapazität = konfiguriertes Stundenlimit minus Anzahl.
4. Es werden maximal so viele fällige Queue-Einträge verarbeitet.
5. `unlimited` überspringt die Kapazitätsbegrenzung.
6. Fehler erhöhen `attempts` und setzen `nextrun` mit Backoff.

Die Einstellung gilt nur für FlexAccess-Mails und kann keine fremden Moodle-Mails limitieren.

## 7. Einschreibung

`enrol_flexaccess` erzeugt normale Moodle-`user_enrolments`. Pro Kursinstanz sind u. a. konfigurierbar:

- temporärer Zugang an/aus,
- Schnellregistrierung an/aus,
- normaler Gastzugang anbieten an/aus,
- normalen Login anbieten an/aus,
- Standardrolle,
- Gruppe bzw. Gruppenstrategie,
- Einschreibedauer,
- Ablaufaktion `suspend` oder `unenrol`,
- temporäre Accountlaufzeit,
- Aktivierungsfrist für Schnellregistrierung,
- Profilfelder,
- Teilnehmerlisten-Sichtbarkeit `inherit/show/hide`.

## 8. Policy-Präzedenz

`system default` → `category policy` → `course enrol instance`.

Verbote werden restriktiv vererbt. Eine untergeordnete Ebene kann ohne explizite administrative Freigabe kein höheres Verbot aufheben. Einstellungen mit reinem Default-Charakter (z. B. Dauer) dürfen überschrieben werden, solange die Methode selbst erlaubt bleibt.

## 9. Teilnehmerlisten-Sichtbarkeit

- Systemdefault: `show` oder `hide`.
- Kursinstanz: `inherit`, `show`, `hide`.
- Temporäre Nutzer selbst erhalten standardmäßig keine Capability, andere Profile/Teilnehmer zu sehen.
- Das Ausblenden temporärer aktiver Einschreibungen aus den Core-Teilnehmerlisten für normale Lernende wird in einem eigenen Compatibility Spike umgesetzt. Nur öffentliche Core-Hooks/APIs sind zulässig. Gibt es für Moodle 4.5 keinen geeigneten per-user Filter, wird kein heimlicher Core-Patch implementiert; die Einschränkung wird dokumentiert und eine API-kompatible Fallback-Policy festgelegt.

## 10. Selbstaktivierungsmodul

Das Modul:

- ist nur für eingeloggte `temporary user` relevant,
- rendert ein Aktivierungsformular mit der Instanz-Allowlist von Profilfeldern,
- delegiert Accountänderung und Tokens vollständig an `auth_flexaccess`,
- speichert keine zweite Benutzeridentität,
- darf nach Aktivierung optional als abgeschlossen gelten,
- zeigt `authenticated user` nur einen konfigurierbaren Erfolgs-/Hinweistext,
- verändert keine Einschreibedauer.

## 11. Security DoD

- `require_login()`/Kontextprüfung auf geschützten Seiten,
- `require_sesskey()` für mutierende POST-Aktionen,
- `PARAM_*` passend zum Feld,
- output escaping über Moodle Renderer/Form API,
- redirect nur auf validierte interne URLs,
- token comparison hashbasiert und timing-sicher,
- rate limits für Accountanlage, Mailanforderung und Tokenprüfung,
- DB-Transaktionen bei Zustandswechseln,
- Moodle Lock API bei Queue/Cleanup-Races,
- keine Account-Enumeration über unterschiedliche Fehlermeldungen,
- Events für create/provisional/activate/expire/convert/delete/enrol/suspend/unenrol.


## Systemweite Administrationskonsole (`tool_flexaccess`)

`tool_flexaccess` ist ein dünner Application-/UI-Layer. Es darf fachliche Tabellen der anderen Plugins für Mutationen nicht direkt beschreiben. Vorgesehene Seiten:

- `index.php`: Operations-Dashboard,
- `accounts.php`: paginierte Suche/Filterung von FlexAccess-Accounts,
- `account.php`: Detailansicht und capability-/sesskey-gesicherte Aktionen,
- `mailqueue.php`: Queue-/Throttle-Diagnose und kontrollierter Retry,
- `policies.php`: read-only Diagnose der effektiven Enrolment-Policies.

Capabilities werden auf Systemkontext getrennt: Dashboard sehen, Accounts verwalten, Accounts konvertieren, Mailqueue verwalten und Policy-Diagnose sehen. Alle schreibenden Requests verwenden Moodle Form API bzw. `confirm_sesskey()` und POST.

Das Tool speichert im MVP keine personenbezogenen Daten selbst und implementiert deshalb einen `null_provider`; sobald später eigene Audit-/Bookmark-/Workflowdaten hinzukommen, muss dies auf einen vollständigen Privacy Provider umgestellt werden.

## 12. Testabdeckung

PHPUnit: Account-Zustände, Token-Lifecycle, Mail-Throttle, Policy-Merge, Ablaufberechnung, Duplicate-E-Mail-Fälle, idempotente Tasks.  
Behat: Deep-Link → Zugangsseite → temporärer Zugang; Quick Registration; normaler Login-Fallback; Selbstaktivierung; Ablauf-/Fehlersichten.  
Zusätzlich: MariaDB + PostgreSQL; Moodle 4.5, 5.1, 5.2; Security Regression Tests für Redirect, CSRF, Token-Reuse und Enumeration.


## Zusatzanforderung: Zugangsschlüssel-Challenge

`auth_flexaccess` orchestriert vor temporärer Gast-Anmeldung und Schnellregistrierung optional eine kursbezogene Zugangsschlüssel-Challenge. Die Policy und Verifikation gehören `enrol_flexaccess`; `auth_flexaccess` darf weder System- noch Kurs-Hash lesen oder persistieren. Nach erfolgreicher Prüfung wird ausschließlich eine kurzlebige, kursgebundene Freigabe in der serverseitigen Session gespeichert. Normaler Login, normaler Moodle-Gastzugang und die spätere Selbstaktivierung bleiben unbeeinflusst.
