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

$string['access:badgate'] = 'Registration is restricted. Please check the access password or use an eligible email address.';
$string['access:badkey'] = 'That access key is not correct.';
$string['access:closed'] = 'Temporary access is not available at this time.';
$string['access:colaccount'] = 'Access with an account';
$string['access:coltemporary'] = 'Temporary guest account';
$string['access:enterkey'] = 'Access key';
$string['access:full'] = 'The maximum number of participants has been reached. Please try again later.';
$string['access:granted'] = 'Temporary access granted. Welcome!';
$string['access:intro'] = 'You are about to get temporary access to "{$a}". Your progress can later be kept by activating your account.';
$string['access:keyblocked'] = 'Too many incorrect attempts. Please wait a few minutes and try again.';
$string['access:notallowed'] = 'Temporary access is not offered for this course.';
$string['access:notenabled'] = 'FlexAccess is not enabled for this course.';
$string['access:noaccountoptions'] = 'Account-based access is not available for this course.';
$string['access:orguest'] = 'Continue as a guest';
$string['access:orlogin'] = 'Already have an account? Log in';
$string['access:ormagic'] = 'Log in with an email link instead';
$string['access:orregister'] = 'Or register for a persistent account';
$string['access:ratelimited'] = 'Too many attempts from your network right now. Please wait a few minutes and try again.';
$string['access:title'] = 'Temporary course access';
$string['access:unavailable'] = 'This course is not available for FlexAccess entry.';
$string['accessprovider'] = 'Course access without an account';
$string['magic:disabled'] = 'Email-link login is not available.';
$string['magic:emailbody'] = 'Open this link to log in. It works once and expires shortly: {$a}';
$string['magic:emailsubject'] = 'Your login link';
$string['magic:intro'] = 'Enter your email address and we will send you a one-time login link — no password needed.';
$string['magic:invalid'] = 'This login link is invalid, has expired, or was already used.';
$string['magic:sent'] = 'If an account exists for {$a}, a login link is on its way. Please check your email.';
$string['magic:submit'] = 'Send me a login link';
$string['magic:success'] = 'You are now logged in.';
$string['magic:title'] = 'Log in with an email link';
$string['maillimitperhour'] = 'Maximum FlexAccess e-mails per rolling hour';
$string['maillimitperhour_desc'] = 'Limits only messages sent by FlexAccess. Zero/unlimited disables the FlexAccess throttle.';
$string['mailsendfailed'] = 'FlexAccess could not send the follow-up email.';
$string['persist:emailbody'] = 'To keep your progress and make your account permanent, open this link: {$a}';
$string['persist:emailsubject'] = 'Make your account permanent';
$string['persist:intro'] = 'You are using a temporary account. Set your email, name and a password to make it permanent — you keep all your progress and can log in again later.';
$string['persist:invalid'] = 'This activation link is invalid, has expired, or was already used.';
$string['persist:notapplicable'] = 'Your account is already permanent, so there is nothing to convert.';
$string['persist:submit'] = 'Make my account permanent';
$string['persist:success'] = 'Your account is now permanent. Your results and access are kept.';
$string['persist:title'] = 'Keep your account';
$string['persist:verificationsent'] = 'We have sent a verification link to {$a}. Open it to make your account permanent, then you can log in with your email and password.';
$string['persisthint:cta'] = 'Secure your access now';
$string['persisthint:text'] = 'You are using temporary access to this course. Your progress can be kept permanently.';
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
$string['privacy:metadata:token'] = 'Metadata for one-time account tokens.';
$string['privacy:metadata:token:purpose'] = 'The token purpose.';
$string['privacy:metadata:token:timecreated'] = 'When the token was issued.';
$string['privacy:metadata:token:timeexpires'] = 'When the token expires.';
$string['privacy:metadata:token:timeused'] = 'When the token was consumed.';
$string['privacy:metadata:token:tokenhash'] = 'A one-way hash of a one-time token (never the token itself).';
$string['privacy:metadata:token:userid'] = 'The Moodle user ID associated with the token.';
$string['register:accesspassword'] = 'Access password';
$string['register:emailtaken'] = 'An account already exists for this email address. Please log in instead.';
$string['register:intro'] = 'Create an account to join {$a}. You can log in again later with the email and password you set here.';
$string['register:submit'] = 'Create account and enter';
$string['register:success'] = 'Your account has been created and you are now enrolled.';
$string['register:title'] = 'Quick registration';
$string['register:verificationsent'] = 'You now have access. Please check your email and follow the activation link to keep your account permanently.';
$string['senderemail'] = 'Optional sender e-mail address';
$string['senderemail_desc'] = 'Leave empty to use the Moodle default sender.';
$string['setpassword:emailbody'] = 'An administrator has activated your account. To finish, set your password and sign in using this link (it expires soon): {$a}';
$string['setpassword:emailsubject'] = 'Activate your account: set your password';
$string['setpassword:intro'] = 'Your account has been activated by an administrator. Choose a password to finish setting it up and sign in.';
$string['setpassword:invalid'] = 'This set-password link is invalid or has expired. Please ask an administrator to resend it.';
$string['setpassword:mismatch'] = 'The two passwords do not match.';
$string['setpassword:password'] = 'New password';
$string['setpassword:password2'] = 'Confirm password';
$string['setpassword:submit'] = 'Set password and sign in';
$string['setpassword:success'] = 'Your password has been set. You are now signed in.';
$string['setpassword:title'] = 'Set your password';
$string['setting:allowmagiclogin'] = 'Allow magic-login links';
$string['setting:allowmagiclogin_desc'] = 'When enabled, permanent FlexAccess accounts can request a one-time passwordless login link by email.';
$string['setting:followupwindow'] = 'Persistence reminder window';
$string['setting:followupwindow_desc'] = 'How long before a temporary account expires to email a one-time reminder to users who started but did not complete email verification. Set to zero to disable reminders.';
$string['setting:magicmaxperemail'] = 'Magic-login requests per email';
$string['setting:magicmaxperemail_desc'] = 'Maximum magic-login requests allowed for one target email within the window (anti inbox-spam).';
$string['setting:magicmaxperip'] = 'Magic-login requests per address';
$string['setting:magicmaxperip_desc'] = 'Maximum magic-login requests allowed from one client address within the window.';
$string['setting:magicwindow'] = 'Magic-login rate window (seconds)';
$string['setting:magicwindow_desc'] = 'Length of the sliding window used for magic-login rate limiting.';
$string['setting:requireemailverification'] = 'Require email verification';
$string['setting:requireemailverification_desc'] = 'When enabled, a temporary user who chooses to keep their account must confirm their email address by opening a verification link before the account becomes permanent.';
$string['setting:retentiondays'] = 'Retention period (days)';
$string['setting:retentiondays_desc'] = 'Days to keep an expired temporary account before it is permanently deleted along with its data. Set to 0 to keep expired accounts (suspended) indefinitely.';
$string['settings:accounts'] = 'Accounts';
$string['settings:accounts_desc'] = 'Account lifetimes are configured per course on the FlexAccess enrolment method, not here.';
$string['settings:mail'] = 'E-mail queue';
$string['settings:ratelimit'] = 'Rate limiting';
$string['settings:ratelimit_desc'] = 'Abuse protection for the public passwordless-login endpoint. Defaults are NAT-friendly.';
$string['task:expireaccounts'] = 'Expire temporary FlexAccess accounts';
$string['task:processmailqueue'] = 'Process FlexAccess mail queue';
$string['temporaryfirstname'] = 'FlexAccess';
$string['temporarylastname'] = 'Guest';
$string['unlimited'] = 'Unlimited';
