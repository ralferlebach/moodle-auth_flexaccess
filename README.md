moodle-auth_flexaccess
======================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-auth_flexaccess/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-auth_flexaccess/actions?query=workflow%3A%22Moodle+Plugin+CI+Main%22+branch%3Amain)

FlexAccess authentication provides the identity layer of the FlexAccess plugin set: temporary course visitors, their conversion into permanent accounts, e-mail link login and the central, rate-limited mail queue used by all four plugins.

FlexAccess consists of four plugins which are released together and depend on each other:
auth_flexaccess, enrol_flexaccess, mod_flexaccess and tool_flexaccess. They have to be installed
in the same version.


Requirements
------------

This plugin requires Moodle 4.5+


Motivation for this plugin
--------------------------

Courses that are meant to be tried out - self-assessments, orientation offers, open course days - lose most of their audience at the registration form. Moodle's guest access avoids that hurdle, but a guest keeps no progress and cannot come back to it.

FlexAccess closes that gap: a visitor enters immediately with a temporary account, and can later turn that very account into a permanent one without losing anything they have done. Everything that touches identity - creating, converting, expiring and deleting those accounts - lives in this plugin, so the rules exist in exactly one place.


Installation
------------

Install the plugin like any other plugin to folder
/auth/flexaccess

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins


Usage & Settings
----------------

After installing the plugin, it is ready to use. Temporary access is switched on per course through the FlexAccess enrolment method; this plugin provides the identity handling behind it.

To configure the plugin and its behaviour, please visit:
Site administration -> Plugins -> Authentication -> FlexAccess

There, you find settings for:

* **E-mail verification** - whether a visitor must confirm their address before their account becomes permanent.
* **Account lifetime and purging** - how long temporary accounts live and when expired ones are removed.
* **Magic login** - whether visitors may request a one-time login link by e-mail, and how often.
* **Mail queue** - the hourly send limit, sender address and retry behaviour. All FlexAccess mail passes through this queue, so an external SMTP limit is never exceeded.
* **Rate limits** - how often a single client or address may trigger account creation or mail.

If you want to learn more about using authentication plugins in Moodle, please see https://docs.moodle.org/en/Authentication.


Capabilities
------------

This plugin does not add any additional capabilities.


Scheduled Tasks
---------------

This plugin also introduces these additional scheduled tasks:

* **\auth_flexaccess\task\process_mail_queue** - Sends the mails waiting in the FlexAccess queue, within the configured hourly limit, and retries failed deliveries.\ By default, the task is enabled and runs every minute.
* **\auth_flexaccess\task\expire_accounts** - Marks temporary accounts as expired and purges them after the configured retention.\ By default, the task is enabled and runs hourly.


How this plugin works / Pitfalls
--------------------------------

A visitor who opens a FlexAccess-enabled course is offered an entry page instead of a login wall. Depending on what the course allows, they can enter with a temporary account, log in normally, request a one-time login link, register a permanent account straight away, or continue as a guest.

A temporary account is a real Moodle account with a generated username and a placeholder address. It carries the visitor's progress, expires after the configured lifetime and is purged afterwards. When the visitor decides to keep their progress, the same account is personalised: it receives their real address and name, and from then on it is an ordinary, fully-fledged Moodle account. Because the account id never changes, nothing that was already done is lost.

**Pitfall:** all FlexAccess mail goes through this plugin's queue. Mails carrying a one-time secret are queued without that secret; the token is created by the worker immediately before delivery, so it is never stored. That also means a mail is only as timely as the queue: with a low hourly limit, invitations and login links can be delayed.


Theme support
-------------

This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.


Plugin repositories
-------------------

This plugin is not published in the Moodle plugins repository.

The latest development version can be found on Github:
https://github.com/ralferlebach/moodle-auth_flexaccess


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-auth_flexaccess/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/ralferlebach/moodle-auth_flexaccess/issues

Please create pull requests on Github:
https://github.com/ralferlebach/moodle-auth_flexaccess/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

Due to limited resources, this plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

This plugin is designed to be compatible with all currently supported versions of Moodle, leveraging its latest APIs. However, if you are using a legacy version of Moodle, we kindly advise against installing or using this plugin. Instead, we strongly recommend updating your Moodle instance to a supported version to ensure security and compliance with current technological standards. Thank you for your understanding.


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be managed through AMOS (https://lang.moodle.org), where they will become part of Moodle's official language pack.

As the plugin creator, we continue to maintain the German translation. For all other languages, we kindly ask you to contribute your translations directly in AMOS. These contributions will be reviewed by Moodle's official language pack maintainers before being included in the official repository.

Thank you for supporting the global Moodle community!


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Maintainers
-----------

The plugin is maintained by\
Ralf Erlebach

Copyright
---------

The copyright of this plugin is held by\
Ralf Erlebach

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
