import { expect, test } from '@playwright/test';

test('auth, dashboard, responsive navigation and account profile work in a Laravel consumer', async ({
    page,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Create an account' })).toHaveCount(0);
    await page.getByLabel('Email address').fill('admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('wrong-password');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('credentials');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole('heading', { name: /Welcome to/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Dashboard', exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: /Theme:/ }).click();
    await page.getByRole('button', { name: /Theme: light/ }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.getByRole('link', { name: 'Manage your profile' }).click();
    await expect(page.getByRole('heading', { name: 'Your profile' })).toBeVisible();
    await page.getByLabel('Name', { exact: true }).fill('Foundation Admin');
    await page.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByRole('status').first()).toContainText('Profile updated');
    await page.goto('/project-example');
    await expect(page.getByRole('heading', { name: 'Your business feature' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Dashboard', exact: true }).first()).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: /Welcome to/ })).toBeVisible();
    await page
        .getByRole('button', { name: /open.*sidebar|open.*navigation|toggle.*sidebar/i })
        .click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();
    await page.screenshot({ path: 'e2e/.artifacts/foundation-mobile.png', fullPage: true });
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.screenshot({ path: 'e2e/.artifacts/foundation-desktop.png', fullPage: true });
    await page.getByRole('button', { name: /Account menu/ }).click();
    expect(errors).toEqual([]);
    await expect(page.getByRole('menu')).toBeVisible();
    await page.getByRole('menuitem', { name: 'Log out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    expect(errors).toEqual([]);
});
