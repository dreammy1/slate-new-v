<?php
/**
 * Slate — English language file.
 *
 * Each key is referenced from PHP via __('key', 'English fallback'),
 * and the inline fallback is what's shown when this file's missing
 * a key. The fallback is the "source of truth"; this file mostly
 * exists so admin translation overrides have a baseline to override.
 */

return [
    // Layout
    'dashboard'         => 'Dashboard',
    'plugins'           => 'Plugins',
    'users'             => 'Users',
    'roles'             => 'Roles',
    'contact_forms'     => 'Contact forms',
    'settings'          => 'Settings',
    'logout'            => 'Log out',
    'login'             => 'Log in',
    'admin_login'       => 'Admin login',
    'email'             => 'Email',
    'password'          => 'Password',
    'skip_to_content'   => 'Skip to main content',
    'primary_navigation'=> 'Primary navigation',
    'more'              => 'More',

    // Login messages
    'csrf_failed'       => 'Security check failed. Please try again.',
    'login_required'    => 'Email and password are required.',
    'login_invalid'     => 'Invalid email or password.',
    'install_success'   => 'Slate is installed. Log in with the account you just created.',

    // Dashboard
    'good_to_see_you'   => 'Good to see you',
    'dashboard_subtitle'=> 'Here\'s a quick snapshot of your account today.',
    'welcome'           => 'Welcome to Slate',
    'dashboard_intro'   => 'Slate is a lean shell. Install plugins to add functionality — booking, products, passes, and more. Each plugin owns its own data and can be deactivated or uninstalled without affecting the others.',
    'active_plugins'    => 'Active plugins',
    'installed'         => 'installed',
    'inactive'          => 'inactive',
    'admin_users'       => 'Admin users',
    'customers'         => 'Customers',
    'manage_plugins'    => 'Manage plugins',
    'recent_activity'   => 'Recent activity',
    'view_all'          => 'View all',
    'when'              => 'When',
    'who'               => 'Who',
    'action'            => 'Action',
    'target'            => 'Target',

    // Plugins page
    'plugins_subtitle'  => 'Add features by installing and activating plugins.',
    'upload_plugin'     => 'Upload a plugin',
    'plugin_zip'        => 'Plugin ZIP file',
    'upload_and_install'=> 'Upload &amp; install',
    'upload_hint'       => 'Upload a .zip file containing a single plugin folder. Max 10 MB.',
    'download_example'  => 'Download example plugin',
    'installed_plugins' => 'Installed plugins',
    'no_plugins_installed' => 'No plugins installed',
    'no_plugins_intro'  => 'Slate is a clean shell right now. Upload your first plugin above.',
    'activate'          => 'Activate',
    'deactivate'        => 'Deactivate',
    'uninstall'         => 'Uninstall',
    'plugin_installed'  => 'Plugin "%s" installed. Click Activate to enable it.',
    'plugin_activated'  => 'Plugin "%s" activated.',
    'plugin_deactivated'=> 'Plugin "%s" deactivated.',
    'plugin_uninstalled'=> 'Plugin "%s" uninstalled. All data removed.',
    'confirm_uninstall' => "Uninstall %s? This will delete all of this plugin's data and cannot be undone.",
    'only_super_admin'  => 'Only super-admins can perform that action.',
    'missing_slug'      => 'Missing plugin slug.',
    'no_file'           => 'No file selected.',
    'upload_too_large'  => 'Upload too large. Max 10 MB.',
    'upload_partial'    => 'Upload was interrupted. Try again.',
    'upload_server_error' => 'Server could not store the upload.',
    'upload_failed'     => 'Upload failed.',
    'upload_invalid'    => 'Invalid upload.',
    'upload_not_zip'    => 'File must be a .zip archive.',
    'name'              => 'Name',
    'version'           => 'Version',
    'status'            => 'Status',
    'installed_at'      => 'Installed',
    'active'            => 'active',

    // Users page
    'users_subtitle'    => 'Admin users who can log into Slate.',
    'coming_session_3'  => 'Edit UI: Session 3',
    'role'              => 'Role',
    'last_login'        => 'Last login',
    'never'             => 'Never',

    // Roles page
    'roles_subtitle'    => 'Roles control what admin users can do.',
    'description'       => 'Description',
    'permissions'       => 'Permissions',
    'super_admin'       => 'Super',
    'all'               => 'All',

    // Contact forms page
    'forms_subtitle'    => 'Build forms to collect inquiries and submissions.',
    'no_forms_yet'      => 'No forms yet',
    'forms_intro'       => 'The drag-and-drop form builder ships in Session 3. You\'ll be able to create forms with text, email, dropdown, file-upload, and signature fields.',
    'slug'              => 'Slug',
    'submissions'       => 'Submissions',

    // Settings page
    'settings_subtitle' => 'Configure how Slate looks and behaves.',
    'more_session_3'    => 'More settings: Session 3',
    'general'           => 'General',
    'site_name'         => 'Site name',
    'site_name_hint'    => 'Shown in the sidebar, on the login screen, and in browser tabs.',
    'site_name_required'=> 'Site name is required.',
    'default_language'  => 'Default language',
    'save_changes'      => 'Save changes',
    'settings_saved'    => 'Settings saved.',
    'settings_readonly' => 'You have read-only access to settings.',
    'coming_soon'       => 'Coming in Session 3',
    'branding_logo'     => 'Branding (logo, accent color, favicon)',
    'business_details'  => 'Business details (address, phone, hours)',
    'smtp_config'       => 'SMTP email configuration',
    'translation_editor'=> 'Translation editor',

    // Days of week (used by I18n::localDate)
    'sunday'            => 'Sunday',
    'monday'            => 'Monday',
    'tuesday'           => 'Tuesday',
    'wednesday'         => 'Wednesday',
    'thursday'          => 'Thursday',
    'friday'            => 'Friday',
    'saturday'          => 'Saturday',

    // Months
    'month_1'           => 'January',
    'month_2'           => 'February',
    'month_3'           => 'March',
    'month_4'           => 'April',
    'month_5'           => 'May',
    'month_6'           => 'June',
    'month_7'           => 'July',
    'month_8'           => 'August',
    'month_9'           => 'September',
    'month_10'          => 'October',
    'month_11'          => 'November',
    'month_12'          => 'December',
];
