<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['accessbadgate'] = 'Das Kurs-/Zugangskennwort ist nicht korrekt.';
$string['accessbadgatedomain'] = 'Die Registrierung für diesen Kurs ist auf bestimmte E-Mail-Domains beschränkt. Bitte verwenden Sie eine Adresse aus einer zugelassenen Domain.';
$string['accessbadkey'] = 'Dieser Zugangsschlüssel ist nicht korrekt.';
$string['accessclosed'] = 'Temporärer Zugang ist derzeit nicht verfügbar.';
$string['accesscolaccount'] = 'Zugang mit Account';
$string['accesscolnoaccount'] = 'Zugang ohne eigenes Konto';
$string['accesscoltemporary'] = 'Temporärer Gastaccount';
$string['accessenterkey'] = 'Zugangsschlüssel';
$string['accessfull'] = 'Die maximale Teilnehmerzahl ist erreicht. Bitte versuchen Sie es später erneut.';
$string['accessgateexpired'] = 'Ihre Zugangsfreigabe ist abgelaufen. Bitte geben Sie das Kurs-/Zugangskennwort erneut ein.';
$string['accessgranted'] = 'Temporärer Zugang gewährt. Willkommen!';
$string['accessguestlimitations'] = 'Als Gast können Sie den Kurs nur ansehen. Sie können nichts abgeben, keine Tests bearbeiten, nicht in Foren schreiben und keinen Fortschritt speichern.';
$string['accessintro'] = 'Sie erhalten gleich temporären Zugang zu „{$a}". Ihr Fortschritt kann später durch Aktivierung Ihres Kontos gesichert werden.';
$string['accesskeyblocked'] = 'Zu viele fehlerhafte Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.';
$string['accessloginrefused'] = 'Das Konto konnte nicht angemeldet werden. Bitte wenden Sie sich an das Kursteam.';
$string['accessnoaccountoptions'] = 'Kontobasierter Zugang ist für diesen Kurs nicht verfügbar.';
$string['accessnotallowed'] = 'Für diesen Kurs wird kein temporärer Zugang angeboten.';
$string['accessnotenabled'] = 'FlexAccess ist für diesen Kurs nicht aktiviert.';
$string['accessorguest'] = 'Als Gast fortfahren';
$string['accessorlogin'] = 'Sie haben bereits ein Konto? Anmelden';
$string['accessormagic'] = 'Stattdessen mit E-Mail-Link anmelden';
$string['accessorregisterintro'] = 'Möchten Sie Ihren Fortschritt lieber gleich dauerhaft sichern?';
$string['accessprovider'] = 'Kurszugang ohne eigenes Konto';
$string['accessratelimited'] = 'Zu viele Versuche aus Ihrem Netzwerk. Bitte warten Sie einige Minuten und versuchen Sie es erneut.';
$string['accessregister'] = 'Für ein dauerhaftes Konto registrieren';
$string['accesstitle'] = 'Temporärer Kurszugang';
$string['accesstitlenotemp'] = 'Kurszugang';
$string['accessunavailable'] = 'Dieser Kurs ist nicht für den FlexAccess-Zugang verfügbar.';
$string['accountstate_active'] = 'Aktiv';
$string['accountstate_ephemeral'] = 'Flüchtig';
$string['accountstate_expired'] = 'Abgelaufen';
$string['accountstate_pendingcredential'] = 'Wartet auf Passwort';
$string['accountstate_provisional'] = 'Vorläufig';
$string['accountstate_suspended'] = 'Gesperrt';
$string['accounttype_authenticated'] = 'Authentifizierter Nutzer';
$string['accounttype_temporary'] = 'Temporärer Nutzer';
$string['checklifecycle'] = 'FlexAccess-Kontolebenszyklus';
$string['checklifecycleaction'] = 'FlexAccess-Systemstatus öffnen';
$string['checklifecyclemismatch'] = '{$a} Inkonsistenzen bei FlexAccess-Konten gefunden.';
$string['checklifecycleok'] = 'Alle FlexAccess-Konten sind konsistent zu ihrem Lebenszyklus-Zustand.';
$string['eventidentitymergeaction'] = 'FlexAccess-Aktion zur Identitätszusammenführung';
$string['eventloginrefused'] = 'FlexAccess-Anmeldung abgewiesen';
$string['externalloginrefused'] = 'Anmeldung abgelehnt: Dieses FlexAccess-Konto kann für diese Anmeldung derzeit nicht verwendet werden (zum Beispiel, weil es abgelaufen oder noch nicht vervollständigt ist). Melden Sie sich mit Ihrem FlexAccess-Zugang an oder wenden Sie sich an das Kursteam.';
$string['lifecycletargetnotreached'] = 'Das Konto von Nutzer {$a} konnte nicht in den Zielzustand gebracht werden; es wurde nichts geändert.';
$string['ltibindrefused'] = 'Dieser FlexAccess-Zugang kann nicht mit dem Konto Ihrer Lernplattform verknüpft werden. Verknüpfen lässt sich nur ein dauerhaftes FlexAccess-Konto mit bestätigter E-Mail-Adresse und eigenem Passwort. Machen Sie Ihren Zugang zuerst dauerhaft und starten Sie die Aktivität dann erneut aus Ihrer Lernplattform.';
$string['magicconfirmbutton'] = 'Anmelden';
$string['magicconfirmintro'] = 'Klicken Sie auf die Schaltfläche, um sich mit diesem einmaligen Link anzumelden.';
$string['magicdisabled'] = 'Die Anmeldung per E-Mail-Link ist nicht verfügbar.';
$string['magicemailbody'] = 'Öffnen Sie diesen Link, um sich anzumelden. Er funktioniert einmalig und läuft bald ab: {$a}';
$string['magicemailsubject'] = 'Ihr Anmeldelink';
$string['magicintro'] = 'Geben Sie Ihre E-Mail-Adresse ein, und wir senden Ihnen einen einmaligen Anmeldelink — ganz ohne Passwort.';
$string['magicinvalid'] = 'Dieser Anmeldelink ist ungültig, abgelaufen oder wurde bereits verwendet.';
$string['magicsent'] = 'Falls ein Konto für {$a} existiert, ist ein Anmeldelink unterwegs. Bitte prüfen Sie Ihre E-Mails.';
$string['magicsubmit'] = 'Anmeldelink senden';
$string['magicsuccess'] = 'Sie sind jetzt angemeldet.';
$string['magictitle'] = 'Mit E-Mail-Link anmelden';
$string['maillimitperhour'] = 'Maximale FlexAccess-E-Mails pro rollierender Stunde';
$string['maillimitperhour_desc'] = 'Begrenzt ausschließlich FlexAccess-Mails. Unbegrenzt deaktiviert das FlexAccess-Throttling.';
$string['mailsendfailed'] = 'FlexAccess konnte die Follow-up-E-Mail nicht versenden.';
$string['mergeevidencecaptured'] = 'FlexAccess: {$a->count} widersprüchliche(r) Datensatz/Datensätze in {$a->table} als Nachweis zur Prüfung gesichert';
$string['mergereconciled'] = 'FlexAccess: Identitätszusammenführung abgeglichen ({$a->status}; offene Nachbedingungen: {$a->reasons})';
$string['mergerefused_duplicateltiusers'] = 'FlexAccess lehnt diese Zusammenführung ab: Beide Nutzer sind Mitglied desselben veröffentlichten LTI-Tools, der behaltene Nutzer hätte also zwei Ziele für die Notenrückgabe. Entfernen Sie zuerst eine Mitgliedschaft im Kurs.';
$string['mergerefused_evidencelimit'] = 'FlexAccess lehnt diese Zusammenführung ab: Die kollidierenden Datensätze beider Nutzer ({$a->rows} Zeilen, {$a->bytes} Bytes bis {$a->table}) überschreiten die Grenzen des Archivs ({$a->maxrows} Zeilen, {$a->maxbytes} Bytes). Es wurde nichts geändert. Erhöhen Sie die Grenzen in den FlexAccess-Einstellungen oder bereinigen Sie die doppelten Datensätze zuerst.';
$string['mergerefused_locked'] = 'FlexAccess bearbeitet gerade eine andere Zusammenführung dieser beiden Konten. Es wurde nichts geändert; bitte die Zusammenführung gleich erneut starten.';
$string['mergerefused_mergercoverage'] = 'FlexAccess lehnt diese Zusammenführung ab: Das Werkzeug »Nutzer zusammenführen« verwendet einen Tabellen-Merger, den FlexAccess nicht erweitern kann (eine Website-Einstellung oder eine neuere Version des Werkzeugs). Kollidierende Kursleistungen beider Nutzer könnten daher nicht vorher gesichert werden. Entfernen Sie die eigene Merger-Einstellung oder aktualisieren Sie FlexAccess.';
$string['mergerefused_needsapproval'] = 'FlexAccess lehnt diese Zusammenführung ab: Jede Zusammenführung mit FlexAccess-Beteiligung braucht eine Freigabe. Eine Administratorin bzw. ein Administrator muss zuerst auf der FlexAccess-Identitätenseite mit einem Eigentumsnachweis bestätigen, dass beide Konten derselben Person gehören.';
$string['mergerefused_nosafedirection'] = 'FlexAccess lehnt diese Zusammenführung ab: Beide Nutzer melden sich über verschiedene institutionelle Anmeldeverfahren an. Es gibt keine Richtung, in der keiner seine Anmeldung verliert; dies muss eine Administratorin bzw. ein Administrator außerhalb der Zusammenführung entscheiden.';
$string['mergerefused_sourcealreadymerged'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu entfernende Nutzer wurde bereits mit einem anderen Konto zusammengeführt.';
$string['mergerefused_sourcebindings'] = 'FlexAccess lehnt diese Zusammenführung ab: Eine LTI-Verknüpfung würde weiterhin auf den zu entfernenden Nutzer zeigen.';
$string['mergerefused_sourcedurable'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu entfernende Nutzer hat ein eigenes Kennwort (ein dauerhaftes FlexAccess- oder lokales Konto), und der zu behaltende Nutzer hat keine eigene interaktive Anmeldung (ein LTI-Konto oder ein FlexAccess-Zugang, der temporär ist oder noch auf sein Kennwort wartet). Die Person verlöre ihre einzige Kennwortanmeldung. Führen Sie in der anderen Richtung zusammen.';
$string['mergerefused_sourceltiusers'] = 'FlexAccess lehnt diese Zusammenführung ab: Eine LTI-Mitgliedschaft (Notenrückgabe) würde weiterhin auf den zu entfernenden Nutzer zeigen.';
$string['mergerefused_sourcemanaged'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu entfernende Nutzer meldet sich über eine institutionelle Anmeldung (Single Sign-on) an; diese Anmeldung ginge verloren. Führen Sie in der anderen Richtung zusammen (das institutionelle Konto behalten).';
$string['mergerefused_ssochanged'] = 'FlexAccess lehnt diese Zusammenführung ab: Die institutionelle Anmeldung des zu behaltenden Nutzers hat sich seit der Freigabe geändert. Prüfen Sie die Zusammenführung und geben Sie sie erneut frei.';
$string['mergerefused_ssounsupported'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu behaltende Nutzer meldet sich über ein institutionelles Verfahren an, dessen Bindung FlexAccess nach einer Zusammenführung nicht prüfen kann.';
$string['mergerefused_ssounverified'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu behaltende Nutzer hat keine verknüpfte Anmeldung bei einem aktiven OAuth-2-Dienst; es ist nicht belegt, dass seine institutionelle Anmeldung funktioniert.';
$string['mergerefused_targetmergedaway'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu behaltende Nutzer wurde selbst bereits in ein anderes Konto zusammengeführt. Führen Sie stattdessen in jenes Konto zusammen.';
$string['mergerefused_targetmissing'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu behaltende Nutzer existiert nicht.';
$string['mergerefused_targetnologin'] = 'FlexAccess lehnt diese Zusammenführung ab: Der zu behaltende Nutzer hat kein eigenes Anmeldeverfahren; die Person könnte sich nicht mehr anmelden.';
$string['mergerefused_wrongdirection'] = 'FlexAccess lehnt diese Zusammenführung ab: Ein dauerhaftes Konto würde in einen temporären FlexAccess-Zugang zusammengeführt. Führen Sie in der anderen Richtung zusammen (das dauerhafte Konto behalten).';
$string['mismatch_active_restricted'] = 'Aktives Konto trägt noch die FlexAccess-Restriktionsrolle';
$string['mismatch_active_suspended'] = 'Aktives Konto, aber der Moodle-Nutzer ist gesperrt';
$string['mismatch_live_suspended'] = 'Gültiges temporäres oder wartendes Konto, aber der Moodle-Nutzer ist gesperrt';
$string['mismatch_locked_unsuspended'] = 'Abgelaufenes oder gesperrtes Konto, aber der Moodle-Nutzer ist nicht gesperrt';
$string['mismatch_orphan_restriction'] = 'Restriktionsrolle ohne FlexAccess-Konto';
$string['mismatch_overdue'] = 'Temporäres Konto nach Ablaufzeit, noch nicht als abgelaufen markiert';
$string['mismatch_temporary_unrestricted'] = 'Temporäres oder wartendes Konto ohne FlexAccess-Restriktionsrolle';
$string['mismatch_type_state'] = 'Kontotyp und Zustand widersprechen sich';
$string['persistconfirmbutton'] = 'E-Mail-Adresse bestätigen';
$string['persistconfirmintro'] = 'Klicken Sie auf die Schaltfläche, um Ihre E-Mail-Adresse zu bestätigen und Ihr Konto dauerhaft zu behalten.';
$string['persistemailbody'] = 'Um Ihren Fortschritt zu behalten und Ihr Konto dauerhaft zu machen, öffnen Sie diesen Link: {$a}';
$string['persistemailsubject'] = 'Konto dauerhaft machen';
$string['persistforeignlock'] = 'Dieses Konto ist derzeit gesperrt. Bitte wenden Sie sich an das Kursteam.';
$string['persisthintcta'] = 'Zugang jetzt dauerhaft sichern';
$string['persisthinttext'] = 'Sie nutzen einen temporären Zugang zu diesem Kurs. Ihr Fortschritt kann dauerhaft gesichert werden.';
$string['persistintro'] = 'Sie verwenden ein temporäres Konto. Legen Sie E-Mail, Namen und ein Passwort fest, um es dauerhaft zu machen — Ihr Fortschritt bleibt erhalten und Sie können sich später erneut anmelden.';
$string['persistinvalid'] = 'Dieser Aktivierungslink ist ungültig, abgelaufen oder wurde bereits verwendet.';
$string['persistnotapplicable'] = 'Ihr Konto ist bereits dauerhaft, es gibt nichts zu konvertieren.';
$string['persistsubmit'] = 'Konto dauerhaft machen';
$string['persistsuccess'] = 'Ihr Konto ist jetzt dauerhaft. Ihre Ergebnisse und Ihr Zugang bleiben erhalten.';
$string['persisttitle'] = 'Konto behalten';
$string['persistverificationsent'] = 'Wir haben einen Bestätigungslink an {$a} gesendet. Öffnen Sie ihn, um Ihr Konto dauerhaft zu machen; danach können Sie sich mit E-Mail und Passwort anmelden.';
$string['pluginname'] = 'FlexAccess-Authentifizierung';
$string['privacy:metadata:account'] = 'FlexAccess-Metadaten zum Account-Lebenszyklus.';
$string['privacy:metadata:account:accountstate'] = 'FlexAccess-Lifecycle-Status.';
$string['privacy:metadata:account:accounttype'] = 'Accounttyp temporary user oder authenticated user.';
$string['privacy:metadata:account:batchcredential'] = 'Ob das Konto ein ausgegebenes, wiederverwendbares Credential besitzt (Zugangslisten-Karte).';
$string['privacy:metadata:account:lockedby'] = 'Ob die Sperre des Moodle-Nutzers durch den FlexAccess-Lebenszyklus gesetzt wurde.';
$string['privacy:metadata:account:referencecode'] = 'Administrative Referenznummer.';
$string['privacy:metadata:account:sourcecmid'] = 'Die Aktivität, aus der ein temporäres Konto erstellt wurde.';
$string['privacy:metadata:account:sourcecourseid'] = 'Der Kurs, aus dem ein temporäres Konto erstellt wurde.';
$string['privacy:metadata:account:timecreated'] = 'Wann der Kontodatensatz erstellt wurde.';
$string['privacy:metadata:account:timeexpires'] = 'Wann das temporäre Konto abläuft.';
$string['privacy:metadata:account:userid'] = 'Moodle-Nutzer-ID.';
$string['privacy:metadata:mail'] = 'Metadaten zu wartenden FlexAccess-E-Mails.';
$string['privacy:metadata:mail:mailtype'] = 'Semantischer Mailtyp.';
$string['privacy:metadata:mail:payloadjson'] = 'Der Inhalt der eingereihten Nachricht (Betreff und Text).';
$string['privacy:metadata:mail:recipient'] = 'E-Mail-Empfänger.';
$string['privacy:metadata:mail:status'] = 'Der Zustellstatus der eingereihten Nachricht.';
$string['privacy:metadata:mail:timecreated'] = 'Wann die Nachricht eingereiht wurde.';
$string['privacy:metadata:mail:userid'] = 'Zugehörige Moodle-Nutzer-ID, falls vorhanden.';
$string['privacy:metadata:merge'] = 'Identitätszusammenführungen, die FlexAccess abgeglichen hat oder prüfen muss.';
$string['privacy:metadata:merge:approvedby'] = 'Die Administratorin bzw. der Administrator, die bzw. der bestätigt hat, dass beide Konten derselben Person gehören.';
$string['privacy:metadata:merge:details'] = 'Das Ergebnis des Abgleichs je Datensatz.';
$string['privacy:metadata:merge:fromuserid'] = 'Der Nutzer, der zusammengeführt (entfernt) wurde.';
$string['privacy:metadata:merge:mergelogid'] = 'Der Protokolleintrag von tool_mergeusers, der die Zusammenführung belegt.';
$string['privacy:metadata:merge:proofevidence'] = 'Die Beschreibung des unabhängigen Eigentumsnachweises durch die Administratorin bzw. den Administrator.';
$string['privacy:metadata:merge:prooftype'] = 'Die Art des Eigentumsnachweises, auf dem die Freigabe beruht.';
$string['privacy:metadata:merge:reasons'] = 'Nicht erfüllte Nachbedingungen.';
$string['privacy:metadata:merge:ssobinding'] = 'Die institutionelle Anmeldung des behaltenen Nutzers (Anmeldeverfahren, Anmeldename, ID-Nummer, E-Mail-Adresse, verknüpfte Anmeldungen) zum Zeitpunkt der Freigabe.';
$string['privacy:metadata:merge:status'] = 'Der Stand des Abgleichs.';
$string['privacy:metadata:merge:timeapproved'] = 'Wann die Zusammenführung freigegeben wurde.';
$string['privacy:metadata:merge:timecreated'] = 'Wann die Zusammenführung erfasst wurde.';
$string['privacy:metadata:merge:touserid'] = 'Der Nutzer, der behalten wurde.';
$string['privacy:metadata:mergeevid'] = 'Widersprüchliche Kursleistungen beider Nutzer einer Zusammenführung, als Nachweis gesichert, bevor die Zusammenführung sie auflöste.';
$string['privacy:metadata:mergeevid:acknowledgedby'] = 'Die Administratorin bzw. der Administrator, die bzw. der den Nachweis geprüft hat.';
$string['privacy:metadata:mergeevid:decisionreason'] = 'Die Begründung der Administration, eine gesicherte Einschreibung nicht zu übernehmen.';
$string['privacy:metadata:mergeevid:fromuserid'] = 'Der Nutzer, der zusammengeführt (entfernt) wurde.';
$string['privacy:metadata:mergeevid:keyvalues'] = 'Die Werte des Schlüssels, auf dem die Datensätze beider Nutzer kollidierten (zum Beispiel die Bewertungsposition oder Aktivität).';
$string['privacy:metadata:mergeevid:sourcerows'] = 'Die kollidierenden Datensätze des entfernten Nutzers vor der Zusammenführung, beschränkt auf IDs, Zahlenwerte (Bewertungen, Zustände, Zeiten) und gelistete Textspalten; nie Zugangsdaten.';
$string['privacy:metadata:mergeevid:tablename'] = 'Die Tabelle der widersprüchlichen Datensätze.';
$string['privacy:metadata:mergeevid:targetrows'] = 'Die kollidierenden Datensätze des behaltenen Nutzers vor der Zusammenführung, ebenso beschränkt.';
$string['privacy:metadata:mergeevid:timecreated'] = 'Wann der Nachweis gesichert wurde.';
$string['privacy:metadata:mergeevid:touserid'] = 'Der Nutzer, der behalten wurde.';
$string['privacy:metadata:preference:followupsent'] = 'Der Zeitpunkt, zu dem eine einmalige Persistenz-Erinnerung gesendet wurde, damit sie nicht wiederholt wird.';
$string['privacy:metadata:preference:pendingcredential'] = 'Zeitpunkt, ab dem eine administrative Konvertierung darauf wartet, dass der Nutzer ein Passwort setzt.';
$string['privacy:metadata:preference:pendingemail'] = 'Eine bei der Kontoverstetigung eingegebene E-Mail-Adresse, die auf Verifizierung wartet.';
$string['privacy:metadata:ratehit'] = 'Zähler zur Missbrauchsbegrenzung an den Zugangs- und Mail-Endpunkten. Der Handelnde wird ausschließlich als Schlüssel-Hash gespeichert; Einträge lassen sich damit keiner Person zuordnen und werden nach 24 Stunden automatisch gelöscht.';
$string['privacy:metadata:ratehit:bucket'] = 'Welche Aktion begrenzt wurde (zum Beispiel eine Anfrage für einen Anmeldelink).';
$string['privacy:metadata:ratehit:identifier'] = 'Ein Schlüssel-Hash (HMAC) der Client-Adresse oder E-Mail-Adresse. Der Ursprungswert wird nicht gespeichert und ist nicht wiederherstellbar.';
$string['privacy:metadata:ratehit:timecreated'] = 'Zeitpunkt der Aufzeichnung.';
$string['privacy:metadata:token'] = 'Metadaten zu einmaligen Account-Tokens.';
$string['privacy:metadata:token:purpose'] = 'Zweck des Tokens.';
$string['privacy:metadata:token:timecreated'] = 'Wann das Token ausgestellt wurde.';
$string['privacy:metadata:token:timeexpires'] = 'Wann das Token abläuft.';
$string['privacy:metadata:token:timeused'] = 'Wann das Token eingelöst wurde.';
$string['privacy:metadata:token:tokenhash'] = 'Ein Einweg-Hash eines Einmal-Tokens (niemals das Token selbst).';
$string['privacy:metadata:token:userid'] = 'Zugehörige Moodle-Nutzer-ID.';
$string['registeraccesspassword'] = 'Kurs-/Zugangskennwort';
$string['registeraccesspassword_help'] = 'Geben Sie hier das Kennwort ein, das Sie für den Zugang zu diesem Kurs erhalten haben. Dies ist nicht das Passwort Ihres Nutzerkontos; das legen Sie im nächsten Schritt fest.';
$string['registeremailtaken'] = 'Für diese E-Mail-Adresse existiert bereits ein Konto. Bitte melden Sie sich stattdessen an.';
$string['registergateintro'] = 'Dieser Kurs ist durch ein Kurs-/Zugangskennwort geschützt. Geben Sie es zuerst ein; danach legen Sie Ihr Konto an.';
$string['registergatesubmit'] = 'Weiter';
$string['registergatetitle'] = 'Kurs-/Zugangskennwort';
$string['registerintro'] = 'Erstellen Sie ein Konto, um {$a} beizutreten. Sie können sich später mit der hier gewählten E-Mail und dem Passwort erneut anmelden.';
$string['registersubmit'] = 'Konto erstellen und eintreten';
$string['registersuccess'] = 'Ihr Konto wurde erstellt und Sie sind nun eingeschrieben.';
$string['registertitle'] = 'Schnellregistrierung';
$string['registerverificationsent'] = 'Sie haben nun Zugang. Bitte prüfen Sie Ihre E-Mails und folgen Sie dem Aktivierungslink, um Ihr Konto dauerhaft zu behalten.';
$string['repointincomplete'] = 'Die LTI-Bindungen und -Mitgliedschaften wurden nicht verschoben: Zwischenzeitlich hat eine andere Änderung Daten auf das zusammengeführte Konto gelenkt. Es wurde nichts geändert; bitte die Zusammenführung erneut prüfen.';
$string['senderemail'] = 'Optionale Absender-E-Mail-Adresse';
$string['senderemail_desc'] = 'Leer lassen, um den Moodle-Standardabsender zu verwenden.';
$string['setpasswordemailbody'] = 'Eine Administratorin/ein Administrator hat Ihr Konto aktiviert. Legen Sie zum Abschluss Ihr Passwort fest und melden Sie sich über diesen Link an (er läuft bald ab): {$a}';
$string['setpasswordemailsubject'] = 'Konto aktivieren: Passwort festlegen';
$string['setpasswordintro'] = 'Ihr Konto wurde von einer Administratorin/einem Administrator aktiviert. Wählen Sie ein Passwort, um die Einrichtung abzuschließen und sich anzumelden.';
$string['setpasswordinvalid'] = 'Dieser Link zum Festlegen des Passworts ist ungültig oder abgelaufen. Bitte lassen Sie ihn erneut senden.';
$string['setpasswordmismatch'] = 'Die beiden Passwörter stimmen nicht überein.';
$string['setpasswordpassword'] = 'Neues Passwort';
$string['setpasswordpassword2'] = 'Passwort bestätigen';
$string['setpasswordsubmit'] = 'Passwort festlegen und anmelden';
$string['setpasswordsuccess'] = 'Ihr Passwort wurde festgelegt. Sie sind jetzt angemeldet.';
$string['setpasswordtitle'] = 'Passwort festlegen';
$string['settingallowmagiclogin'] = 'Magic-Login-Links erlauben';
$string['settingallowmagiclogin_desc'] = 'Wenn aktiviert, können dauerhafte FlexAccess-Konten einen einmaligen, passwortlosen Anmeldelink per E-Mail anfordern.';
$string['settingfollowupwindow'] = 'Erinnerungsfenster für Persistenz';
$string['settingfollowupwindow_desc'] = 'Wie lange vor Ablauf eines temporären Kontos eine einmalige Erinnerung an Nutzer gesendet wird, die die E-Mail-Verifizierung begonnen, aber nicht abgeschlossen haben. Null deaktiviert die Erinnerungen.';
$string['settingmagicmaxperemail'] = 'Magic-Login-Anfragen pro E-Mail';
$string['settingmagicmaxperemail_desc'] = 'Maximale Anzahl an Magic-Login-Anfragen für eine Ziel-E-Mail innerhalb des Zeitfensters (gegen Inbox-Spam).';
$string['settingmagicmaxperip'] = 'Magic-Login-Anfragen pro Adresse';
$string['settingmagicmaxperip_desc'] = 'Maximale Anzahl an Magic-Login-Anfragen einer Client-Adresse innerhalb des Zeitfensters.';
$string['settingmagicwindow'] = 'Magic-Login-Zeitfenster (Sekunden)';
$string['settingmagicwindow_desc'] = 'Länge des gleitenden Zeitfensters für die Magic-Login-Ratenbegrenzung.';
$string['settingmergeevidencemaxbytes'] = 'Höchstgröße archivierter Daten je Zusammenführung (Bytes)';
$string['settingmergeevidencemaxbytes_desc'] = 'Eine Zusammenführung, deren archivierte Datensätze diese Größe überschreiten, wird abgewiesen und zurückgerollt.';
$string['settingmergeevidencemaxrows'] = 'Höchstzahl archivierter Zeilen je Zusammenführung';
$string['settingmergeevidencemaxrows_desc'] = 'Eine Zusammenführung, deren kollidierende Datensätze diese Zahl überschreiten, wird abgewiesen und zurückgerollt; sie läuft nie mit ausgelassenen Nachweisen.';
$string['settingmergeevidenceretention'] = 'Aufbewahrung erledigter Nachweise (Tage)';
$string['settingmergeevidenceretention_desc'] = 'Archivierte Kollisionen mit Entscheidung einer abgeglichenen Zusammenführung werden nach so vielen Tagen gelöscht. Offene Einträge und Zusammenführungen in Prüfung bleiben immer erhalten. 0 behält alles.';
$string['settingrecoverylifetime'] = 'Gültigkeit eines reaktivierten Kontos';
$string['settingrecoverylifetime_desc'] = 'Wie lange ein eingefrorenes temporäres Konto nach der Reaktivierung durch Administration oder Lehrende gültig bleibt. Ein Konto mit offener E-Mail-Verifikation erhält einen neuen Verifikationslink und wird erst dauerhaft, wenn dieser Link aufgerufen wird.';
$string['settingrequireemailverification'] = 'E-Mail-Bestätigung verlangen';
$string['settingrequireemailverification_desc'] = 'Wenn aktiviert, muss ein temporärer Nutzer, der sein Konto behalten möchte, seine E-Mail-Adresse über einen Bestätigungslink verifizieren, bevor das Konto dauerhaft wird.';
$string['settingretentiondays'] = 'Aufbewahrungsfrist (Tage)';
$string['settingretentiondays_desc'] = 'Tage, die ein abgelaufenes temporäres Konto aufbewahrt wird, bevor es samt Daten endgültig gelöscht wird. 0 = abgelaufene Konten unbegrenzt (gesperrt) behalten.';
$string['settingsaccounts'] = 'Accounts';
$string['settingsaccounts_desc'] = 'Konto-Laufzeiten werden pro Kurs an der FlexAccess-Einschreibemethode konfiguriert, nicht hier.';
$string['settingsmail'] = 'E-Mail-Queue';
$string['settingsmergeevidence'] = 'Nachweise bei Zusammenführungen';
$string['settingsmergeevidence_desc'] = 'Bevor eine Zusammenführung kollidierende Datensätze beider Nutzer auflöst, archiviert FlexAccess sie – nur IDs, Nutzer- und Schlüsselspalten, Zahlenwerte und ausdrücklich gelistete Textspalten wie Bewertungsfeedback, nie Zugangsdaten wie Tokens oder Push-IDs.';
$string['settingsratelimit'] = 'Ratenbegrenzung';
$string['settingsratelimit_desc'] = 'Missbrauchsschutz für den öffentlichen passwortlosen Login-Endpunkt. Die Vorgaben sind NAT-freundlich.';
$string['taskexpireaccounts'] = 'Temporäre FlexAccess-Accounts ablaufen lassen';
$string['taskprocessmailqueue'] = 'FlexAccess-Mailqueue verarbeiten';
$string['taskpurgemergeevidence'] = 'Erledigte FlexAccess-Nachweise von Zusammenführungen löschen';
$string['temporaryfirstname'] = 'FlexAccess';
$string['temporarylastname'] = 'Gast';
$string['unlimited'] = 'Unbegrenzt';
$string['welcomebody'] = 'Guten Tag {$a->firstname},

Ihr Zugang ist jetzt dauerhaft. Ihr Lernfortschritt bleibt damit gespeichert und Sie können jederzeit zurückkehren.

Ihr Anmeldename: {$a->username}
Hier anmelden: {$a->loginurl}

Aus Sicherheitsgründen versenden wir keine Passwörter per E-Mail. Bitte verwenden Sie das Passwort, das Sie selbst vergeben haben. Falls Sie es einmal vergessen, können Sie sich jederzeit selbst ein neues setzen:
{$a->forgoturl}

Mit freundlichen Grüßen';
$string['welcomesubject'] = 'Ihr dauerhafter Zugang: Anmeldename und Anmeldung';
