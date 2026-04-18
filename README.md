# Laravel Agent Guard

Laravel Agent Guard helps prevent sensitive credentials from leaking to AI agents, editors, and automation tools by moving `.env` values out of your project files and into the macOS keychain. Your app can keep reading environment values normally through `env()`, `$_ENV`, `$_SERVER`, and `getenv()`, while database passwords, API keys, app secrets, and other local credentials stay out of your workspace.




## 💻 Supported platforms

Laravel Agent Guard currently supports:

- macOS
- PHP 8.1 or newer
- Composer 2 plugin support
- Laravel projects that use a `.env` file

Laravel Agent Guard is intended for local development environments. Other operating systems are ignored safely.




## 📦 Installation

From your Laravel project root, allow the Composer plugin:

```sh
composer config allow-plugins.philipannis/laravel-agent-guard true
```

Then install the package:

```sh
composer require --dev philipannis/laravel-agent-guard
```

If Composer asks whether you trust this plugin during installation, allow it for your project.

On the first successful import from `.env`, Laravel Agent Guard attempts to open `Keychain Access` automatically so you can review the imported items in your `login` keychain.




## 🗑️ Delete your `.env` file manually

Laravel Agent Guard does not delete your `.env` file for you.

After the import succeeds, review the values in `Keychain Access`, make any backup you need, and then delete the `.env` file from your project yourself. This manual step is intentional. Your `.env` file may contain values you want to inspect, back up, rotate, or migrate before removal.

A blank placeholder `.env` file will be automatically created after you manually delete yours. This provides backwards compatibility for packages that expect `.env` to exist.



## 🔐 Manage values in `Keychain Access`

After you delete `.env`, manage local secrets directly in `Keychain Access`. Each environment variable should be stored as its own item in the `login` keychain with this structure:

| Field | Value |
| --- | --- |
| `Name` | Your project-prefixed variable name, such as `PROJECT_STRIPE_KEY` |
| `Kind` | `application password` |
| `Account` | `laravel-agent-guard` |
| `Password` | The secret value, such as `PROJECT_STRIPE_TOKEN` |

To add a value, open `Keychain Access`, choose `File` > `New Password Item`, and create a new item using the fields above.

To edit a value, open the existing item in `Keychain Access` and update the `Password`.

To delete a value, remove the item in `Keychain Access` whose `Name` matches the variable you want to remove.



## 📄 License

Laravel Agent Guard is open-sourced software licensed under the MIT license.
