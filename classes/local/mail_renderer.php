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

namespace auth_flexaccess\local;

/**
 * Renders subject and body for queued token mails at delivery time.
 *
 * Rendering is deliberately deferred to the worker so that the secret link (and therefore the
 * plaintext token) is only ever constructed at send time and never persisted in the mail queue.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mail_renderer {
    /**
     * Render subject, plain body and HTML body for a token mail.
     *
     * @param string $mailtype One of the mail_kind values.
     * @param string $link The freshly rendered secret link.
     * @return array{0: string, 1: string, 2: string} Subject, plain body, HTML body.
     */
    public static function render(string $mailtype, string $link): array {
        [$subjectkey, $bodykey] = match ($mailtype) {
            mail_kind::MAGIC_LOGIN => ['magicemailsubject', 'magicemailbody'],
            mail_kind::VERIFICATION => ['persistemailsubject', 'persistemailbody'],
            mail_kind::SET_PASSWORD => ['setpasswordemailsubject', 'setpasswordemailbody'],
            default => ['', ''],
        };
        if ($subjectkey === '') {
            return ['', '', ''];
        }
        $subject = get_string($subjectkey, 'auth_flexaccess');
        $body = get_string($bodykey, 'auth_flexaccess', $link);
        $bodyhtml = \html_writer::tag('p', get_string($bodykey, 'auth_flexaccess', \html_writer::link($link, $link)));
        return [$subject, $body, $bodyhtml];
    }

    /**
     * Map a token purpose to the anonymous entry page that consumes it.
     *
     * @param string $purpose Token purpose.
     * @return string|null Relative page path, or null when the purpose is not link-based.
     */
    public static function page_for_purpose(string $purpose): ?string {
        return match ($purpose) {
            'magiclogin' => '/auth/flexaccess/magic.php',
            'persistence' => '/auth/flexaccess/persist.php',
            'setpassword' => '/auth/flexaccess/setpassword.php',
            default => null,
        };
    }
}
