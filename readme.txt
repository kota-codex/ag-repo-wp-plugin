=== Argentum Package Manager Module Repository ===
Contributors: AKalmatskiy
Tags: argentum, package manager, repository
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 1.0.0
License: Apache-2.0 license
License URI: https://www.apache.org/licenses/LICENSE-2.0.txt

== Description ==

This plugin creates a repository of Argentum modules inside your WordPress site.

Argentum is a new safe application-level programming language having no memory leaks (see https://aglang.org).
* It compiles to machine code producing small atandalone executables.
* It has C/C++ interop.
* It supports multiple archs and platfors.
Thus its modules can include argentim source code as well as native libs/a files, DLLs/so, resources etc.

Argentum compiler has a built-in package manager that, by analyzing import declarations in the source files,
builds a module dependency graph, taking in account versions, arch, debug/release flavor.
If needed, it retrieves/updates the required modules from external repositories via HTTPS.

So this plugin adds to your WordPress site a functionality of custom Argentum Module Repository. It stores a list of modules
along with versions, descriptions and author contact info. It does not store or handle the actual binary content of the
modules; it instead stores external URLs (for example to GitHub) and redirects all requests to those URLs.

Plugin maintains a separate list of authors allowed to create new modules.
Only module authors can update their modules (increase version, update URL, roll-back version, delete module).
There are UI and REST API to create and update modules and list of authors.

== Installation ==

1. Install the plugin via the WordPress admin panel.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. In the admin interface, open the "AgModules" menu.
4. Add authors allowed to create modules under "Manage Repository Users".

== Usage ==

1. Watch and edit the module list under "Manage Modules".
2. As a WordPress user, you can see and edit your modules in the dashboard. Admins can see and edit everything.
3. For guests, add the [ag_modules] shortcode to display a read-only module table on any page of your WordPress site.
4. Access your site using the following URL:
   ```
   curl -L -v -o ag-sqlite.zip https://aglang.org/wp-json/repo/v1/Sqlite/1
   ```
   Where
   * -L - follow redirects
   * -v - verbode output for debugging
   * -o ag-sqlite.zip - place result in file
   * https://aglang.org - replace with you site url
   * Sqlite - module name
   * 1 - requested module version (i.e. >=1)


== Changelog ==

= 1.0.0 =
* Initial release: Added shortcode, user management UI, module management UI, REST API to get and update modules.

