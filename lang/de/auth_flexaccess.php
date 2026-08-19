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

$string['access:badkey'] = 'Dieser Zugangsschlüssel ist nicht korrekt.';
$string['access:closed'] = 'Temporärer Zugang ist derzeit nicht verfügbar.';
$string['access:enterkey'] = 'Zugangsschlüssel';
$string['access:full'] = 'Die maximale Teilnehmerzahl ist erreicht. Bitte versuchen Sie es später erneut.';
$string['access:granted'] = 'Temporärer Zugang gewährt. Willkommen!';
$string['access:intro'] = 'Sie erhalten gleich temporären Zugang zu „{$a}". Ihr Fortschritt kann später durch Aktivierung Ihres Kontos gesichert werden.';
$string['access:keyblocked'] = 'Zu viele fehlerhafte Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.';
$string['access:notallowed'] = 'Für diesen Kurs wird kein temporärer Zugang angeboten.';
$string['access:notenabled'] = 'FlexAccess ist für diesen Kurs nicht aktiviert.';
$string['access:orguest'] = 'Als Gast fortfahren';
$string['access:orlogin'] = 'Sie haben bereits ein Konto? Anmelden';
$string['access:ormagic'] = 'Stattdessen mit E-Mail-Link anmelden';
$string['access:orregister'] = 'Oder für ein dauerhaftes Konto registrieren';
$string['access:ratelimited'] = 'Zu viele Versuche aus Ihrem Netzwerk. Bitte warten Sie einige Minuten und versuchen Sie es erneut.';
$string['access:title'] = 'Temporärer Kurszugang';
$string['access:unavailable'] = 'Dieser Kurs ist nicht für den FlexAccess-Zugang verfügbar.';
$string['accessprovider'] = 'Flexibler Kurszugang';
$string['magic:disabled'] = 'Die Anmeldung per E-Mail-Link ist nicht verfügbar.';
$string['magic:emailbody'] = 'Öffnen Sie diesen Link, um sich anzumelden. Er funktioniert einmalig und läuft bald ab: {$a}';
$string['magic:emailsubject'] = 'Ihr Anmeldelink';
$string['magic:intro'] = 'Geben Sie Ihre E-Mail-Adresse ein, und wir senden Ihnen einen einmaligen Anmeldelink — ganz ohne Passwort.';
$string['magic:invalid'] = 'Dieser Anmeldelink ist ungültig, abgelaufen oder wurde bereits verwendet.';
$string['magic:sent'] = 'Falls ein Konto für {$a} existiert, ist ein Anmeldelink unterwegs. Bitte prüfen Sie Ihre E-Mails.';
$string['magic:submit'] = 'Anmeldelink senden';
$string['magic:success'] = 'Sie sind jetzt angemeldet.';
$string['magic:title'] = 'Mit E-Mail-Link anmelden';
$string['maillimitperhour'] = 'Maximale FlexAccess-E-Mails pro rollierender Stunde';
$string['maillimitperhour_desc'] = 'Begrenzt ausschließlich FlexAccess-Mails. Unbegrenzt deaktiviert das FlexAccess-Throttling.';
$string['mailsendfailed'] = 'FlexAccess konnte die Follow-up-E-Mail nicht versenden.';
$string['persist:emailbody'] = 'Um Ihren Fortschritt zu behalten und Ihr Konto dauerhaft zu machen, öffnen Sie diesen Link: {$a}';
$string['persist:emailsubject'] = 'Konto dauerhaft machen';
$string['persist:intro'] = 'Sie verwenden ein temporäres Konto. Legen Sie E-Mail, Namen und ein Passwort fest, um es dauerhaft zu machen — Ihr Fortschritt bleibt erhalten und Sie können sich später erneut anmelden.';
$string['persist:invalid'] = 'Dieser Aktivierungslink ist ungültig, abgelaufen oder wurde bereits verwendet.';
$string['persist:notapplicable'] = 'Ihr Konto ist bereits dauerhaft, es gibt nichts zu konvertieren.';
$string['persist:submit'] = 'Konto dauerhaft machen';
$string['persist:success'] = 'Ihr Konto ist jetzt dauerhaft. Ihre Ergebnisse und Ihr Zugang bleiben erhalten.';
$string['persist:title'] = 'Konto behalten';
$string['persist:verificationsent'] = 'Wir haben einen Bestätigungslink an {$a} gesendet. Öffnen Sie ihn, um Ihr Konto dauerhaft zu machen; danach können Sie sich mit E-Mail und Passwort anmelden.';
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
$string['privacy:metadata:preference:pendingemail'] = 'Eine bei der Kontoverstetigung eingegebene E-Mail-Adresse, die auf Verifizierung wartet.';
$string['privacy:metadata:token'] = 'Metadaten zu einmaligen Account-Tokens.';
$string['privacy:metadata:token:purpose'] = 'Zweck des Tokens.';
$string['privacy:metadata:token:timecreated'] = 'Wann das Token ausgestellt wurde.';
$string['privacy:metadata:token:timeexpires'] = 'Wann das Token abläuft.';
$string['privacy:metadata:token:timeused'] = 'Wann das Token eingelöst wurde.';
$string['privacy:metadata:token:tokenhash'] = 'Ein Einweg-Hash eines Einmal-Tokens (niemals das Token selbst).';
$string['privacy:metadata:token:userid'] = 'Zugehörige Moodle-Nutzer-ID.';
$string['provisionallifetime'] = 'Standard-Aktivierungsfrist';
$string['provisionallifetime_desc'] = 'Zeitraum zur Bestätigung einer Schnellregistrierung.';
$string['register:emailtaken'] = 'Für diese E-Mail-Adresse existiert bereits ein Konto. Bitte melden Sie sich stattdessen an.';
$string['register:intro'] = 'Erstellen Sie ein Konto, um {$a} beizutreten. Sie können sich später mit der hier gewählten E-Mail und dem Passwort erneut anmelden.';
$string['register:submit'] = 'Konto erstellen und eintreten';
$string['register:success'] = 'Ihr Konto wurde erstellt und Sie sind nun eingeschrieben.';
$string['register:title'] = 'Schnellregistrierung';
$string['senderemail'] = 'Optionale Absender-E-Mail-Adresse';
$string['senderemail_desc'] = 'Leer lassen, um den Moodle-Standardabsender zu verwenden.';
$string['setting:allowmagiclogin'] = 'Magic-Login-Links erlauben';
$string['setting:allowmagiclogin_desc'] = 'Wenn aktiviert, können dauerhafte FlexAccess-Konten einen einmaligen, passwortlosen Anmeldelink per E-Mail anfordern.';
$string['setting:magicmaxperemail'] = 'Magic-Login-Anfragen pro E-Mail';
$string['setting:magicmaxperemail_desc'] = 'Maximale Anzahl an Magic-Login-Anfragen für eine Ziel-E-Mail innerhalb des Zeitfensters (gegen Inbox-Spam).';
$string['setting:magicmaxperip'] = 'Magic-Login-Anfragen pro Adresse';
$string['setting:magicmaxperip_desc'] = 'Maximale Anzahl an Magic-Login-Anfragen einer Client-Adresse innerhalb des Zeitfensters.';
$string['setting:magicwindow'] = 'Magic-Login-Zeitfenster (Sekunden)';
$string['setting:magicwindow_desc'] = 'Länge des gleitenden Zeitfensters für die Magic-Login-Ratenbegrenzung.';
$string['setting:requireemailverification'] = 'E-Mail-Bestätigung verlangen';
$string['setting:requireemailverification_desc'] = 'Wenn aktiviert, muss ein temporärer Nutzer, der sein Konto behalten möchte, seine E-Mail-Adresse über einen Bestätigungslink verifizieren, bevor das Konto dauerhaft wird.';
$string['setting:retentiondays'] = 'Aufbewahrungsfrist (Tage)';
$string['setting:retentiondays_desc'] = 'Tage, die ein abgelaufenes temporäres Konto aufbewahrt wird, bevor es samt Daten endgültig gelöscht wird. 0 = abgelaufene Konten unbegrenzt (gesperrt) behalten.';
$string['settings:accounts'] = 'Accounts';
$string['settings:mail'] = 'E-Mail-Queue';
$string['settings:ratelimit'] = 'Ratenbegrenzung';
$string['settings:ratelimit_desc'] = 'Missbrauchsschutz für den öffentlichen passwortlosen Login-Endpunkt. Die Vorgaben sind NAT-freundlich.';
$string['stubnotice'] = 'FlexAccess scaffold: Die zielabhängige Anmeldung ist im Stub noch nicht aktiviert.';
$string['task:expireaccounts'] = 'Temporäre FlexAccess-Accounts ablaufen lassen';
$string['task:processmailqueue'] = 'FlexAccess-Mailqueue verarbeiten';
$string['temporaryfirstname'] = 'FlexAccess';
$string['temporarylastname'] = 'Gast';
$string['temporarylifetime'] = 'Standard-Laufzeit temporärer Nutzer';
$string['temporarylifetime_desc'] = 'Standardlaufzeit für anonyme temporäre Nutzer. Kursregeln dürfen weiter einschränken.';
$string['unlimited'] = 'Unbegrenzt';
