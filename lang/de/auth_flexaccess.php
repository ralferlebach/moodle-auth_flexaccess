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
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'FlexAccess-Authentifizierung';
$string['accessprovider'] = 'Flexibler Kurszugang';
$string['stubnotice'] = 'FlexAccess scaffold: Die zielabhängige Anmeldung ist im Stub noch nicht aktiviert.';
$string['settings:accounts'] = 'Accounts';
$string['settings:mail'] = 'E-Mail-Queue';
$string['temporarylifetime'] = 'Standard-Laufzeit temporärer Nutzer';
$string['temporarylifetime_desc'] = 'Standardlaufzeit für anonyme temporäre Nutzer. Kursregeln dürfen weiter einschränken.';
$string['provisionallifetime'] = 'Standard-Aktivierungsfrist';
$string['provisionallifetime_desc'] = 'Zeitraum zur Bestätigung einer Schnellregistrierung.';
$string['senderemail'] = 'Optionale Absender-E-Mail-Adresse';
$string['senderemail_desc'] = 'Leer lassen, um den Moodle-Standardabsender zu verwenden.';
$string['maillimitperhour'] = 'Maximale FlexAccess-E-Mails pro rollierender Stunde';
$string['maillimitperhour_desc'] = 'Begrenzt ausschließlich FlexAccess-Mails. Unbegrenzt deaktiviert das FlexAccess-Throttling.';
$string['unlimited'] = 'Unbegrenzt';
$string['task:processmailqueue'] = 'FlexAccess-Mailqueue verarbeiten';
$string['task:expireaccounts'] = 'Temporäre FlexAccess-Accounts ablaufen lassen';
$string['privacy:metadata:account'] = 'FlexAccess-Metadaten zum Account-Lebenszyklus.';
$string['privacy:metadata:account:userid'] = 'Moodle-Nutzer-ID.';
$string['privacy:metadata:account:accounttype'] = 'Accounttyp temporary user oder authenticated user.';
$string['privacy:metadata:account:accountstate'] = 'FlexAccess-Lifecycle-Status.';
$string['privacy:metadata:account:referencecode'] = 'Administrative Referenznummer.';
$string['privacy:metadata:token'] = 'Metadaten zu einmaligen Account-Tokens.';
$string['privacy:metadata:token:userid'] = 'Zugehörige Moodle-Nutzer-ID.';
$string['privacy:metadata:token:purpose'] = 'Zweck des Tokens.';
$string['privacy:metadata:mail'] = 'Metadaten zu wartenden FlexAccess-E-Mails.';
$string['privacy:metadata:mail:userid'] = 'Zugehörige Moodle-Nutzer-ID, falls vorhanden.';
$string['privacy:metadata:mail:recipient'] = 'E-Mail-Empfänger.';
$string['privacy:metadata:mail:mailtype'] = 'Semantischer Mailtyp.';
$string['followup:subject'] = 'Ergebnisse behalten: Konto aktivieren';
$string['followup:body'] = 'Sie haben einen Kurs mit einem temporären FlexAccess-Konto genutzt. Um Ihre Ergebnisse zu behalten und ein dauerhaftes Konto zu erhalten, öffnen Sie diesen Link: {$a}';
$string['persist:title'] = 'Konto aktivieren';
$string['persist:success'] = 'Ihr Konto ist jetzt dauerhaft. Ihre Ergebnisse und Ihr Zugang bleiben erhalten.';
$string['persist:invalid'] = 'Dieser Aktivierungslink ist ungültig, abgelaufen oder wurde bereits verwendet.';
$string['temporaryfirstname'] = 'FlexAccess';
$string['temporarylastname'] = 'Gast';
$string['access:title'] = 'Temporärer Kurszugang';
$string['access:intro'] = 'Sie erhalten gleich temporären Zugang zu „{$a}". Ihr Fortschritt kann später durch Aktivierung Ihres Kontos gesichert werden.';
$string['access:granted'] = 'Temporärer Zugang gewährt. Willkommen!';
$string['access:closed'] = 'Temporärer Zugang ist derzeit nicht verfügbar.';
$string['access:notallowed'] = 'Für diesen Kurs wird kein temporärer Zugang angeboten.';
$string['access:notenabled'] = 'FlexAccess ist für diesen Kurs nicht aktiviert.';
$string['access:full'] = 'Die maximale Teilnehmerzahl ist erreicht. Bitte versuchen Sie es später erneut.';
