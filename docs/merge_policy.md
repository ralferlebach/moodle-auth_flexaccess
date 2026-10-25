# Generische Kontenzusammenführung (ab 2026102400, Stand 2026102500, auth_flexaccess #13)

Dieses Dokument beschreibt, wann und wie FlexAccess die Zusammenführung zweier oder mehrerer Moodle-Konten
derselben Person zulässt – unabhängig davon, wie das andere Konto entstanden ist (FlexAccess, lokal, LTI,
institutionell). Die Zusammenführung selbst führt `tool_mergeusers` aus; FlexAccess prüft sie in dessen
Transaktion, archiviert kollidierende Daten und gleicht seinen eigenen Zustand ab.

Code: `classes/local/merge_policy.php` (Matrix, Plan, institutionelle Bindung), `merge_guard.php` (Bewertung,
Nachbedingungen), `merge_coverage.php` (Tabellenabdeckung), `merge_evidence.php` (Archiv), `merge_registry.php`
(Freigabe, Register), `merge_service.php` (Abgleich), `mergeusers/*` (Tabellen-Merger).
Tests: `tests/merge_matrix_test.php` (echte Zusammenführungen über tool_mergeusers), `tests/lti_merge_test.php`,
`tests/merge_service_test.php`; Browser: tool_flexaccess `tests/playwright/merge-review.spec.js` (u. a. echte
OAuth-2-Anmeldung vor und nach der Zusammenführung).

## 1. Abgrenzung

- **Verknüpfen** einer externen Anmeldung (LTI `iss`/`sub`) mit einem bestehenden Konto ist keine
  Zusammenführung (siehe [lti_identities.md](lti_identities.md), Abschnitt 1).
- **Dauerhaft machen** eines temporären Zugangs geschieht am selben Konto (Persistenz), ohne Zusammenführung.
- **Zusammenführen** zweier Moodle-Nutzer verschiebt alle Daten der Quelle auf das Ziel.
- Haben **beide** Konten keinen FlexAccess-Zustand (kein FlexAccess-Konto, keine FlexAccess-Einschreibung),
  greift FlexAccess nicht ein und legt keinen Registereintrag an (Fall G); es entscheidet allein
  tool_mergeusers.
- Gleiche E-Mail-Adresse oder gleicher Name machen zwei Konten nur zu **Kandidaten**, nie zu einem Nachweis.

## 2. Herkunftstypen

| Herkunft (`merge_policy`) | Erkennung | Anmeldeweg |
|---|---|---|
| `ephemeral` | auth `flexaccess`, Kontotyp temporär | temporärer Zugang |
| `pendingcredential` | auth `flexaccess`, dauerhaft, noch ohne Kennwort (provisorisch) | Kennwort noch zu setzen |
| `flexaccess` | auth `flexaccess`, dauerhaft, eigenes Kennwort | Kennwortanmeldung |
| `localpassword` | auth `manual` oder `email` mit Kennwort | Kennwortanmeldung |
| `lti` | auth `lti` | LTI-Start (Bindungen ziehen mit um) |
| `institutional` | jedes andere Auth-Plugin (LDAP, OAuth 2, SAML, Shibboleth, CAS, DB, …) | institutionelle Anmeldung (SSO) |
| `nologin` | `nologin`, `webservice`, lokales Konto ohne Kennwort | keiner |

Das Ziel behält immer sein Auth-Plugin und sein Kennwort; ein generischer Merge verschiebt beides nicht.
Der Anmeldeweg der Quelle **endet** deshalb (Ausnahme: LTI-Bindungen, sie ziehen mit um). Die Vorschau
zeigt den behaltenen und die wegfallenden Anmeldewege (`keptroute`, `lostroutes`).

## 3. Matrix (Quelle → Ziel)

Jede Zusammenführung mit FlexAccess-Beteiligung braucht eine **Freigabe mit Eigentumsnachweis** (Abschnitt 4).
Die Matrix entscheidet zusätzlich, ob die Richtung überhaupt zulässig ist.

| Fall | Quelle → Ziel | Ergebnis |
|---|---|---|
| A | temporär oder provisorisch → Ziel mit Anmeldung (FlexAccess, lokal, LTI, institutionell, temporär) | erlaubt; das Ziel wird **nicht** befördert (temporäres Ziel bleibt temporär mit eigenem Ablauf und Beschränkung; ein dauerhaftes Ziel erbt keine Beschränkung und keinen Ablauf) |
| B | FlexAccess dauerhaft ↔ FlexAccess dauerhaft | erlaubt; Kennwort des Ziels bleibt, Kennwort der Quelle endet |
| C | FlexAccess dauerhaft → lokal / lokal → FlexAccess dauerhaft | erlaubt; Kennwort des Ziels bleibt |
| D | FlexAccess / lokal / LTI → institutionell | erlaubt, wenn die institutionelle Anmeldung des Ziels prüfbar ist (Abschnitt 7) |
| D | institutionell → nicht institutionell | **abgewiesen** (`sourcemanaged`): die SSO-Identität ginge verloren – umgekehrt zusammenführen |
| D | institutionell → institutionell, anderes Auth-Plugin | **abgewiesen** (`nosafedirection`) |
| E | LTI → FlexAccess dauerhaft / lokal / provisorisch | erlaubt; Bindungen und LTI-Mitgliedschaften ziehen um |
| E | FlexAccess dauerhaft / lokal → LTI, temporär oder provisorisch | **abgewiesen** (`sourcedurable`): das einzige Kennwort ginge verloren |
| E | LTI / provisorisch → temporär | **abgewiesen** (`wrongdirection`) |
| – | dauerhaft → Konto ohne Anmeldung | **abgewiesen** (`targetnologin`) |
| F | drei oder mehr Konten | ein Überlebender, alle anderen einzeln in ihn (kein Kettenmerge), Abschnitt 8 |
| G | beide ohne FlexAccess-Zustand | nicht Sache von FlexAccess |

Zusätzlich gesperrt: ein Ziel, das selbst schon weggemergt wurde (`targetmergedaway`), eine Quelle, die
schon in ein anderes Ziel ging (`sourcealreadymerged`), und jede Zusammenführung, solange tool_mergeusers einen
Tabellen-Merger verwendet, den FlexAccess nicht erweitern kann (`mergercoverage`, Abschnitt 5).

## 4. Eigentumsnachweis und Freigabe

**Jede** Zusammenführung mit FlexAccess-Beteiligung braucht vorher eine Freigabe – auch für temporäre und
provisorische Quellen. Der Besitz einer temporären Sitzung oder eines Links ist kein Nachweis, ein dauerhaftes
Konto zu besitzen. Es gibt **keine automatische Ausnahme**: Der einzige sichere Weg ohne Freigabe ist,
einen temporären Zugang am selben Konto dauerhaft zu machen (Persistenz); das ist keine Zusammenführung.

Eine Freigabe (`api::approve_merge($from, $to, true, $proof, $evidence)`, Seite
**tool_flexaccess → LTI-Identitäten → „Beliebiges Kontenpaar prüfen“**) braucht:

- die Berechtigung `moodle/user:update` im Systemkontext – eine angemeldete Besucherin kann ihren eigenen
  Merge nicht freigeben,
- die ausdrückliche Bestätigung der Administratorin bzw. des Administrators,
- eine **Nachweisart**: `reauthentication` (die Person hat sich vor der Administration mit beiden Konten
  angemeldet), `idp` (vom Identitätsanbieter bestätigt) oder `documents` (Ausweis, Studierendenakte,
  unterschriebener Antrag),
- eine **Beschreibung** der Prüfung mit mindestens 20 Zeichen ohne E-Mail-Adressen.

Die Freigabe gilt einen Tag (für die Zusammenführung; spätere Nachprüfungen eines erfolgten Merges bleiben
gültig), nur für die genannte Richtung und nur, wenn die Matrix die Richtung erlaubt. Ohne gültige Freigabe
weist FlexAccess den Merge in der Transaktion ab; tool_mergeusers rollt alles zurück, FlexAccess trägt die
Ablehnung danach als „refused“ ein. Ein Abgleich, der nachträglich (ohne die Transaktion von tool_mergeusers)
angestoßen wird, ändert ohne Freigabe nichts und setzt das Paar auf „review“.

## 5. Kollisionen: Archiv vor der Auflösung

tool_mergeusers verschiebt jede Zeile mit Nutzerbezug. Nur wo ein eindeutiger oder fachlicher Schlüssel zwei
Zeilen kollidieren lässt (seine „compound indexes“ und die Tabellen mit eigenem Merger), behält es eine
Zeile und löscht, nummeriert um oder vereinigt die andere – nur dort kann etwas verloren gehen. FlexAccess
liest diese Schlüssel aus der wirksamen Konfiguration von tool_mergeusers (Kern und Plugins, die das Werkzeug
kennt) und ersetzt den Standard-Merger sowie jeden bekannten Spezial-Merger (Bewertungen, Testversuche,
Abgaben, Abstimmungen, Lektionen) durch Unterklassen, die **unmittelbar vor** dem Verarbeiten einer Tabelle –
in derselben Transaktion – die kollidierenden Zeilen beider Nutzer in `auth_flexaccess_mergeevid` archivieren
und danach exakt wie das Original arbeiten. Regel je Tabelle (`merge_coverage`):

- **review** (Standard für alles, was nicht ausdrücklich harmlos ist, also auch für unbekannte Tabellen von
  Drittplugins): Bewertungen (nur wenn beide eine Endnote haben), Versuche, Abgaben, Abschlüsse, Kompetenzen,
  Badges, Richtlinienzustimmungen, Wiki, Umfragen, Workshop. Der Merge braucht eine Freigabe und bleibt
  `review` (`conflictsrecorded`), bis die Administration die archivierten Zeilen gesichtet und mit „Kollisionen
  geprüft“ bestätigt hat (`api::acknowledge_merge_conflicts()`; Entscheidung `reviewed`).
- **benign**: Mitgliedschaften, Abonnements, Favoriten, Zuletzt-besucht, Geräte, Autosaves, Rollen,
  Einschreibungen (fremder Methoden) – zwei Zeilen bedeuten dasselbe; archiviert und als erledigt markiert.
- **enrolment**: FlexAccess-Einschreibungen, Abschnitt 6.
- **Lücke** (`mergercoverage`): verwendet tool_mergeusers für eine Tabelle einen Merger, den FlexAccess nicht
  erweitert hat (eine Website-Einstellung `customdbsettings` oder ein neuer Spezial-Merger einer späteren
  Version), wird jede Zusammenführung mit FlexAccess-Beteiligung abgewiesen.

Tabellen ohne Kollisionsschlüssel werden nur verschoben; dort geht nichts verloren. Ein Rollback verwirft auch
das Archiv.

### Was archiviert wird (Datensparsamkeit, ab 2026102500)

Archiviert wird nur, was zum Verständnis einer Kollision nötig ist – eine **Positivliste je Tabelle**
(`merge_evidence::columns()`):

- immer: Zeilen-ID, Nutzer- und Schlüsselspalten,
- Zahlenspalten (Bewertungen, Zustände, Zeiten, IDs),
- Textspalten nur, wenn sie für die Tabelle ausdrücklich gelistet sind (Bewertungsfeedback und -information,
  Abgabestatus, Versuchszustand, Lektionsantwort, Umfrageantworten, Sprache einer Richtlinienzustimmung,
  App-ID und Plattform eines Geräts),
- **nie** eine Spalte, deren Name eine Zugangsinformation bezeichnet (`token`, `secret`, `passw…`, `hash`,
  `salt`, `apikey`, `privatekey`, `pushid`, `session`, `cookie`, `credential`, `uuid`, `signature`, `nonce`,
  `cipher` sowie `key`, `sid`, `pin`, `otp`). Ist eine solche Spalte Teil des Kollisionsschlüssels (z. B.
  `scopehash` bei `oauth2_refresh_token`, `pushid` bei `user_devices`), wird nur ein nicht umkehrbarer
  Fingerabdruck (`sha256:` + 16 Hex-Zeichen) gespeichert.

Textspalten unbekannter Tabellen werden nicht übernommen. Die Namen (nie die Werte) nicht archivierter Spalten
stehen in `_omitted`, damit die Kollision diagnostizierbar bleibt. Dieselbe Reduktion wird bei jeder Anzeige
(Seite „LTI-Identitäten“) und im Datenschutz-Export erneut angewandt.

**Altbestände:** Der Upgrade-Schritt `2026102500` reduziert alle bisher archivierten Zeilen auf diese Liste und
hält die Zahl der bereinigten Einträge je Tabelle in `auth_flexaccess/mergeevidencesanitised` fest. Versionen
2026102400 und 2026102401 konnten bei einer Kollision ganze Zeilen archivieren, bei `oauth2_refresh_token`
also das Refresh-Token der Quelle, bei `user_devices` die Push-ID. Diese Werte waren nur in der Datenbank und
im Datenschutz-Export der betroffenen Konten sichtbar, nicht auf der Prüfseite (beide Tabellen sind `benign`).
Wer 2026102401 produktiv eingesetzt hat, sollte für die in `mergeevidencesanitised` genannten Tabellen
`oauth2_refresh_token` die betroffenen Konten beim Identitätsanbieter abmelden bzw. ihre Refresh-Tokens dort
widerrufen; Moodle selbst verwendet das Token der zusammengeführten Quelle nicht mehr (tool_mergeusers löscht
deren Zeile).

### Grenzen und Last

Eine Zusammenführung archiviert höchstens `mergeevidencemaxrows` Zeilen (Standard 5000) und
`mergeevidencemaxbytes` Bytes (Standard 8 MB). Wird eine Grenze überschritten, wirft der Tabellen-Merger einen
Fehler, tool_mergeusers rollt die ganze Zusammenführung zurück, und FlexAccess trägt sie als `refused` mit
`evidencelimit` ein. Es gibt keine Zusammenführung mit ausgelassenen Nachweisen. Je Tabelle und Nutzerspalte
werden die kollidierenden Zeilen beider Nutzer mit zwei Abfragen als Recordset gelesen (keine Abfrage je
Schlüsselwert) und mit einem Sammel-Insert archiviert; gemessen in `merge_evidence_test` (25 und 500
Kollisionen, PostgreSQL und MariaDB).

### Aufbewahrung

Die geplante Aufgabe `purge_merge_evidence` (täglich) löscht erledigte Einträge (mit Entscheidung oder
`benign`) abgeglichener Zusammenführungen nach `mergeevidenceretention` Tagen (Standard 365, 0 = nie).
Offene Einträge und Zusammenführungen in Prüfung bleiben unabhängig vom Alter erhalten. Lesen und
Entscheiden erfordert `tool/flexaccess:recoversystem` und `moodle/user:update`; der Datenschutz-Export zeigt
jedem Konto nur seine eigene Seite der Kollision, die Begründung einer Entscheidung nur der entscheidenden
Person.

## 6. FlexAccess-Einschreibungen beider Konten

Sind beide Konten in derselben FlexAccess-Einschreibemethode eingeschrieben, behält tool_mergeusers die
Einschreibung des Ziels. FlexAccess archiviert vorher die der Quelle und übernimmt ihre Werte mit der
FlexAccess-Regel: aktiv gewinnt, der frühere Beginn und das spätere Ende gewinnen, „ohne Ende“ nur, wenn eine
der beiden es hatte – nie mehr, als eine der beiden hatte. Ergebnis je Einschreibung (Status, Beginn, Ende vor
und nach) steht im Register (`details.enrolmentmerges`).

Jeder archivierte Einschreibungseintrag hat einen von drei Zuständen:

| Zustand | `decision` | Wie er entsteht |
|---|---|---|
| offen | leer | Übernahme noch nicht gelungen (Methode oder Ziel-Einschreibung fehlt) |
| übernommen | `merged` | die Übernahme ist gelungen (automatisch beim Abgleich oder bei einer erneuten Prüfung) |
| ausdrücklich verworfen | `discarded` | Einzelentscheidung der Administration mit Begründung |

Solange ein Eintrag offen ist, steht der Merge auf `review` (`enrolmentspending`, `details.pendingenrolments`),
und die FlexAccess-Daten der Quelle bleiben erhalten. Eine erneute Prüfung nach Korrektur übernimmt die Werte
genau einmal. Soll eine Einschreibung nicht übernommen werden, entscheidet die Administration **je
Einschreibung** auf der Prüfseite („Nicht übernehmen“, `api::discard_merge_enrolment()`), mit einer Begründung
von mindestens 20 Zeichen ohne E-Mail-Adressen; festgehalten werden Entscheidung, Person, Zeit und Begründung,
die archivierten Werte bleiben. Die allgemeine Bestätigung „Kollisionen geprüft“ schließt nur Kollisionen der
Art `review`, **nie** Einschreibungen. Einschreibungen, die unter 2026102401 mit der allgemeinen Bestätigung
geschlossen wurden, öffnet der Upgrade-Schritt `2026102500` wieder. Andere Einschreibungen (manuell, LTI)
verändert FlexAccess nicht.

## 7. Institutionelle Anmeldungen

| Auth-Plugin | Wo die Bindung liegt | Prüfung |
|---|---|---|
| `oauth2` | `auth_oauth2_linked_login` (Aussteller + externer Benutzername → Moodle-Nutzer) | mindestens eine bestätigte Verknüpfung mit aktivem Aussteller; tool_mergeusers verschiebt die der Quelle und lässt die des Ziels unverändert |
| `ldap`, `cas`, `shibboleth`, `db`, `saml2`, `mnet` | Moodle-Benutzername (und Host) | Benutzername vorhanden |
| alle anderen | unbekannt | **abgewiesen** (`ssounsupported`) |

Bei der Freigabe wird die Bindung des Ziels festgehalten (Auth-Plugin, Benutzername, ID-Nummer, E-Mail,
Host und – bei OAuth 2 – die Verknüpfungen; `ssobinding`). Weicht sie bei der Zusammenführung davon ab
(anderer Benutzername, eine freigegebene Verknüpfung fehlt), wird der Merge abgewiesen (`ssochanged`); ein
OAuth-2-Ziel ohne bestätigte Verknüpfung ebenso (`ssounverified`). Nach der Zusammenführung prüft dieselbe
Bedingung als Nachbedingung. Die Playwright-Reise meldet sich über einen lokalen OAuth-2-Anbieter echt an,
führt das FlexAccess-Konto in das institutionelle Konto zusammen und meldet sich erneut an: dieselbe
Moodle-ID, mit dem Kurs des entfernten Kontos. LDAP, SAML und Shibboleth sind nicht als echter Dienst
getestet; ihre Bindung über den Benutzernamen wird geprüft.

## 8. Mehr als zwei Konten

`api::plan_merge([$a, $b, $c])` (`merge_policy::plan()`) liefert einen deterministischen Plan, unabhängig
von der Reihenfolge der IDs: Überlebender nach Herkunft (institutionell > FlexAccess dauerhaft = lokal > LTI >
provisorisch > temporär), dann Kursaktivität, dann das ältere Konto; jedes andere Konto ist ein eigener
Schritt mit eigener Freigabe. Zwei institutionelle Konten verschiedener Auth-Plugins oder ein gesperrter
Schritt setzen `review`.

## 9. Nachbedingungen

Zusätzlich zu den LTI-Nachbedingungen (`sourcebindings`, `sourceltiusers`, `duplicateltiusers`,
`targetlocked`, `targetlocklifted`, siehe lti_identities.md) gilt ein Merge erst als `reconciled`, wenn

- keine Kursleistungen mehr bei der Quelle liegen (`sourceuserdata`),
- keine ungeprüften Archiveinträge offen sind (`conflictsrecorded`),
- jede archivierte FlexAccess-Einschreibung übernommen ist (`enrolmentspending`) und
- die institutionelle Bindung des Ziels der Freigabe entspricht (`ssochanged`).

Erst dann entfernt FlexAccess Konto-Zeile, Tokens und wartende Mails der Quelle; die Konto-Zeile einer
Quelle wird vorher im Register archiviert (`details.sourceaccount`, mit `details.origins` und
`details.lostroutes`).

## 10. Reihenfolge, Wiederholung und Wiederherstellung

- Löschen der Quelle vor oder nach der Ad-hoc-Prüfung `reconcile_merge`: beide Reihenfolgen getestet.
- Jede Prüfung ist wiederholbar (`api::recheck_merge()`, Aufgabe `verify_merges`); bereits abgeglichene Paare
  werden mit der im Register gespeicherten Herkunft der Quelle beurteilt. Gleichzeitige Abgleiche desselben
  Paars sind über eine Sperre je Paar serialisiert (`locked`); in einem einzelnen PHP-Prozess ist das nicht
  testbar (Sperren sind je Datenbanksitzung wiedereintrittsfähig).
- Rückgängig machen: siehe lti_identities.md, Abschnitt 5; Archiv und archivierte Konto-Zeile zeigen den
  Zustand vor der Zusammenführung.

## 11. Tabellenabdeckung von tool_mergeusers

Ermittelt am 25.10.2026 mit `php auth/flexaccess/cli/mergeusers_coverage.php --format=markdown` (FlexAccess
2026102500, tool_mergeusers 2026081302 gepinnt) auf den vier unterstützten Moodle-Versionen. „moved“: ohne
Kollisionsschlüssel, die Zeilen ziehen nur um. Auf allen vier Versionen gibt es keine Lücke; die Unterschiede
sind entfernte Kernmodule (Umfrage und Atto ab 5.0).

### Moodle 4.5

Moodle 4.5.15 (Build: 20261005), tool_mergeusers 2026081302: moved 453, review 21, benign 24, excluded 9, dedicated 4, gap 0
- review: `assign_grades` (assignment,attemptnumber), `assign_submission` (assignment,attemptnumber,groupid), `assign_user_flags` (assignment), `badge_issued` (badgeid), `choice_answers` (choiceid,optionid), `competency_usercomp` (competencyid), `competency_usercompcourse` (competencyid,courseid), `competency_usercompplan` (competencyid,planid), `course_completion_crit_compl` (course,criteriaid), `course_completions` (course), `course_modules_completion` (coursemoduleid), `forum_grades` (forum,itemnumber), `grade_grades` (itemid), `h5pactivity_attempts` (attempt,h5pactivityid), `lesson_attempts` (lessonid), `quiz_attempts` (attempt,quiz), `survey_answers` (survey,question), `tool_policy_acceptances` (policyversionid), `wiki_pages` (subwikiid,title), `wiki_subwikis` (groupid,wikiid), `workshop_aggregations` (workshopid)
- benign: `assign_user_mapping` (assignment), `auth_lti_linked_login` (issuer256,sub256), `auth_oauth2_linked_login` (issuerid,username), `badge_backpack` (externalbackpackid), `block_recentlyaccesseditems` (cmid,courseid), `cohort_members` (cohortid), `course_modules_viewed` (coursemoduleid), `editor_atto_autosave` (contextid,elementid,pagehash), `favourite` (component,contextid,itemid,itemtype), `forum_digests` (forum,maildigest), `forum_discussion_subs` (discussion), `forum_subscriptions` (forum), `groups_members` (groupid), `message_user_actions` (action,messageid), `oauth2_refresh_token` (issuerid,scopehash), `post` (id), `role_assignments` (contextid,roleid), `tag_instance` (component,contextid,itemid,itemtype,tagid), `tiny_autosave` (contextid,elementid,pagehash), `tool_cohortroles` (cohortid,roleid), `tool_monitor_history` (sid,timesent), `user_devices` (pushid), `user_enrolments` (enrolid), `user_lastaccess` (courseid)
- excluded: `auth_flexaccess_account` (not merged), `auth_flexaccess_mailqueue` (not merged), `auth_flexaccess_merge` (not merged), `auth_flexaccess_mergeevid` (not merged), `auth_flexaccess_token` (not merged), `my_pages` (not merged), `user_info_data` (not merged), `user_preferences` (not merged), `user_private_key` (not merged)
- dedicated: `lesson_branch` (handled by the merger of another table), `lesson_grades` (handled by the merger of another table), `lesson_timer` (handled by the merger of another table), `quiz_grades` (handled by the merger of another table)
- gap: -

### Moodle 5.0

Moodle 5.0.11 (Build: 20261005), tool_mergeusers 2026081302: moved 448, review 20, benign 23, excluded 9, dedicated 4, gap 0
- review: `assign_grades` (assignment,attemptnumber), `assign_submission` (assignment,attemptnumber,groupid), `assign_user_flags` (assignment), `badge_issued` (badgeid), `choice_answers` (choiceid,optionid), `competency_usercomp` (competencyid), `competency_usercompcourse` (competencyid,courseid), `competency_usercompplan` (competencyid,planid), `course_completion_crit_compl` (course,criteriaid), `course_completions` (course), `course_modules_completion` (coursemoduleid), `forum_grades` (forum,itemnumber), `grade_grades` (itemid), `h5pactivity_attempts` (attempt,h5pactivityid), `lesson_attempts` (lessonid), `quiz_attempts` (attempt,quiz), `tool_policy_acceptances` (policyversionid), `wiki_pages` (subwikiid,title), `wiki_subwikis` (groupid,wikiid), `workshop_aggregations` (workshopid)
- benign: `assign_user_mapping` (assignment), `auth_lti_linked_login` (issuer256,sub256), `auth_oauth2_linked_login` (issuerid,username), `badge_backpack` (externalbackpackid), `block_recentlyaccesseditems` (cmid,courseid), `cohort_members` (cohortid), `course_modules_viewed` (coursemoduleid), `favourite` (component,contextid,itemid,itemtype), `forum_digests` (forum,maildigest), `forum_discussion_subs` (discussion), `forum_subscriptions` (forum), `groups_members` (groupid), `message_user_actions` (action,messageid), `oauth2_refresh_token` (issuerid,scopehash), `post` (id), `role_assignments` (contextid,roleid), `tag_instance` (component,contextid,itemid,itemtype,tagid), `tiny_autosave` (contextid,elementid,pagehash), `tool_cohortroles` (cohortid,roleid), `tool_monitor_history` (sid,timesent), `user_devices` (pushid), `user_enrolments` (enrolid), `user_lastaccess` (courseid)
- excluded: `auth_flexaccess_account` (not merged), `auth_flexaccess_mailqueue` (not merged), `auth_flexaccess_merge` (not merged), `auth_flexaccess_mergeevid` (not merged), `auth_flexaccess_token` (not merged), `my_pages` (not merged), `user_info_data` (not merged), `user_preferences` (not merged), `user_private_key` (not merged)
- dedicated: `lesson_branch` (handled by the merger of another table), `lesson_grades` (handled by the merger of another table), `lesson_timer` (handled by the merger of another table), `quiz_grades` (handled by the merger of another table)
- gap: -

### Moodle 5.2

Moodle 5.2.4+ (Build: 20261021), tool_mergeusers 2026081302: moved 451, review 20, benign 23, excluded 9, dedicated 4, gap 0
- review: `assign_grades` (assignment,attemptnumber), `assign_submission` (assignment,attemptnumber,groupid), `assign_user_flags` (assignment), `badge_issued` (badgeid), `choice_answers` (choiceid,optionid), `competency_usercomp` (competencyid), `competency_usercompcourse` (competencyid,courseid), `competency_usercompplan` (competencyid,planid), `course_completion_crit_compl` (course,criteriaid), `course_completions` (course), `course_modules_completion` (coursemoduleid), `forum_grades` (forum,itemnumber), `grade_grades` (itemid), `h5pactivity_attempts` (attempt,h5pactivityid), `lesson_attempts` (lessonid), `quiz_attempts` (attempt,quiz), `tool_policy_acceptances` (policyversionid), `wiki_pages` (subwikiid,title), `wiki_subwikis` (groupid,wikiid), `workshop_aggregations` (workshopid)
- benign: `assign_user_mapping` (assignment), `auth_lti_linked_login` (issuer256,sub256), `auth_oauth2_linked_login` (issuerid,username), `badge_backpack` (externalbackpackid), `block_recentlyaccesseditems` (cmid,courseid), `cohort_members` (cohortid), `course_modules_viewed` (coursemoduleid), `favourite` (component,contextid,itemid,itemtype), `forum_digests` (forum,maildigest), `forum_discussion_subs` (discussion), `forum_subscriptions` (forum), `groups_members` (groupid), `message_user_actions` (action,messageid), `oauth2_refresh_token` (issuerid,scopehash), `post` (id), `role_assignments` (contextid,roleid), `tag_instance` (component,contextid,itemid,itemtype,tagid), `tiny_autosave` (contextid,elementid,pagehash), `tool_cohortroles` (cohortid,roleid), `tool_monitor_history` (sid,timesent), `user_devices` (pushid), `user_enrolments` (enrolid), `user_lastaccess` (courseid)
- excluded: `auth_flexaccess_account` (not merged), `auth_flexaccess_mailqueue` (not merged), `auth_flexaccess_merge` (not merged), `auth_flexaccess_mergeevid` (not merged), `auth_flexaccess_token` (not merged), `my_pages` (not merged), `user_info_data` (not merged), `user_preferences` (not merged), `user_private_key` (not merged)
- dedicated: `lesson_branch` (handled by the merger of another table), `lesson_grades` (handled by the merger of another table), `lesson_timer` (handled by the merger of another table), `quiz_grades` (handled by the merger of another table)
- gap: -

### Moodle 5.3

Moodle 5.3+ (Build: 20261013), tool_mergeusers 2026081302: moved 460, review 20, benign 23, excluded 9, dedicated 4, gap 0
- review: `assign_grades` (assignment,attemptnumber), `assign_submission` (assignment,attemptnumber,groupid), `assign_user_flags` (assignment), `badge_issued` (badgeid), `choice_answers` (choiceid,optionid), `competency_usercomp` (competencyid), `competency_usercompcourse` (competencyid,courseid), `competency_usercompplan` (competencyid,planid), `course_completion_crit_compl` (course,criteriaid), `course_completions` (course), `course_modules_completion` (coursemoduleid), `forum_grades` (forum,itemnumber), `grade_grades` (itemid), `h5pactivity_attempts` (attempt,h5pactivityid), `lesson_attempts` (lessonid), `quiz_attempts` (attempt,quiz), `tool_policy_acceptances` (policyversionid), `wiki_pages` (subwikiid,title), `wiki_subwikis` (groupid,wikiid), `workshop_aggregations` (workshopid)
- benign: `assign_user_mapping` (assignment), `auth_lti_linked_login` (issuer256,sub256), `auth_oauth2_linked_login` (issuerid,username), `badge_backpack` (externalbackpackid), `block_recentlyaccesseditems` (cmid,courseid), `cohort_members` (cohortid), `course_modules_viewed` (coursemoduleid), `favourite` (component,contextid,itemid,itemtype), `forum_digests` (forum,maildigest), `forum_discussion_subs` (discussion), `forum_subscriptions` (forum), `groups_members` (groupid), `message_user_actions` (action,messageid), `oauth2_refresh_token` (issuerid,scopehash), `post` (id), `role_assignments` (contextid,roleid), `tag_instance` (component,contextid,itemid,itemtype,tagid), `tiny_autosave` (contextid,elementid,pagehash), `tool_cohortroles` (cohortid,roleid), `tool_monitor_history` (sid,timesent), `user_devices` (pushid), `user_enrolments` (enrolid), `user_lastaccess` (courseid)
- excluded: `auth_flexaccess_account` (not merged), `auth_flexaccess_mailqueue` (not merged), `auth_flexaccess_merge` (not merged), `auth_flexaccess_mergeevid` (not merged), `auth_flexaccess_token` (not merged), `my_pages` (not merged), `user_info_data` (not merged), `user_preferences` (not merged), `user_private_key` (not merged)
- dedicated: `lesson_branch` (handled by the merger of another table), `lesson_grades` (handled by the merger of another table), `lesson_timer` (handled by the merger of another table), `quiz_grades` (handled by the merger of another table)
- gap: -

Eine Website-Einstellung `customdbsettings` von tool_mergeusers hat Vorrang vor den Hooks; setzt sie einen
eigenen Merger für eine Tabelle, meldet die Ermittlung eine Lücke und FlexAccess weist Zusammenführungen ab.
