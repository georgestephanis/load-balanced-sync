=== Load Balanced Sync ===
Contributors: georgestephanis
Tags: updates, synchronization, load-balancer, distributed
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronizes plugin, theme, and core updates across multiple WordPress instances in a load-balanced or distributed setup.

== Description ==

Load Balanced Sync helps keep a pool of WordPress instances in sync by dispatching update events to trusted peers and applying them via WordPress internal upgraders.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Configure peers and synchronization settings in wp-admin.

== Frequently Asked Questions ==

= Does this replace normal WordPress updates? =

No. It coordinates update activity between nodes so they stay aligned.

== Changelog ==

= 1.0.0 =
* Initial release.
