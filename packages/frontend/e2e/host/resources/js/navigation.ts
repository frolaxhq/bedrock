import { IconDashboard } from '@tabler/icons-react';
import { defineNavigation, navigationItem } from '@frolax/bedrock/module';

export default defineNavigation({
    items: [
        navigationItem({
            key: 'dashboard',
            label: 'Dashboard',
            href: '/',
            panels: ['admin'],
            active: ['/'],
            icon: IconDashboard,
        }),
    ],
});
