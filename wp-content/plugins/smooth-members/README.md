# Smooth Members

WordPress membership plugin scaffold built with a simple MVC-style structure.

## Current Scope

- Admin menu registration
- Placeholder admin pages for:
  - Members
  - Memberships
  - Subscriptions
  - Settings

## Architecture

```
smooth-members/
├─ smooth-members.php                # Plugin bootstrap
└─ app/
   ├─ Core/
   │  ├─ Autoloader.php             # Namespace class loader
   │  └─ Plugin.php                 # Service bootstrapping
   ├─ Controllers/
   │  └─ AdminMenuController.php    # WP admin menus + callbacks
   ├─ Models/
   │  └─ Member.php                 # Domain model placeholder
   └─ Views/
      └─ Admin/
         ├─ members.php
         ├─ memberships.php
         ├─ subscriptions.php
         └─ settings.php
```

## Admin Menu Details

- Top-level page title: `Smooth Members`
- Top-level menu label: `Members`
- Top-level slug: `smooth-members`
- Submenu slugs:
  - `smooth-members` (Members)
  - `smooth-members-memberships`
  - `smooth-members-subscriptions`
  - `smooth-members-settings`

## Development Notes

- Namespace prefix: `SmoothMembers\\`
- New controllers should register hooks in a `register()` method.
- Keep business logic in `Models`/services and keep views minimal.
- Use capability `manage_options` for admin-only pages unless requirements change.
