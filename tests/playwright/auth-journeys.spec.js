/*
 * This file is part of Moodle - https://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * What auth_flexaccess is responsible for, exercised in a browser: the entry page a visitor meets,
 * temporary access, the one-time login link, quick registration and turning a temporary account
 * into a permanent one.
 */

const { test, expect } = require('@playwright/test');
const { loginAs, fillPasswordUnmask, open, submitForm, chooseCourse } = require('./helpers');

const ADMIN_USER = process.env.FLEXACCESS_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.FLEXACCESS_ADMIN_PASS || 'Admin!23';
const COURSE_ID = process.env.FLEXACCESS_COURSE_ID;
const COURSE_NAME = process.env.FLEXACCESS_COURSE_NAME || 'My favourite course';

/**
 * Build a readable address that stays unique across retries.
 *
 * A retry would otherwise reuse an address that the first attempt already registered. The first
 * attempt - the one whose screenshots are used as illustrations - keeps the plain name.
 *
 * @param {string} local The local part, for example 'john.doe'.
 * @param {import('@playwright/test').TestInfo} testInfo The current test info.
 * @returns {string}
 */
function personEmail(local, testInfo) {
  return testInfo.retry ? `${local}.${testInfo.retry}@example.org` : `${local}@example.org`;
}

test('the entry page offers a visitor a way into the course', async ({ page, context }) => {
  test.skip(!COURSE_ID, 'FLEXACCESS_COURSE_ID not provided by the seed step');
  await context.clearCookies();
  await page.goto(`/auth/flexaccess/access.php?courseid=${COURSE_ID}`);
  await expect(page.locator('body')).toContainText(COURSE_NAME);
});

test('an anonymous visitor gains temporary access', async ({ page, context }) => {
  test.skip(!COURSE_ID, 'FLEXACCESS_COURSE_ID not provided by the seed step');
  await context.clearCookies();
  await page.goto(`/auth/flexaccess/access.php?courseid=${COURSE_ID}`);

  const button = page.getByRole('button', { name: /Continue/i });
  if (await button.count()) {
    await button.first().click();
  } else {
    await page.getByRole('link', { name: /Continue/i }).first().click();
  }

  await expect(page).toHaveURL(/course\/view\.php/);
  await expect(page.locator('body')).toContainText(COURSE_NAME);
});

test('the one-time login link can be requested', async ({ page, context }) => {
  await context.clearCookies();
  await page.goto('/auth/flexaccess/magic.php');
  await expect(page.locator('body')).toContainText('email link');
  await expect(page.locator('input[name="email"]')).toBeVisible();
});

test('quick registration creates an account that can log in again', async ({ page, context }, testInfo) => {
  test.skip(!COURSE_ID, 'FLEXACCESS_COURSE_ID not provided by the seed step');
  const email = personEmail('john.doe', testInfo);
  const password = 'P@$$w0rd!';

  await context.clearCookies();
  await page.goto(`/auth/flexaccess/register.php?courseid=${COURSE_ID}`);
  await page.waitForLoadState('domcontentloaded');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="firstname"]', 'John');
  await page.fill('input[name="lastname"]', 'Doe');
  await fillPasswordUnmask(page, 'password', password);
  await page.getByRole('button', { name: /Create account and enter/i }).click();
  await expect(page.locator('body')).toContainText(COURSE_NAME);

  // The decisive part: the account survives the session and its owner can return to it.
  await context.clearCookies();
  await loginAs(page, email, password);
  await expect(page.locator('body')).toContainText('John Doe');
});

test('temporary access can be made permanent', async ({ page, context }, testInfo) => {
  test.skip(!COURSE_ID, 'FLEXACCESS_COURSE_ID not provided by the seed step');
  const email = personEmail('jane.doe', testInfo);
  const password = 'P@$$w0rd!';

  await context.clearCookies();
  await page.goto(`/auth/flexaccess/access.php?courseid=${COURSE_ID}`);
  const cont = page.getByRole('button', { name: /Continue/i });
  if (await cont.count()) {
    await cont.first().click();
  } else {
    await page.getByRole('link', { name: /Continue/i }).first().click();
  }
  await expect(page).toHaveURL(/course\/view\.php/);

  await page.goto('/auth/flexaccess/persist.php');
  await page.waitForLoadState('domcontentloaded');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="firstname"]', 'Jane');
  await page.fill('input[name="lastname"]', 'Doe');
  await fillPasswordUnmask(page, 'password', password);
  await page.getByRole('button', { name: /Make my account permanent/i }).click();
  await expect(page.locator('body')).toContainText(/permanent/i);

  await context.clearCookies();
  await loginAs(page, email, password);
  await expect(page.locator('body')).toContainText('Jane Doe');
});
