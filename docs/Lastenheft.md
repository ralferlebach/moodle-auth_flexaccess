# auth_flexaccess — Lastenheft

**Verantwortung:** zielabhängiges Login-Routing, Accounttypen/-zustände, temporäre Nutzer, Schnellregistrierung, Aktivierung/Conversion, Token- und Mail-Lifecycle.


## 1. Ziel

FlexAccess ersetzt eine plattformspezifische alternative Login-Seite durch einen Moodle-nativen, kontextsensitiven Zugang. Ein direkter Aufruf eines Kurses oder einer Aktivität soll abhängig von System-, Kursbereichs- und Kurseinstellungen passende Zugangsmöglichkeiten anbieten, ohne Moodle-Core-Dateien zu verändern.

## 2. Pluginumfang

Das Projekt umfasst ausschließlich:

1. `auth_flexaccess`
2. `enrol_flexaccess`
3. `tool_flexaccess` (systemweite Administration und Betrieb)
4. `mod_flexaccessactivation` (optional installierbare Aktivität)

Nicht Bestandteil sind `local`, `block` und `availability`. Eine generalisierte Availability-Lösung ist ein separates Projekt.

## 3. Nutzerseitige Zugangswege

Je nach wirksamer Policy können angeboten werden:

- temporärer Nutzerzugang ohne Namens-/E-Mail-Angabe,
- Schnellregistrierung mit Name, E-Mail und optional konfigurierten Profilfeldern,
- normaler Moodle-Gastzugang, sofern im Zielkurs verfügbar,
- normaler Login für bereits bestehende Nutzer.

## 4. Accounttypen

FlexAccess verwendet bewusst nur zwei verständliche Accounttypen:

- `temporary user`
- `authenticated user`

Der Lifecycle wird getrennt über Zustände modelliert. Ein Nutzer aus einem anderen Moodle-Authentifizierungsplugin gilt für die Policy-Auswertung als `authenticated user`, ohne dass dafür zwingend ein FlexAccess-Metadatensatz angelegt wird.

## 5. Temporärer Nutzer

Ein temporärer Nutzer soll:

- ohne Angabe von Namen oder vergleichbaren Personendaten erzeugt werden können,
- eine interne zufällige Identität erhalten,
- kein nutzbares, geteiltes oder im Browser übertragenes Passwort benötigen,
- standardmäßig keine E-Mail, Messages oder Benachrichtigungen erhalten,
- hinsichtlich Profil- und Kommunikationsfunktionen stark eingeschränkt sein,
- eine administrativ auffindbare Referenznummer erhalten,
- für 3, 6, 12, 24 Stunden oder unbegrenzt gültig sein können,
- später unter Beibehaltung derselben Moodle-`userid` in einen `authenticated user` umgewandelt werden können.

## 6. Schnellregistrierung

Die Schnellregistrierung fragt mindestens Name und E-Mail ab und kann ausgewählte Nutzerprofilfelder ergänzen. Der Nutzer erhält sofort eine provisorische Session. Die E-Mail-Adresse wird über einen Aktivierungslink bestätigt; ein Passwort wird nicht im Klartext per E-Mail verschickt, sondern nach erfolgreicher Linkprüfung gesetzt. Bis dahin bleibt der Accounttyp `temporary user` und der Zustand `provisional`.

Ohne Aktivierung kann die Registrierung nach 24 Stunden, 48 Stunden, einer Woche, einem frei gesetzten Zeitraum oder nie verfallen. Die Aktivierungsmail enthält zusätzlich einen einmaligen Löschlink, der nach erfolgreicher Aktivierung ungültig wird.

## 7. Einschreibung

Der Zielkurs kann automatisch über `enrol_flexaccess` eingeschrieben werden. Accountlaufzeit und Einschreibungslaufzeit sind unabhängig. Nach Ablauf der Einschreibung kann der Kurszugang suspendiert oder entfernt werden, während der Account bestehen bleibt.

## 8. Policy-Scope

Die Funktion kann systemweit eingeschränkt werden auf:

- Kurse,
- Kursbereiche,
- Cohorts/Nutzergruppen,
- Rollen.

Kursbezogene Regeln werden durch eine Instanz von `enrol_flexaccess` konfiguriert. Untergeordnete Regeln dürfen standardmäßig strengere, aber nicht auf höherer Ebene verbotene Zugangswege wieder erlauben.

Identitätsabhängige Regeln (Rolle/Cohort) können erst ausgewertet werden, wenn eine Identität bekannt ist; sie dürfen nicht fälschlich auf anonyme Nutzer angewendet werden.

## 9. Teilnehmerliste

Für temporäre Nutzer gibt es sowohl eine systemweite Vorgabe als auch eine kursspezifische Option der Einschreibemethode, ob diese für reguläre Kursteilnehmende in Teilnehmerlisten sichtbar sein sollen. Administratoren und berechtigte Lehrende müssen temporäre Nutzer weiterhin administrieren können.

Die technische Umsetzung muss auf Moodle 4.5, 5.1 und 5.2 mit öffentlichen APIs verifiziert werden. Es darf kein Core-Patch vorausgesetzt werden.

## 10. E-Mail

E-Mails werden taskbasiert versendet. Konfigurierbar sind:

- optionale Absender-E-Mail-Adresse,
- maximales Versandvolumen pro rollierender Stunde: 10 / 50 / 100 / 500 / unbegrenzt.

Der Versand muss bei einer externen SMTP-Begrenzung von 100 E-Mails/Stunde zuverlässig unter dieser Grenze bleiben können.

## 11. Selbstaktivierungs-Aktivität

`mod_flexaccessactivation` kann in Kursen eingebettet werden. Ein temporärer Nutzer kann dort Name, E-Mail und konfigurierte Profilfelder ergänzen und seine Identität bestätigen. Die Umwandlung erfolgt auf derselben Moodle-`userid`; Lernaktivitäten bleiben dadurch dem Konto zugeordnet. Die Aktivierung verändert nicht automatisch die Laufzeit der Kurseinschreibung.

## 12. Systemweite Administration (`tool_flexaccess`)

Administratoren benötigen eine zentrale Oberfläche, ohne die Fachlogik aus `auth_flexaccess` oder `enrol_flexaccess` zu duplizieren. Sie soll mindestens:

- FlexAccess-verwaltete Nutzer nach Referenznummer, Nutzer-ID, Zustand und Accounttyp suchen und filtern,
- Details und Lifecycle-Zeitpunkte eines Accounts anzeigen,
- administrative Konvertierung eines `temporary user` in einen `authenticated user` auslösen,
- Accounts suspendieren, Ablauf/Deletion anstoßen und zulässige Bulk-Aktionen anbieten,
- Mailqueue, Fehlversuche, Retry-Zeitpunkte und die verbleibende FlexAccess-Sendekapazität der rollierenden Stunde anzeigen,
- fehlgeschlagene Queue-Einträge kontrolliert erneut freigeben,
- wirksame System-/Kategorie-/Kurs-Policies diagnostisch darstellen,
- auf Moodle-Logs/Events für Audit und Nachvollziehbarkeit verweisen.

Das Tool besitzt **keine eigene Account-, Token-, Mailqueue- oder Policy-Datenhaltung**. Mutationen werden ausschließlich über öffentliche Services der fachlich zuständigen Plugins ausgeführt.

## 13. Qualitätsanforderungen

- ausschließlich Moodle DML, Form-, Access-, Task-, Privacy-, Events-, Enrolment- und User-APIs,
- keine direkten `mysqli`/PDO-Zugriffe,
- keine gemeinsamen oder statischen Passwörter,
- keine Credentials in Hidden Fields,
- keine unvalidierten Redirects,
- kryptographisch zufällige, einmalige und zeitlich begrenzte Tokens; nur Hashes dauerhaft speichern,
- Rate Limiting für öffentliche Endpunkte,
- idempotente Cleanup-/Expiry-Verarbeitung,
- vollständige Capability-Checks,
- Privacy Provider für personenbezogene Plugin-Daten,
- PHPUnit und Behat für kritische Pfade,
- Moodle Coding Standards und dokumentierte CI-Gates.


## Zusatzanforderung: Zugangsschlüssel-Challenge

`auth_flexaccess` orchestriert vor temporärer Gast-Anmeldung und Schnellregistrierung optional eine kursbezogene Zugangsschlüssel-Challenge. Die Policy und Verifikation gehören `enrol_flexaccess`; `auth_flexaccess` darf weder System- noch Kurs-Hash lesen oder persistieren. Nach erfolgreicher Prüfung wird ausschließlich eine kurzlebige, kursgebundene Freigabe in der serverseitigen Session gespeichert. Normaler Login, normaler Moodle-Gastzugang und die spätere Selbstaktivierung bleiben unbeeinflusst.
