import { IconDashboard } from '@tabler/icons-react';
import { defineNavigation, navigationItem } from '@frolax/bedrock/module';

export default defineNavigation({
    items: [
        navigationItem({
            key: 'dashboard',
            label: 'Dashboard',
            href: '/',
            icon: IconDashboard,
            panels: ['default', 'admin'],
            active: ['/'],
        }),
    ],
});
