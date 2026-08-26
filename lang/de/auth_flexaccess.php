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

$string['accessbadgate'] = 'Die Registrierung ist eingeschränkt. Bitte prüfen Sie das Zugangspasswort oder verwenden Sie eine zulässige E-Mail-Adresse.';
$string['accessbadkey'] = 'Dieser Zugangsschlüssel ist nicht korrekt.';
$string['accessclosed'] = 'Temporärer Zugang ist derzeit nicht verfügbar.';
$string['accesscolaccount'] = 'Zugang mit Account';
$string['accesscoltemporary'] = 'Temporärer Gastaccount';
$string['accessenterkey'] = 'Zugangsschlüssel';
$string['accessfull'] = 'Die maximale Teilnehmerzahl ist erreicht. Bitte versuchen Sie es später erneut.';
$string['accessgranted'] = 'Temporärer Zugang gewährt. Willkommen!';
$string['accessguestlimitations'] = 'Als Gast können Sie den Kurs nur ansehen. Sie können nichts abgeben, keine Tests bearbeiten, nicht in Foren schreiben und keinen Fortschritt speichern.';
$string['accessintro'] = 'Sie erhalten gleich temporären Zugang zu „{$a}". Ihr Fortschritt kann später durch Aktivierung Ihres Kontos gesichert werden.';
$string['accesskeyblocked'] = 'Zu viele fehlerhafte Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.';
$string['accessnotallowed'] = 'Für diesen Kurs wird kein temporärer Zugang angeboten.';
$string['accessnotenabled'] = 'FlexAccess ist für diesen Kurs nicht aktiviert.';
$string['accessnoaccountoptions'] = 'Kontobasierter Zugang ist für diesen Kurs nicht verfügbar.';
$string['accessorguest'] = 'Als Gast fortfahren';
$string['accessorlogin'] = 'Sie haben bereits ein Konto? Anmelden';
$string['accessormagic'] = 'Stattdessen mit E-Mail-Link anmelden';
$string['accessorregister'] = 'Oder für ein dauerhaftes Konto registrieren';
$string['accessratelimited'] = 'Zu viele Versuche aus Ihrem Netzwerk. Bitte warten Sie einige Minuten und versuchen Sie es erneut.';
$string['accesstitle'] = 'Temporärer Kurszugang';
$string['accessunavailable'] = 'Dieser Kurs ist nicht für den FlexAccess-Zugang verfügbar.';
$string['accessprovider'] = 'Kurszugang ohne eigenes Konto';
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
$string['persistemailbody'] = 'Um Ihren Fortschritt zu behalten und Ihr Konto dauerhaft zu machen, öffnen Sie diesen Link: {$a}';
$string['persistemailsubject'] = 'Konto dauerhaft machen';
$string['persistintro'] = 'Sie verwenden ein temporäres Konto. Legen Sie E-Mail, Namen und ein Passwort fest, um es dauerhaft zu machen — Ihr Fortschritt bleibt erhalten und Sie können sich später erneut anmelden.';
$string['persistinvalid'] = 'Dieser Aktivierungslink ist ungültig, abgelaufen oder wurde bereits verwendet.';
$string['persistnotapplicable'] = 'Ihr Konto ist bereits dauerhaft, es gibt nichts zu konvertieren.';
$string['persistsubmit'] = 'Konto dauerhaft machen';
$string['persistsuccess'] = 'Ihr Konto ist jetzt dauerhaft. Ihre Ergebnisse und Ihr Zugang bleiben erhalten.';
$string['persisttitle'] = 'Konto behalten';
$string['persistverificationsent'] = 'Wir haben einen Bestätigungslink an {$a} gesendet. Öffnen Sie ihn, um Ihr Konto dauerhaft zu machen; danach können Sie sich mit E-Mail und Passwort anmelden.';
$string['persisthintcta'] = 'Zugang jetzt dauerhaft sichern';
$string['persisthinttext'] = 'Sie nutzen einen temporären Zugang zu diesem Kurs. Ihr Fortschritt kann dauerhaft gesichert werden.';
$string['pluginname'] = 'FlexAccess-Authentifizierung';
$string['privacy:metadata:account'] = 'FlexAccess-Metadaten zum Account-Lebenszyklus.';
$string['privacy:metadata:account:accountstate'] = 'FlexAccess-Lifecycle-Status.';
$string['privacy:metadata:account:accounttype'] = 'Accounttyp temporary user oder authenticated user.';
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
$string['privacy:metadata:preference:followupsent'] = 'Der Zeitpunkt, zu dem eine einmalige Persistenz-Erinnerung gesendet wurde, damit sie nicht wiederholt wird.';
$string['privacy:metadata:preference:pendingemail'] = 'Eine bei der Kontoverstetigung eingegebene E-Mail-Adresse, die auf Verifizierung wartet.';
$string['privacy:metadata:token'] = 'Metadaten zu einmaligen Account-Tokens.';
$string['privacy:metadata:token:purpose'] = 'Zweck des Tokens.';
$string['privacy:metadata:token:timecreated'] = 'Wann das Token ausgestellt wurde.';
$string['privacy:metadata:token:timeexpires'] = 'Wann das Token abläuft.';
$string['privacy:metadata:token:timeused'] = 'Wann das Token eingelöst wurde.';
$string['privacy:metadata:token:tokenhash'] = 'Ein Einweg-Hash eines Einmal-Tokens (niemals das Token selbst).';
$string['privacy:metadata:token:userid'] = 'Zugehörige Moodle-Nutzer-ID.';
$string['registeraccesspassword'] = 'Zugangspasswort';
$string['registeremailtaken'] = 'Für diese E-Mail-Adresse existiert bereits ein Konto. Bitte melden Sie sich stattdessen an.';
$string['registerintro'] = 'Erstellen Sie ein Konto, um {$a} beizutreten. Sie können sich später mit der hier gewählten E-Mail und dem Passwort erneut anmelden.';
$string['registersubmit'] = 'Konto erstellen und eintreten';
$string['registersuccess'] = 'Ihr Konto wurde erstellt und Sie sind nun eingeschrieben.';
$string['registertitle'] = 'Schnellregistrierung';
$string['registerverificationsent'] = 'Sie haben nun Zugang. Bitte prüfen Sie Ihre E-Mails und folgen Sie dem Aktivierungslink, um Ihr Konto dauerhaft zu behalten.';
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
$string['settingrequireemailverification'] = 'E-Mail-Bestätigung verlangen';
$string['settingrequireemailverification_desc'] = 'Wenn aktiviert, muss ein temporärer Nutzer, der sein Konto behalten möchte, seine E-Mail-Adresse über einen Bestätigungslink verifizieren, bevor das Konto dauerhaft wird.';
$string['settingretentiondays'] = 'Aufbewahrungsfrist (Tage)';
$string['settingretentiondays_desc'] = 'Tage, die ein abgelaufenes temporäres Konto aufbewahrt wird, bevor es samt Daten endgültig gelöscht wird. 0 = abgelaufene Konten unbegrenzt (gesperrt) behalten.';
$string['settingsaccounts'] = 'Accounts';
$string['settingsaccounts_desc'] = 'Konto-Laufzeiten werden pro Kurs an der FlexAccess-Einschreibemethode konfiguriert, nicht hier.';
$string['settingsmail'] = 'E-Mail-Queue';
$string['settingsratelimit'] = 'Ratenbegrenzung';
$string['settingsratelimit_desc'] = 'Missbrauchsschutz für den öffentlichen passwortlosen Login-Endpunkt. Die Vorgaben sind NAT-freundlich.';
$string['taskexpireaccounts'] = 'Temporäre FlexAccess-Accounts ablaufen lassen';
$string['taskprocessmailqueue'] = 'FlexAccess-Mailqueue verarbeiten';
$string['temporaryfirstname'] = 'FlexAccess';
$string['temporarylastname'] = 'Gast';
$string['unlimited'] = 'Unbegrenzt';
