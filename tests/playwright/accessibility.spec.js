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
 * Accessibility gate for the anonymous-facing FlexAccess pages.
 *
 * These pages (the access entry point and the quick-registration form) are shown to visitors who are
 * not logged in, so they carry the highest accessibility obligation. We run the axe-core WCAG 2.1 A
 * and AA rule sets and fail the build on any serious or critical violation.
 *
 * @module     enrol_flexaccess/accessibility.spec
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { test, expect } = require('@playwright/test');
const { loginAs } = require('./helpers');
const AxeBuilder = require('@axe-core/playwright').default;

const COURSE_ID = process.env.FLEXACCESS_COURSE_ID;
const MANAGER_USER = process.env.FLEXACCESS_MANAGER_USER;
const MANAGER_PASS = process.env.FLEXACCESS_MANAGER_PASS;

const BLOCKING_IMPACTS = ['serious', 'critical'];

/**
 * Run axe against the current page and return only the blocking violations.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @returns {Promise<Array>} The serious/critical violations.
 */
async function blockingViolations(page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    return results.violations.filter((v) => BLOCKING_IMPACTS.includes(v.impact));
}

/**
 * Open a page and assert it really rendered (HTTP 200 with the expected heading), so axe can never
 * pass by analysing a 404 or login redirect instead of the page under test.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @param {string} url The URL to open.
 * @param {RegExp} heading Expected visible heading on the rendered page.
 * @returns {Promise<void>}
 */
async function openAndVerify(page, url, heading) {
    const response = await page.goto(url);
    expect(response, `No response for ${url}`).not.toBeNull();
    expect(response.status(), `Unexpected HTTP status for ${url}`).toBe(200);
    // .first(): the page legitimately carries several headings matching the pattern (site, course
    // and section). Any of them proves the page rendered, so a strict single match is the wrong
    // assertion here - it failed as "resolved to 3 elements".
    await expect(page.getByRole('heading', { name: heading }).first()).toBeVisible();
}



test.describe('FlexAccess anonymous pages accessibility', () => {
    test.skip(!COURSE_ID, 'FLEXACCESS_COURSE_ID must be provided by the seed script.');

    test('temporary access entry page has no serious accessibility violations', async ({ page }) => {
        // The entry point lives in auth_flexaccess; /enrol/flexaccess/access.php does not exist.
        await openAndVerify(page, `/auth/flexaccess/access.php?courseid=${COURSE_ID}`, /access/i);
        const violations = await blockingViolations(page);
        expect(violations, JSON.stringify(violations.map((v) => v.id), null, 2)).toEqual([]);
    });

    test('quick-registration page has no serious accessibility violations', async ({ page }) => {
        await openAndVerify(page, `/auth/flexaccess/register.php?courseid=${COURSE_ID}`, /registration/i);
        const violations = await blockingViolations(page);
        expect(violations, JSON.stringify(violations.map((v) => v.id), null, 2)).toEqual([]);
    });
});


