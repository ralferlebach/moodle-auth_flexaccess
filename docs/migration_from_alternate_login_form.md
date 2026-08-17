# Migration vom bisherigen alternate_login_form.php

Der Altansatz wird nicht portiert, sondern ersetzt. Insbesondere entfallen:

- direkte `mysqli`-Verbindung neben Moodles DML,
- `LOCK TABLES` auf `user`/`sessions`,
- Suche nach einem freien `guest.*`-Poolkonto,
- gemeinsames festes Gastpasswort,
- Übergabe von Benutzername/Passwort in Hidden Fields,
- fest verdrahtetes Login-HTML und Theme-Assets.

Migration: Plugin installieren → global aktivieren → `enrol_flexaccess` in Zielkursen konfigurieren → Deep-Link-Flows testen → bisherige Alternate-Login-Konfiguration deaktivieren → Poolaccounts nach Daten-/Sessionprüfung stilllegen.
