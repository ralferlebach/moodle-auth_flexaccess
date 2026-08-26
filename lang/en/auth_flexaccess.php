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

$string['accessbadgate'] = 'Registration is restricted. Please check the access password or use an eligible email address.';
$string['accessbadkey'] = 'That access key is not correct.';
$string['accessclosed'] = 'Temporary access is not available at this time.';
$string['accesscolaccount'] = 'Access with an account';
$string['accesscoltemporary'] = 'Temporary guest account';
$string['accessenterkey'] = 'Access key';
$string['accessfull'] = 'The maximum number of participants has been reached. Please try again later.';
$string['accessgranted'] = 'Temporary access granted. Welcome!';
$string['accessguestlimitations'] = 'As a guest you can only view the course. You cannot submit work, take tests, post in forums or save any progress.';
$string['accessintro'] = 'You are about to get temporary access to "{$a}". Your progress can later be kept by activating your account.';
$string['accesskeyblocked'] = 'Too many incorrect attempts. Please wait a few minutes and try again.';
$string['accessnotallowed'] = 'Temporary access is not offered for this course.';
$string['accessnotenabled'] = 'FlexAccess is not enabled for this course.';
$string['accessnoaccountoptions'] = 'Account-based access is not available for this course.';
$string['accessorguest'] = 'Continue as a guest';
$string['accessorlogin'] = 'Already have an account? Log in';
$string['accessormagic'] = 'Log in with an email link instead';
$string['accessorregister'] = 'Or register for a persistent account';
$string['accessratelimited'] = 'Too many attempts from your network right now. Please wait a few minutes and try again.';
$string['accesstitle'] = 'Temporary course access';
$string['accessunavailable'] = 'This course is not available for FlexAccess entry.';
$string['accessprovider'] = 'Course access without an account';
$string['magicdisabled'] = 'Email-link login is not available.';
$string['magicemailbody'] = 'Open this link to log in. It works once and expires shortly: {$a}';
$string['magicemailsubject'] = 'Your login link';
$string['magicintro'] = 'Enter your email address and we will send you a one-time login link — no password needed.';
$string['magicinvalid'] = 'This login link is invalid, has expired, or was already used.';
$string['magicsent'] = 'If an account exists for {$a}, a login link is on its way. Please check your email.';
$string['magicsubmit'] = 'Send me a login link';
$string['magicsuccess'] = 'You are now logged in.';
$string['magictitle'] = 'Log in with an email link';
$string['maillimitperhour'] = 'Maximum FlexAccess e-mails per rolling hour';
$string['maillimitperhour_desc'] = 'Limits only messages sent by FlexAccess. Zero/unlimited disables the FlexAccess throttle.';
$string['mailsendfailed'] = 'FlexAccess could not send the follow-up email.';
$string['persistemailbody'] = 'To keep your progress and make your account permanent, open this link: {$a}';
$string['persistemailsubject'] = 'Make your account permanent';
$string['persistintro'] = 'You are using a temporary account. Set your email, name and a password to make it permanent — you keep all your progress and can log in again later.';
$string['persistinvalid'] = 'This activation link is invalid, has expired, or was already used.';
$string['persistnotapplicable'] = 'Your account is already permanent, so there is nothing to convert.';
$string['persistsubmit'] = 'Make my account permanent';
$string['persistsuccess'] = 'Your account is now permanent. Your results and access are kept.';
$string['persisttitle'] = 'Keep your account';
$string['persistverificationsent'] = 'We have sent a verification link to {$a}. Open it to make your account permanent, then you can log in with your email and password.';
$string['persisthintcta'] = 'Secure your access now';
$string['persisthinttext'] = 'You are using temporary access to this course. Your progress can be kept permanently.';
$string['pluginname'] = 'FlexAccess authentication';
$string['privacy:metadata:account'] = 'FlexAccess account lifecycle metadata.';
$string['privacy:metadata:account:accountstate'] = 'The FlexAccess lifecycle state.';
$string['privacy:metadata:account:accounttype'] = 'Whether this is a temporary user or authenticated user.';
$string['privacy:metadata:account:referencecode'] = 'Administrative reference code.';
$string['privacy:metadata:account:sourcecmid'] = 'The activity a temporary account was created from.';
$string['privacy:metadata:account:sourcecourseid'] = 'The course a temporary account was created from.';
$string['privacy:metadata:account:timecreated'] = 'When the account record was created.';
$string['privacy:metadata:account:timeexpires'] = 'When the temporary account expires.';
$string['privacy:metadata:account:userid'] = 'The Moodle user ID.';
$string['privacy:metadata:mail'] = 'Queued FlexAccess e-mail metadata.';
$string['privacy:metadata:mail:mailtype'] = 'The semantic mail type.';
$string['privacy:metadata:mail:payloadjson'] = 'The queued message content (subject and body).';
$string['privacy:metadata:mail:recipient'] = 'The e-mail recipient.';
$string['privacy:metadata:mail:status'] = 'The delivery status of the queued message.';
$string['privacy:metadata:mail:timecreated'] = 'When the message was queued.';
$string['privacy:metadata:mail:userid'] = 'The associated Moodle user ID, if available.';
$string['privacy:metadata:preference:followupsent'] = 'The time a one-time persistence reminder was sent, so it is not repeated.';
$string['privacy:metadata:preference:pendingemail'] = 'An email address entered during account persistence, awaiting verification.';
$string['privacy:metadata:ratehit'] = 'Rate-limit counters used to throttle abuse of the access and mail endpoints. The actor is stored only as a keyed hash, so entries cannot be traced back to a person, and rows are deleted automatically after 24 hours.';
$string['privacy:metadata:ratehit:bucket'] = 'Which action was throttled (for example a magic-login request).';
$string['privacy:metadata:ratehit:identifier'] = 'A keyed hash (HMAC) of the client address or email address. The original value is not stored and cannot be recovered.';
$string['privacy:metadata:ratehit:timecreated'] = 'When the action was recorded.';
$string['privacy:metadata:token'] = 'Metadata for one-time account tokens.';
$string['privacy:metadata:token:purpose'] = 'The token purpose.';
$string['privacy:metadata:token:timecreated'] = 'When the token was issued.';
$string['privacy:metadata:token:timeexpires'] = 'When the token expires.';
$string['privacy:metadata:token:timeused'] = 'When the token was consumed.';
$string['privacy:metadata:token:tokenhash'] = 'A one-way hash of a one-time token (never the token itself).';
$string['privacy:metadata:token:userid'] = 'The Moodle user ID associated with the token.';
$string['registeraccesspassword'] = 'Access password';
$string['registeremailtaken'] = 'An account already exists for this email address. Please log in instead.';
$string['registerintro'] = 'Create an account to join {$a}. You can log in again later with the email and password you set here.';
$string['registersubmit'] = 'Create account and enter';
$string['registersuccess'] = 'Your account has been created and you are now enrolled.';
$string['registertitle'] = 'Quick registration';
$string['registerverificationsent'] = 'You now have access. Please check your email and follow the activation link to keep your account permanently.';
$string['senderemail'] = 'Optional sender e-mail address';
$string['senderemail_desc'] = 'Leave empty to use the Moodle default sender.';
$string['setpasswordemailbody'] = 'An administrator has activated your account. To finish, set your password and sign in using this link (it expires soon): {$a}';
$string['setpasswordemailsubject'] = 'Activate your account: set your password';
$string['setpasswordintro'] = 'Your account has been activated by an administrator. Choose a password to finish setting it up and sign in.';
$string['setpasswordinvalid'] = 'This set-password link is invalid or has expired. Please ask an administrator to resend it.';
$string['setpasswordmismatch'] = 'The two passwords do not match.';
$string['setpasswordpassword'] = 'New password';
$string['setpasswordpassword2'] = 'Confirm password';
$string['setpasswordsubmit'] = 'Set password and sign in';
$string['setpasswordsuccess'] = 'Your password has been set. You are now signed in.';
$string['setpasswordtitle'] = 'Set your password';
$string['settingallowmagiclogin'] = 'Allow magic-login links';
$string['settingallowmagiclogin_desc'] = 'When enabled, permanent FlexAccess accounts can request a one-time passwordless login link by email.';
$string['settingfollowupwindow'] = 'Persistence reminder window';
$string['settingfollowupwindow_desc'] = 'How long before a temporary account expires to email a one-time reminder to users who started but did not complete email verification. Set to zero to disable reminders.';
$string['settingmagicmaxperemail'] = 'Magic-login requests per email';
$string['settingmagicmaxperemail_desc'] = 'Maximum magic-login requests allowed for one target email within the window (anti inbox-spam).';
$string['settingmagicmaxperip'] = 'Magic-login requests per address';
$string['settingmagicmaxperip_desc'] = 'Maximum magic-login requests allowed from one client address within the window.';
$string['settingmagicwindow'] = 'Magic-login rate window (seconds)';
$string['settingmagicwindow_desc'] = 'Length of the sliding window used for magic-login rate limiting.';
$string['settingrequireemailverification'] = 'Require email verification';
$string['settingrequireemailverification_desc'] = 'When enabled, a temporary user who chooses to keep their account must confirm their email address by opening a verification link before the account becomes permanent.';
$string['settingretentiondays'] = 'Retention period (days)';
$string['settingretentiondays_desc'] = 'Days to keep an expired temporary account before it is permanently deleted along with its data. Set to 0 to keep expired accounts (suspended) indefinitely.';
$string['settingsaccounts'] = 'Accounts';
$string['settingsaccounts_desc'] = 'Account lifetimes are configured per course on the FlexAccess enrolment method, not here.';
$string['settingsmail'] = 'E-mail queue';
$string['settingsratelimit'] = 'Rate limiting';
$string['settingsratelimit_desc'] = 'Abuse protection for the public passwordless-login endpoint. Defaults are NAT-friendly.';
$string['taskexpireaccounts'] = 'Expire temporary FlexAccess accounts';
$string['taskprocessmailqueue'] = 'Process FlexAccess mail queue';
$string['temporaryfirstname'] = 'FlexAccess';
$string['temporarylastname'] = 'Guest';
$string['unlimited'] = 'Unlimited';
$string['welcomebody'] = 'Hello {$a->firstname},

your account is now permanent, so your progress is saved and you can come back at any time.

Your username: {$a->username}
Log in here: {$a->loginurl}

For security reasons we do not send passwords by email. Please use the password you chose yourself. If you ever forget it, you can set a new one at any time:
{$a->forgoturl}

Kind regards';
$string['welcomesubject'] = 'Your permanent account: username and login';
