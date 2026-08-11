<?php

declare(strict_types=1);

/**
 * Uninstall routine for Bulk Product Editor for WooCommerce.
 *
 * Runs when the plugin is deleted from the Plugins screen — not on
 * deactivation. Removes only the per-user preferences this plugin created.
 *
 * Products, categories, tags and tax classes belong to WooCommerce; this
 * plugin only ever edited them, so none of that is touched here.
 */

// Only WordPress defines this. Without the guard the file could be requested
// directly and would delete data for every user.
defined('WP_UNINSTALL_PLUGIN') || exit();

/** User meta keys written by this plugin. Keep in sync with the class constants. */
const WCBULK_UNINSTALL_USER_META = [
    '_wcbulk_columns',   // selected columns, per user
    '_wcbulk_views',     // saved filter views, per user
];

/**
 * Delete this plugin's user meta for every user on the current site.
 *
 * The $delete_all flag makes delete_metadata() ignore the object id and clear
 * the key across all users in one query, so there is no need to page through
 * users.
 */
$wcbulk_uninstall_site = static function (): void {
    foreach (WCBULK_UNINSTALL_USER_META as $key) {
        delete_metadata('user', 0, $key, '', true);
    }
};

// On multisite, "delete plugin" removes it network-wide, so every site's
// preferences have to go. get_sites() is capped because a network with tens of
// thousands of sites would time out here; anything beyond that needs WP-CLI.
if (is_multisite()) {
    $wcbulk_sites = get_sites(['fields' => 'ids', 'number' => 1000]);

    foreach ($wcbulk_sites as $wcbulk_site_id) {
        switch_to_blog((int) $wcbulk_site_id);
        $wcbulk_uninstall_site();
        restore_current_blog();
    }
} else {
    $wcbulk_uninstall_site();
}
