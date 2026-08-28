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
 * Playwright configuration for the auth_flexaccess browser suite.
 *
 * Screenshots, traces and videos are recorded for every run, not only for failures: a green run
 * documents the intended journey and its screenshots are reused as illustrations.
 */

const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  timeout: 60 * 1000,
  expect: { timeout: 10 * 1000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.FLEXACCESS_BASE_URL || 'http://127.0.0.1:8000',
    screenshot: 'on',
    trace: 'on',
    video: 'on',
    ignoreHTTPSErrors: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
