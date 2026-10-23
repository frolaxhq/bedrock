import { expect, test } from '@playwright/test';

test('SSR renders, hydrates, and navigates between host and module pages', async ({
    page,
    request,
}) => {
    const errors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
    });
    page.on('pageerror', (error) => errors.push(error.message));

    const response = await page.goto('/example');
    const html = await response?.text();

    expect(response?.status()).toBe(200);
    expect(html).toContain('Bedrock full-stack module');
    expect(html).toContain('Refresh example');
    expect(html).toContain('Live');
    expect(html).toContain('Administrator panel footer');
    await expect(page.locator('#app')).toHaveAttribute('data-hydrated', 'true');
    expect(errors).toEqual([]);
    await expect(page.getByRole('heading', { name: 'Bedrock full-stack module' })).toBeVisible();
    await expect(page.locator('[data-slot="panel-shell"]')).toHaveAttribute('data-panel', 'admin');
    await expect(page.getByRole('link', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText('Live')).toBeVisible();
    await page.getByRole('button', { name: 'Refresh example' }).click();
    await expect(page.getByText('Example refreshed.')).toBeVisible();

    await page.getByRole('button', { name: 'Collapse navigation' }).click();
    await expect(page.locator('[data-slot="panel-shell"]')).toHaveAttribute(
        'data-sidebar-collapsed',
        'true',
    );
    await page.reload();
    await expect(page.locator('[data-slot="panel-shell"]')).toHaveAttribute(
        'data-sidebar-collapsed',
        'true',
    );

    await page.getByRole('link', { name: 'Return to the host' }).click();
    await expect(page.getByRole('heading', { name: 'Host application' })).toBeVisible();
    await expect(page.getByText('Administrator panel footer')).toBeVisible();
    await page.getByRole('link', { name: 'Open module' }).click();
    await expect(page.getByRole('heading', { name: 'Bedrock full-stack module' })).toBeVisible();

    const build = await (await request.get('/__build')).json();
    expect(build.unchanged).toBe(true);
    expect(build.before).toBe(build.after);
    expect(errors).toEqual([]);
});

test('the panel opens an accessible mobile navigation drawer', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/example');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const trigger = page.getByRole('button', { name: 'Open navigation' });
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await trigger.click();

    await expect(page.locator('#bedrock-panel-mobile-sidebar')).toBeVisible();
    await expect(page.locator('[data-slot="panel-shell"]')).toHaveAttribute(
        'data-mobile-sidebar-open',
        'true',
    );
    await expect(page.getByRole('button', { name: 'Dismiss navigation' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#bedrock-panel-mobile-sidebar')).toBeHidden();
    await expect(page.locator('[data-slot="panel-shell"]')).toHaveAttribute(
        'data-mobile-sidebar-open',
        'false',
    );
});
