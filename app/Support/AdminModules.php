<?php

namespace App\Support;

/**
 * Every page of the admin, in sidebar order. One registry feeds the sidebar,
 * the permission grid on the group form and the `can:perm,...` route
 * middleware, so a new page is added here once.
 *
 * `actions` are the permissions the page supports; `active` is the route
 * name (admin.x.*) or path (admin/x*) pattern that highlights its link. `site_wide` pages show or
 * change data for every lab, so a lab's own account can never be given them,
 * whatever its group says. The rest are scoped to the account's lab.
 */
final class AdminModules
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    public const ACTION_LABELS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'];

    /** What a lab manager imported from WordPress, with no group, may do. */
    public const LAB_MANAGER_DEFAULTS = [
        'dashboard' => ['view'],
        'patients' => ['view', 'create'],
        'doctors' => ['view', 'create'],
        'guarantors' => ['view', 'create'],
        'push' => ['view', 'create'],
    ];

    /** Sections the sidebar folds into one expandable item => its icon. */
    public const FOLDED = ['Content' => 'image', 'Administration' => 'shield', 'Settings' => 'settings'];

    public static function sections(): array
    {
        return [
            'Overview' => [
                'dashboard' => ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => ['admin.dashboard'], 'active' => 'admin.dashboard', 'actions' => ['view']],
            ],
            'People' => [
                'patients' => ['label' => 'Patients', 'icon' => 'patients', 'route' => ['admin.users.index', 'patients'], 'active' => 'admin/patients*', 'actions' => self::ACTIONS],
                'doctors' => ['label' => 'Doctors', 'icon' => 'doctors', 'route' => ['admin.users.index', 'doctors'], 'active' => 'admin/doctors*', 'actions' => self::ACTIONS],
                'guarantors' => ['label' => 'Guarantors', 'icon' => 'guarantors', 'route' => ['admin.users.index', 'guarantors'], 'active' => 'admin/guarantors*', 'actions' => self::ACTIONS],
            ],
            'Manage' => [
                'labs' => ['label' => 'Labs', 'icon' => 'lab', 'route' => ['admin.labs.index'], 'active' => 'admin.labs.*', 'actions' => self::ACTIONS, 'site_wide' => true],
                'push' => ['label' => 'Send Notification', 'icon' => 'send', 'route' => ['admin.push.index'], 'active' => 'admin.push.index', 'actions' => ['view', 'create']],
                'accounting' => ['label' => 'Accounting', 'icon' => 'wallet', 'route' => ['admin.accounting.index'], 'active' => 'admin.accounting.*', 'actions' => self::ACTIONS, 'site_wide' => true, 'badge' => 'billing_due'],
            ],
            'Content' => [
                'sliders' => ['label' => 'Slider Images', 'icon' => 'image', 'route' => ['admin.images.index', 'sliders'], 'active' => 'admin/sliders*', 'actions' => self::ACTIONS, 'site_wide' => true],
                'banners' => ['label' => 'Banner Images', 'icon' => 'banner', 'route' => ['admin.images.index', 'banners'], 'active' => 'admin/banners*', 'actions' => self::ACTIONS, 'site_wide' => true],
                'categories' => ['label' => 'Result Categories', 'icon' => 'tag', 'route' => ['admin.categories.edit'], 'active' => 'admin.categories.*', 'actions' => ['view', 'edit'], 'site_wide' => true],
                'pages' => ['label' => 'About & FAQ', 'icon' => 'info', 'route' => ['admin.pages.edit', 'about'], 'active' => 'admin/pages/*', 'actions' => ['view', 'edit'], 'site_wide' => true],
            ],
            'Administration' => [
                'staff' => ['label' => 'Admin Accounts', 'icon' => 'key', 'route' => ['admin.staff.index'], 'active' => 'admin.staff.*', 'actions' => self::ACTIONS, 'site_wide' => true],
                'groups' => ['label' => 'Groups & Permissions', 'icon' => 'shield', 'route' => ['admin.groups.index'], 'active' => 'admin.groups.*', 'actions' => self::ACTIONS, 'site_wide' => true],
                'audit' => ['label' => 'Audit Log', 'icon' => 'history', 'route' => ['admin.audit.index'], 'active' => 'admin.audit.*', 'actions' => ['view'], 'site_wide' => true],
                'errors' => ['label' => 'Error Log', 'icon' => 'alert', 'route' => ['admin.errors.index'], 'active' => 'admin.errors.*', 'actions' => ['view', 'edit', 'delete'], 'site_wide' => true, 'badge' => 'errors_open'],
            ],
            'Settings' => [
                'alerts' => ['label' => 'Result Alerts', 'icon' => 'bell', 'route' => ['admin.alerts.show'], 'active' => 'admin.alerts.*', 'actions' => ['view', 'edit'], 'site_wide' => true],
                'firebase' => ['label' => 'Firebase Settings', 'icon' => 'flame', 'route' => ['admin.push.firebase'], 'active' => 'admin.push.firebase*', 'actions' => ['view', 'edit'], 'site_wide' => true],
                'ai' => ['label' => 'AI Summary', 'icon' => 'sparkle', 'route' => ['admin.ai.show'], 'active' => 'admin.ai.*', 'actions' => ['view', 'edit'], 'site_wide' => true],
            ],
        ];
    }

    /** key => definition, all sections flattened. */
    public static function all(): array
    {
        return array_merge(...array_values(self::sections()));
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function isSiteWide(string $key): bool
    {
        return (bool) (self::get($key)['site_wide'] ?? false);
    }

    /**
     * Keeps only known modules and the actions each supports, drops
     * site-wide pages from a lab group, and adds `view` to any other action:
     * nobody can edit a page they cannot open.
     */
    public static function sanitize(array $permissions, bool $forLabs = false): array
    {
        $clean = [];
        foreach (self::all() as $key => $module) {
            if ($forLabs && ($module['site_wide'] ?? false)) {
                continue;
            }
            $actions = array_values(array_intersect($module['actions'], array_map('strval', (array) ($permissions[$key] ?? []))));
            if ($actions !== [] && ! in_array('view', $actions, true)) {
                array_unshift($actions, 'view');
            }
            if ($actions !== []) {
                $clean[$key] = $actions;
            }
        }

        return $clean;
    }
}
