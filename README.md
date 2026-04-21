# Contact Portal — EspoCRM Extension

A self-service portal for Sepheo contacts. It provides two public-facing flows with no login required:

- **Magic-link edit form** — existing contacts request a one-time link by email and use it to view and update their details.
- **Registration form** — new contacts submit a sign-up form that creates a Contact record in EspoCRM.

---

## Running locally

### Prerequisites

- Docker Desktop
- Node.js / npm

### Start the stack

```bash
docker compose up -d
```

This starts two containers:

| Container                | Purpose                    | Default URL           |
| ------------------------ | -------------------------- | --------------------- |
| `ext-template-espocrm-1` | EspoCRM app (Apache + PHP) | http://localhost:8080 |
| `ext-template-mysql-1`   | MySQL database             | localhost:3306        |

EspoCRM is pre-installed. Log in with **admin / 1**.

### How the extension files reach the container

The `custom/` directory in the workspace root is **bind-mounted** directly into the container at `/var/www/html/custom/`. Any file written there is immediately available to PHP — no restart needed.

`src/files/custom/` is the **source of truth** for all extension PHP, metadata and layout files. Running:

```bash
npm run sync
```

copies everything from `src/files/` into `custom/`, which the container then picks up instantly.

**Typical workflow:**

1. Edit a file under `src/files/custom/Espo/Modules/ContactPortal/`.
2. Run `npm run sync`.
3. Run Admin → Clear Cache in EspoCRM (or `docker exec ext-template-espocrm-1 php /var/www/html/command.php clear-cache`).
4. Reload the page.

> For faster iteration, configure a file-watcher in your IDE to run `npm run sync` automatically on save.

---

## Portal URLs

| Purpose                | URL                                          |
| ---------------------- | -------------------------------------------- |
| Request magic link     | `/?entryPoint=contactPortalRequest`          |
| Edit form (magic link) | `/?entryPoint=contactPortalEdit&token=TOKEN` |
| Registration form      | `/?entryPoint=contactPortalRegister`         |

---

## How entry points work

EspoCRM entry points are classes that handle unauthenticated HTTP requests. They are declared in `Resources/metadata/app/client.json` and implement `Espo\Core\EntryPoint\EntryPoint`. The `NoAuth` trait suppresses the normal authentication check.

The portal uses three entry points (GET, render HTML) and three actions (POST, process form submissions):

```
GET  /?entryPoint=contactPortalRequest    → EntryPoints/ContactPortalRequest.php
POST /api/v1/ContactPortal/request        → Actions/HandleRequest.php

GET  /?entryPoint=contactPortalEdit       → EntryPoints/ContactPortalEdit.php
POST /api/v1/ContactPortal/save           → Actions/HandleSave.php

GET  /?entryPoint=contactPortalRegister   → EntryPoints/ContactPortalRegister.php
POST /api/v1/ContactPortal/register       → Actions/HandleRegister.php
```

Routes for the POST endpoints are declared in `Resources/routes.json`.

All pages are server-rendered HTML returned by `Util/HtmlRenderer.php` (inline CSS, no JS framework). EspoCRM's DI container auto-wires constructor dependencies.

---

## Configuring which fields appear

All field configuration lives in one file:

```
src/files/custom/Espo/Modules/ContactPortal/Resources/metadata/contactPortal/Contact.json
```

After editing it, run `npm run sync` and then clear the EspoCRM cache.

### `editFormFields`

Controls which fields appear on the magic-link **edit form**, in what order, and how they behave. Each entry is an object with a required `name` key and optional flags:

```json
"editFormFields": [
    { "name": "firstName", "hint": "Your given names." },
    { "name": "emailAddress", "readOnly": true, "hint": "We use this to contact you." },
    { "name": "cMatrixID", "required": true, "hint": "Your Matrix handle." },
    { "name": "cWebsite" }
]
```

| Key        | Type   | Effect                                                               |
| ---------- | ------ | -------------------------------------------------------------------- |
| `name`     | string | EspoCRM field name — **required**                                    |
| `hint`     | string | Italic helper text shown below the label                             |
| `readOnly` | bool   | Displays the value as plain text; the field is never written on save |
| `required` | bool   | Overrides the EspoCRM entityDefs `required` flag for this form       |

**Field order** is exactly the order of entries in the array. To reorder, move the objects. To hide a field entirely, remove its entry.

### `registrationFields`

Controls which fields appear on the **registration form**. Entries are either plain strings (inheriting hints and required from `editFormFields`) or objects with per-field overrides:

```json
"registrationFields": [
    "firstName",
    "lastName",
    { "name": "emailAddress", "required": true },
    { "name": "cMatrixID", "required": true, "hint": "Override hint for registration." },
    "cWebsite"
]
```

**Priority for `required`:**

1. `required` on the `registrationFields` entry (highest)
2. `required` on the matching `editFormFields` entry
3. EspoCRM entityDefs `required` flag (fallback)

Fields not listed in `registrationFields` do not appear on the registration form even if they are in `editFormFields`.

### Supported field types

The following EspoCRM field types are rendered automatically:

| EspoCRM type                       | Rendered as                                                 |
| ---------------------------------- | ----------------------------------------------------------- |
| `varchar`, `email`, `phone`, `url` | `<input>`                                                   |
| `int`, `float`, `currency`         | `<input type="number">`                                     |
| `date`, `datetime`                 | `<input type="date/datetime-local">`                        |
| `bool`                             | `<input type="checkbox">`                                   |
| `text`                             | `<textarea>`                                                |
| `enum`                             | `<select>`                                                  |
| `multiEnum`                        | Grouped checkboxes                                          |
| `urlMultiple`                      | Single URL `<input>` (first value)                          |
| `address`                          | Five sub-fields (street, city, state, postal code, country) |
| `attachmentMultiple`               | `<input type="file">`                                       |

Types such as `link`, `linkMultiple`, `image`, and `wysiwyg` are silently skipped.

---

## Module structure

```
src/files/custom/Espo/Modules/ContactPortal/
├── Actions/
│   ├── HandleRequest.php     # Generates + emails magic-link token
│   ├── HandleSave.php        # Saves edits from magic-link form
│   └── HandleRegister.php    # Creates Contact from registration form
├── EntryPoints/
│   ├── ContactPortalRequest.php   # Renders email-input page
│   ├── ContactPortalEdit.php      # Renders pre-filled edit form
│   └── ContactPortalRegister.php  # Renders blank registration form
├── Util/
│   ├── ContactFieldProvider.php   # Reads config; returns typed field arrays
│   ├── AttachmentSaver.php        # File upload validation and persistence
│   └── HtmlRenderer.php           # Wraps content in branded HTML page
└── Resources/
    ├── module.json
    ├── routes.json                 # POST route declarations
    └── metadata/
        ├── app/client.json         # Entry point registration
        ├── contactPortal/
        │   └── Contact.json        # Field config (editFormFields / registrationFields)
        └── entityDefs/
            └── Contact.json        # portalToken / portalTokenExpiry field definitions
```

---

## Building an installable package

```bash
npm run extension
```

The `.zip` package is written to `build/`. The version number is read from `package.json`.

To bump the version:

```bash
npm version patch   # 1.0.0 → 1.0.1
npm version minor   # 1.0.0 → 1.1.0
npm version major   # 1.0.0 → 2.0.0
```

---

## Tests

### Unit tests

```bash
composer install        # install dev dependencies once
vendor/bin/phpunit tests/unit/Espo/Modules/ContactPortal
# or
npm run unit-tests
```

### Static analysis

```bash
vendor/bin/phpstan
# or
npm run sa
```

PHPStan is configured in `phpstan.neon` and scans `src/` and `site/`.

    You need to create a config file `tests/integration/config.php`:

    ```php
    <?php

    return [
        'database' => [
            'driver' => 'pdo_mysql',
            'host' => 'localhost',
            'charset' => 'utf8mb4',
            'dbname' => 'TEST_DB_NAME',
            'user' => 'YOUR_DB_USER',
            'password' => 'YOUR_DB_PASSWORD',
        ],
    ];
    ```

Command to run integration tests:

```
(npm run sync; cd site; vendor/bin/phpunit tests/integration/Espo/Modules/ContactPortal)
```

or:

```
npm run integration-tests
```

Note that integration tests needs the full Espo installation.

Integration tests should be placed in `tests/integration/Espo/Modules/ContactPortal` directory
and be in `tests\integration\Espo\Modules\ContactPortal` namespace.

### GitHub workflow

A workflow running unit tests and static analysis is defined in `.github/workflows/test.yml.disabled`.
Remove `.disabled` from the filename to activate the workflow.

## Configuring IDE

You need to set the following paths to be ignored in your IDE:

- `build`
- `site/build`
- `site/custom/`
- `site/client/custom/`
- `site/tests/unit/Espo/Modules/ContactPortal`
- `site/tests/integration/Espo/Modules/ContactPortal`

### File watcher

Note: The File Watcher configuration for PhpStorm is included in this repository (no need to configure).

You can set up a file watcher in the IDE to automatically copy and transpile files upon saving.

File watcher parameters for PhpStorm:

- Program: `node`
- Arguments: `build --copy-file --file=$FilePathRelativeToProjectRoot$`
- Working Directory: `$ProjectFileDir$`

## Using ES modules

The initialization script asks whether you want to use ES6 modules. It's recommended to choose "YES".

If you have chosen No and want to switch to ES6 later, then:

1. Set _bundled_ to true in `extension.json`.
2. Set _bundled_ and _jsTranspiled_ to true in `src/files/custom/Espo/Modules/ContactPortal/Resources/module.json`.
3. Add `src/files/custom/Espo/Modules/ContactPortal/Resources/metadata/app/client.json`
    ```json
    {
        "scriptList": [
            "__APPEND__",
            "client/custom/modules/contact-portal/lib/init.js"
        ]
    }
    ```

## Javascript frontend libraries

Install _rollup_.

In `extension.json`, add a command that will bundle the needed library into an AMD module. Example:

```json
{
    "scripts": [
        "npx rollup node_modules/some-lib/build/esm/index.mjs --format amd --file build/assets/lib/some-lib.js --amd.id some-lib"
    ]
}
```

Add the library module path to `src/files/custom/Espo/Modules/ContactPortal/Resources/metadata/app/jsLibs.json`

```json
{
    "some-lib": {
        "path": "client/custom/modules/contact-portal/lib/some-lib.js"
    }
}
```

When you build, the library module will be automatically included in the needed location.

Note that you may also need to create _rollup.config.js_ to set some additional Rollup parameters that are not supported via CLI usage.

## Updating tooling libraries

Update the version number of espo-extension-tools in package.json to the [latest one](https://github.com/espocrm/extension-tools/releases).

Run:

```
npm update espo-extension-tools
npm update espo-frontend-build-tools
```

Or just update everything:

```
npm update
```

## License

(change this section after initialization)

Change the license in `LICENSE` file. The current license is intended for scripts of this repository. It's not supposed to be used for code of your extension.
